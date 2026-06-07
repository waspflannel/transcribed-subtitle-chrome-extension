# Plan: Voice Isolation Scribe Audio Quality

Status: active
Owner: agent
Created: 2026-06-06
Last updated: 2026-06-06

## Goal

Improve transcription input quality by adding an internal audio-preparation stage before ElevenLabs Scribe v2. The intended pipeline is:

```text
YouTube audio -> ElevenLabs Voice Isolation -> 16 kHz mono WAV -> Scribe v2
```

Diarization is intentionally deferred. The implementation should keep the existing Scribe request behavior with `diarize=false` while producing cleaner, predictable audio for transcription.

This is a backend-only quality pipeline change. It should not alter the extension API contract, user-facing request shape, subtitle response schema, billing product interface, or cue enrichment behavior.

## Scope

- In scope:
  - Add an internal Scribe audio preparation service.
  - Call ElevenLabs Audio Isolation before transcription when enabled.
  - Convert the Scribe input to 16 kHz mono PCM WAV.
  - Keep Scribe v2 as the transcription model.
  - Keep `diarize=false`.
  - Preserve word timestamps.
  - Add config for enabling/disabling voice isolation and for FFmpeg/runtime timeouts.
  - Add fallback behavior so transcription can continue from normalized source audio if isolation fails.
  - Add focused unit/feature tests for request shape, conversion flow, fallback, cleanup, and secret-safe logging.

- Out of scope:
  - Speaker diarization behavior, speaker labels, and speaker-aware cue splitting.
  - Demucs, DeepFilterNet, WhisperX, VAD chunking, OpenAI transcription replacement, or hybrid alignment.
  - Public API/interface/type changes.
  - New frontend controls.
  - New billing tiers or user-visible quality modes.
  - Aggressive denoise, compression, EQ, or loudness changes beyond format normalization unless later testing proves they help.

## Acceptance Criteria

- [ ] Transcription uses a prepared audio file when voice isolation is enabled.
- [ ] Prepared Scribe upload is a 16 kHz mono WAV with MIME type `audio/wav`.
- [ ] ElevenLabs Audio Isolation is called with multipart field `audio`.
- [ ] Audio Isolation uses `file_format=pcm_s16le_16` after local FFmpeg conversion to 16-bit PCM, 16 kHz, mono, little-endian raw audio.
- [ ] Scribe request still sends `model_id=scribe_v2`, `timestamps_granularity=word`, `diarize=false`, `tag_audio_events=false`, and `no_verbatim=false`.
- [ ] Source language behavior is unchanged: omit `language_code` for `auto`; pass configured catalog language code otherwise.
- [ ] If voice isolation fails, the system falls back to normalized 16 kHz mono WAV from the original source audio and still attempts Scribe.
- [ ] Temporary raw PCM, isolated audio, and prepared WAV files are deleted with the acquired YouTube audio directory.
- [ ] Provider API keys, raw audio paths, and raw provider payloads are not written to user-facing artifacts or logs.
- [ ] Existing subtitle generation tests still pass.

## Relevant Context

- Current Scribe integration: `app/backend/app/Services/Transcription/ElevenLabsScribeTranscriptionService.php`
- Current downloaded audio handle: `app/backend/app/Services/Audio/TemporaryAudioFile.php`
- Current YouTube audio acquisition: `app/backend/app/Services/Audio/YouTubeAudioSource.php`
- Existing Scribe tests: `app/backend/tests/Unit/ElevenLabsScribeTranscriptionServiceTest.php`
- Config files likely touched:
  - `app/backend/config/ai.php`
  - `app/backend/config/subtitles.php`
  - `app/backend/.env.example`
- Quality rules: `docs/quality/golden-principles.md`
- Related findings memo: `docs/experiments/transcription-quality/manual-findings-and-combinations.md`
- Known current-state note: an interrupted implementation created an empty skeleton at `app/backend/app/Services/Audio/ElevenLabsScribeAudioPreparer.php`. The implementation pass should either complete this class or delete/recreate it intentionally.

## External References

- ElevenLabs Audio Isolation: `https://elevenlabs.io/docs/api-reference/audio-isolation/convert`
- ElevenLabs Speech to Text: `https://elevenlabs.io/docs/api-reference/speech-to-text/convert`
- FFmpeg full documentation: `https://ffmpeg.org/ffmpeg-all.html`

## API Findings

### ElevenLabs Audio Isolation

Current REST shape:

```text
POST /v1/audio-isolation
Header: xi-api-key
Body: multipart/form-data
  audio: file, required
  file_format: optional string
  preview_b64: optional string
```

Use `file_format=pcm_s16le_16` for the isolation input. ElevenLabs documents this as 16-bit PCM, 16 kHz sample rate, mono, little-endian. This should reduce latency compared with passing an encoded waveform.

Implementation caveat: the REST reference currently shows a thin success response shape, while SDK-oriented examples describe processed audio bytes/stream behavior. Before production rollout, run a sandbox call and record:

- HTTP status.
- `Content-Type`.
- Whether the body is direct audio bytes, JSON metadata, or another transport shape.
- Whether the endpoint requires a different SDK parameter name when using an official SDK.

Do not assume the final response parser until this is verified against a real successful request.

### ElevenLabs Scribe v2

Current Scribe request should remain conceptually unchanged:

```text
POST /v1/speech-to-text
Header: xi-api-key
Body: multipart/form-data
  file: prepared WAV
  model_id: scribe_v2
  timestamps_granularity: word
  diarize: false
  tag_audio_events: false
  no_verbatim: false
  language_code: only when source language is not auto
```

The existing service already sends `diarize=false`, so the implementation should preserve that explicit setting.

## Proposed Architecture

Add a dedicated preparer between downloaded YouTube audio and Scribe upload:

```text
SubtitleGenerationPipeline
  -> YouTubeAudioSource::acquire()
  -> ElevenLabsScribeTranscriptionService::transcribe()
       -> ElevenLabsScribeAudioPreparer::prepare()
            -> FFmpeg raw PCM conversion for Audio Isolation
            -> ElevenLabs Audio Isolation request
            -> FFmpeg isolated output conversion to WAV
            -> fallback normalized WAV if isolation fails
       -> Scribe upload of prepared TemporaryAudioFile
```

The preparer should return a `TemporaryAudioFile` or a small value object with equivalent fields:

```text
path
directory
durationSeconds
sizeBytes
mimeType = audio/wav
```

Prefer reusing the original audio work directory so cleanup remains simple. All derived files should live under the existing private audio-processing temp directory.

## Detailed Pipeline

### Step 1: Acquire YouTube Audio

Keep current behavior:

```text
yt-dlp -> TemporaryAudioFile
```

No change to video duration checks, public-video checks, download format selection, or billing reservation sync.

### Step 2: Convert Source Audio To Raw PCM For Isolation

Run FFmpeg inside the same private work directory:

```powershell
ffmpeg -hide_banner -nostdin -y `
  -i input `
  -vn `
  -ac 1 `
  -ar 16000 `
  -c:a pcm_s16le `
  -f s16le `
  isolation-input.pcm
```

Purpose:

- Strip video streams with `-vn`.
- Downmix to mono with `-ac 1`.
- Resample to 16 kHz with `-ar 16000`.
- Emit 16-bit little-endian PCM with `pcm_s16le`.
- Use raw `s16le` container because ElevenLabs' `pcm_s16le_16` mode describes raw PCM constraints, not a WAV container.

Avoid extra filters in this step. Do not apply high-pass, denoise, speech normalization, or loudness normalization until measured later.

### Step 3: Call ElevenLabs Audio Isolation

Send:

```text
audio = isolation-input.pcm
file_format = pcm_s16le_16
```

Recommended timeout:

```text
ELEVENLABS_AUDIO_ISOLATION_TIMEOUT_SECONDS=600
```

Store the response body in the same private work directory as:

```text
isolated-output.bin
```

Name the file generically until the real response content type is verified. After verification, use the extension implied by the content type if reliable.

### Step 4: Convert Isolated Output To 16 kHz Mono WAV

Run FFmpeg:

```powershell
ffmpeg -hide_banner -nostdin -y `
  -i isolated-output.bin `
  -vn `
  -ac 1 `
  -ar 16000 `
  -c:a pcm_s16le `
  scribe-ready.wav
```

If ElevenLabs returns raw PCM instead of a containerized audio format, use an input format declaration:

```powershell
ffmpeg -hide_banner -nostdin -y `
  -f s16le `
  -ar 16000 `
  -ac 1 `
  -i isolated-output.pcm `
  -c:a pcm_s16le `
  scribe-ready.wav
```

The implementation should choose the correct command only after the sandbox response check confirms response format.

### Step 5: Fallback If Isolation Fails

If Audio Isolation fails, times out, returns empty output, or returns a response FFmpeg cannot decode:

```text
source audio -> FFmpeg 16 kHz mono WAV -> Scribe
```

Fallback FFmpeg command:

```powershell
ffmpeg -hide_banner -nostdin -y `
  -i input `
  -vn `
  -ac 1 `
  -ar 16000 `
  -c:a pcm_s16le `
  scribe-ready.wav
```

Fallback should log structured context:

```text
stage=audio_isolation
provider=eleven
reason=http_failure|timeout|empty_output|decode_failure|ffmpeg_failure
status=<provider status if available>
```

Do not log API keys, raw response bodies, full audio paths, or transcript text.

### Step 6: Upload To Scribe

Upload `scribe-ready.wav` to Scribe with:

```text
filename = audio.wav
Content-Type = audio/wav
diarize = false
timestamps_granularity = word
```

The normalizer and downstream subtitle generation should not need to know whether voice isolation ran.

## Configuration Plan

Add config with conservative defaults:

```php
'audio_preparation' => [
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffmpeg_timeout_seconds' => (int) env('SUBTITLE_AUDIO_PREP_FFMPEG_TIMEOUT_SECONDS', 600),
    'voice_isolation' => [
        'enabled' => (bool) env('ELEVENLABS_AUDIO_ISOLATION_ENABLED', false),
        'timeout_seconds' => (int) env('ELEVENLABS_AUDIO_ISOLATION_TIMEOUT_SECONDS', 600),
        'fail_open' => (bool) env('ELEVENLABS_AUDIO_ISOLATION_FAIL_OPEN', true),
    ],
],
```

Recommended `.env.example` additions:

```text
FFMPEG_BINARY=ffmpeg
SUBTITLE_AUDIO_PREP_FFMPEG_TIMEOUT_SECONDS=600
ELEVENLABS_AUDIO_ISOLATION_ENABLED=false
ELEVENLABS_AUDIO_ISOLATION_TIMEOUT_SECONDS=600
ELEVENLABS_AUDIO_ISOLATION_FAIL_OPEN=true
```

Default `ELEVENLABS_AUDIO_ISOLATION_ENABLED=false` keeps rollout safe. Turn it on in staging after sandbox verification.

## Error Handling

Classify failures as transcription-stage failures unless they happen before Scribe and fallback succeeds.

Suggested behavior:

- FFmpeg source-to-WAV fallback fails: fail transcription with stable `transcription_failed`.
- Audio Isolation HTTP failure and fail-open enabled: log, fallback to normalized source WAV, continue.
- Audio Isolation HTTP failure and fail-open disabled: fail transcription.
- Empty isolation response: log, fallback if fail-open enabled.
- Isolated output cannot be converted to WAV: log, fallback if fail-open enabled.
- Scribe HTTP failure: preserve current transcription failure behavior.

## Observability

Add structured logs or trace context for:

- Audio preparation started.
- FFmpeg source normalization completed.
- Audio Isolation request completed.
- Audio Isolation fallback used.
- Prepared WAV ready.

Suggested fields:

```text
input_mime_type
source_duration_seconds
prepared_audio_bytes
voice_isolation_enabled
voice_isolation_used
voice_isolation_fallback_used
ffmpeg_elapsed_ms
voice_isolation_elapsed_ms
```

Avoid:

- Full local file paths.
- API keys.
- Provider response bodies.
- Raw transcript text.
- Audio bytes.

## Cost And Runtime Notes

Voice Isolation adds a second ElevenLabs provider call before Scribe. Keep it disabled by default until staging evidence justifies enabling it.

Approximate file-size concern:

```text
16,000 samples/sec * 2 bytes/sample * 1 channel = 32,000 bytes/sec
60 minutes ~= 115 MB raw PCM
```

Do not keep multiple long raw audio copies in memory. Use files/streams where Laravel's HTTP client allows it, and delete temporary files at the end of the job.

## Implementation Steps

- [ ] Inspect current Scribe request tests and update expected request shape only where the prepared WAV changes upload filename/content.
- [ ] Verify real Audio Isolation response body/content type with one sandbox request outside production flow.
- [ ] Decide response parser from sandbox evidence: direct audio stream, raw PCM, JSON metadata, or SDK stream.
- [ ] Complete or replace `App\Services\Audio\ElevenLabsScribeAudioPreparer`.
- [ ] Add FFmpeg process runner with explicit timeout, private temp directory, and safe environment handling.
- [ ] Add source-to-PCM conversion for Audio Isolation.
- [ ] Add Audio Isolation HTTP call.
- [ ] Add isolated-output-to-WAV conversion.
- [ ] Add source-to-WAV fallback conversion.
- [ ] Route `ElevenLabsScribeTranscriptionService` through the preparer before uploading to Scribe.
- [ ] Keep `diarize=false` explicit in the Scribe payload.
- [ ] Ensure prepared audio cleanup happens with the existing `TemporaryAudioFile::delete()` lifecycle.
- [ ] Add config and `.env.example` entries.
- [ ] Add tests.
- [ ] Run formatting and validation.
- [ ] Record staging evidence before enabling in production.

## Validation Plan

Commands:

```powershell
cd app/backend
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Unit/ElevenLabsScribeTranscriptionServiceTest.php
php artisan test --compact --filter=ElevenLabsScribeAudioPreparerTest
```

Before final merge, run:

```powershell
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Evidence to capture:

- Unit tests:
  - prepared WAV is uploaded to Scribe.
  - Scribe payload keeps `diarize=false`.
  - Audio Isolation request uses `audio` and `file_format=pcm_s16le_16`.
  - fallback path runs when isolation fails.
  - unsupported source MIME behavior remains stable.
  - temp files are removed.
- Manual/staging:
  - one clean YouTube clip.
  - one noisy/ambient YouTube clip.
  - one music-heavy YouTube clip.
  - compare transcript text and cue quality before/after.
  - record provider cost and elapsed time.
- Logs:
  - no API keys.
  - no full audio file paths.
  - no raw transcript text.

## Rollout Plan

1. Keep `ELEVENLABS_AUDIO_ISOLATION_ENABLED=false` after merge.
2. Verify the Audio Isolation response format with one controlled staging request.
3. Enable in staging for a small set of manual jobs.
4. Compare results against the existing raw Scribe path.
5. If quality is neutral or better and runtime is acceptable, enable for internal/beta usage.
6. If regressions appear, keep the preparer but leave Voice Isolation disabled and use only normalized WAV.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-06 | Implement Voice Isolation before Scribe, but defer diarization. | The selected near-term product decision is cleaner speech input without speaker-label behavior changes. |
| 2026-06-06 | Keep `diarize=false` explicit. | Current subtitle flow is not yet designed around speaker labels or speaker-aware cue splitting. |
| 2026-06-06 | Use 16 kHz mono PCM/WAV as the normalized Scribe format. | This matches speech-transcription-friendly input and keeps the provider upload predictable. |
| 2026-06-06 | Use `pcm_s16le_16` for Audio Isolation input after local FFmpeg conversion. | ElevenLabs documents this as the lower-latency input path for Audio Isolation. |
| 2026-06-06 | Default Voice Isolation off until staging verification. | The previous quality run did not prove isolation as a safe global default, and the REST response format needs one real-call verification. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-06 | Plan created. | `docs/exec-plans/active/00-learning-upgrade/05-voice-isolation-scribe-audio-quality.md` |

## Completion Notes

- What changed:
  - Not started.
- Validation results:
  - Not started.
- Simplicity/readability review:
  - Keep the implementation as a small preparer service plus a narrow Scribe-service integration point.
- Residual risk:
  - Audio Isolation response format must be verified against a successful real request before production wiring is considered complete.
  - Voice Isolation may still regress some clean or music-heavy clips; rollout should be gated.
- Follow-up debt:
  - Diarization design remains deferred.
  - Music-specific Demucs path remains deferred.
  - DeepFilterNet and VAD chunking remain deferred.
