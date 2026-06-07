# Transcription Quality Findings And Manual Combination Ideas

Run ID: `quality-run-20260606-001`

This memo summarizes the previous transcription-quality run in a form that is easier to use for manual follow-up testing. The generated report remains the artifact of record at `docs/experiments/transcription-quality/report.md`.

## What Actually Ran

The run used eight Arabic-heavy YouTube windows with manual Arabic subtitle tracks imported as reference text. It was a real provider run, not a fake simulation. Total estimated provider spend was `$2.3563` against the `$10.00` cap.

Completed across all eight windows:

- `raw_scribe`
- `scribe_keyterms`
- `scribe_diarization`
- `ffmpeg_voice_norm`
- `ffmpeg_denoise_norm`
- `elevenlabs_isolation_scribe`
- `vad_chunked_scribe`
- `openai_text_compare`
- `ffmpeg_voice_norm_plus_keyterms`
- `ffmpeg_denoise_norm_plus_keyterms`

Completed on gated multi-speaker clips:

- `best_preprocess_plus_diarization`

Not run because local tools were unavailable:

- `deepfilternet_scribe`
- `demucs_scribe`
- `whisperx_align_probe`
- DeepFilterNet and Demucs combinations

Skipped by gate:

- `elevenlabs_isolation_plus_keyterms`
- `best_text_candidate_plus_scribe_timing_alignment_probe`

## Main Findings

Baseline `raw_scribe` median:

- WER: `0.2897`
- CER: `0.1379`

Best result:

- `best_preprocess_plus_diarization`
- Finding: `keep`, but only on the two configured multi-speaker clips.
- Median relative improvement: `5.4%`
- Median WER: `0.2298`
- Median CER: `0.1155`
- Interpretation: diarization is worth retesting when paired with the best available preprocessing path for multi-speaker clips.

Promising but not enough to keep globally:

- `ffmpeg_denoise_norm`
  - Median relative improvement: `1.6%`
  - Helped several clips, especially `daily_routine_beginner`, `describing_things_podcast`, `easy_arabic_podcast`, and `kid_dialogue`.
  - Regressed badly on `family_beginner_dialogue`, where baseline was already near perfect.

- `ffmpeg_denoise_norm_plus_keyterms`
  - Median relative improvement: `1.5%`
  - Helped `kid_dialogue` by `6.8%`, `describing_things_podcast` by `4.3%`, `easy_arabic_podcast` by `3.5%`, and `clean_beginner_dialogue` by `2.5%`.
  - Also regressed badly on `family_beginner_dialogue`.

- `scribe_diarization`
  - No median gain by itself.
  - Helped `daily_routine_beginner` by `3.9%` and `music_heavy_mawlaya` by `1.8%`, but hurt `kid_dialogue` by `3.9%`.
  - Looks more useful as part of a gated combo than as a global toggle.

Dropped:

- `scribe_keyterms`
  - No median gain and added cost.
  - Regressed `family_beginner_dialogue` and `kid_dialogue`.

- `ffmpeg_voice_norm`
  - No median gain globally.
  - Helped `kid_dialogue` by `10.2%`, `daily_routine_beginner` by `6.0%`, and `music_heavy_mawlaya` by `1.8%`.
  - Hurt `walking_podcast` by `6.6%`.

- `ffmpeg_voice_norm_plus_keyterms`
  - No median gain globally.
  - Helped `kid_dialogue` by `7.3%`, `daily_routine_beginner` by `6.0%`, and `easy_arabic_podcast` by `2.5%`.
  - Regressed `family_beginner_dialogue`.

- `elevenlabs_isolation_scribe`
  - Tiny median improvement, `0.2%`, at much higher cost.
  - Helped `kid_dialogue` by `5.5%` and `easy_arabic_podcast` by `3.0%`.
  - Hurt `walking_podcast` by `6.6%` and the music-heavy clip by `3.7%`.

- `vad_chunked_scribe`
  - Median regression: `3.7%`.
  - One notable exception: `kid_dialogue` improved by `10.2%`.
  - Current chunking is too crude for global use.

- `openai_text_compare`
  - Median regression: `8.4%`.
  - Do not replace Scribe text with OpenAI text based on this run.

Infeasible in this environment:

- DeepFilterNet
- Demucs
- WhisperX

## Caveats

- The reference text came from manual YouTube Arabic subtitle tracks, not a fresh human transcription pass. These references should be spot-checked before product decisions.
- The final clip set prioritized manual Arabic subtitle availability. It is less noisy/street-interview-heavy than the original research target.
- DeepFilterNet, Demucs, and WhisperX were not installed, so the strongest local preprocessing ideas remain untested.
- Separate worktree threads were not used because the runnable provider matrix was small and the heavy local-model families were preflight-infeasible.

## Research Ideas To Carry Forward

This section captures the broader research direction that led to the experiment. Some ideas were tested in `quality-run-20260606-001`; others remain untested and should be verified before production use.

### Add A Quality Benchmark Harness First

Build and keep a small test set of messy YouTube audio:

- Background music.
- Low-volume speech.
- Dialect or accent variation.
- Multiple speakers.
- Fast speech.
- Noisy outdoor audio.
- Clean studio audio.

Every new pipeline should run through the same benchmark and be scored by:

- WER/CER where reference text exists.
- Cue timing quality.
- Speaker/cue readability.
- Runtime.
- Provider cost.
- Failure rate.

The main goal is to avoid "improving" audio in a way that makes transcripts, timing, or cue readability worse.

### Use More ElevenLabs Scribe Features

Scribe v2 supports features we are not fully using in production today:

- Word timestamps.
- Diarization.
- Audio tagging.
- Language hints.
- Keyterms.
- `no_verbatim`.
- PCM 16k mono input mode.

High-value experiments:

- Pass keyterms from video title, channel, and description for names, places, technical terms, and song names.
- Try `diarize=true` for multi-speaker videos and split cues on speaker changes.
- Convert to `pcm_s16le_16` mono 16k before Scribe to test latency and quality.

Current run result:

- Keyterms alone did not help globally and should not become a default.
- Diarization was most promising when paired with the best preprocess path on multi-speaker clips.
- Mono 16k FFmpeg preprocessing was partially tested through the FFmpeg variants.

Sources to re-check before implementation:

- ElevenLabs speech-to-text docs.
- ElevenLabs Create transcript API.

### Try Light FFmpeg Preprocessing As A Cheap Variant

Create a normalized audio variant:

- Mono 16k PCM.
- Gentle high-pass filter.
- Loudness normalization or speech normalization.
- Mild FFT denoise only when noise is detected.

Relevant FFmpeg filters:

- `loudnorm`
- `speechnorm`
- `afftdn`
- `arnndn`
- `highpass`

Current run result:

- `ffmpeg_denoise_norm` was a reasonable cheap candidate but did not clear the global keep gate.
- `ffmpeg_voice_norm` helped some clips but hurt others, so it should be conditional.

Source to re-check before implementation:

- FFmpeg filter docs.

### Use Conditional Voice Isolation, Not Default Voice Isolation

Test provider or ML voice isolation only on noisy clips. Candidate tools:

- ElevenLabs Audio Isolation / Voice Isolator.
- DeepFilterNet.

Current run result:

- ElevenLabs Voice Isolation alone had only a `0.2%` median improvement and much higher cost.
- It should not become a default.
- DeepFilterNet was not installed, so it remains an untested candidate.

Expected production shape if this ever works:

- Put isolation behind a quality classifier, retry mode, or user-selected high-quality mode.
- Do not make it always-on without stronger evidence.

Sources to re-check before implementation:

- ElevenLabs Audio Isolation docs.
- ElevenLabs Voice Isolator guide.
- DeepFilterNet GitHub.
- DeepFilterNet paper.

### Test VAD-Based Chunking

Use voice activity detection to cut on speech boundaries instead of sending long continuous audio blindly.

Candidate approaches:

- WhisperX VAD plus cut/merge chunking and forced alignment.
- Silero VAD as a lightweight local option.
- Provider-side loudness normalization or VAD chunking where supported by the selected transcription model.

Current run result:

- The crude FFmpeg silence-split version regressed median quality by `3.7%`.
- It did help `kid_dialogue` by `10.2%`, which suggests chunking may still help some dialogue if implemented more carefully.

Required next version:

- Add overlap.
- Avoid cutting mid-utterance.
- Merge duplicated boundary words.
- Preserve absolute timing offsets.

Sources to re-check before implementation:

- WhisperX paper.
- WhisperX GitHub.
- Silero VAD GitHub.
- OpenAI speech-to-text docs.

### Explore Hybrid Transcription: Best Text From One Model, Best Timing From Another

A simple provider swap can lose subtitle-ready timing. A better experiment:

```text
Scribe word timings -> alternate model text for low-confidence spans -> alignment back to Scribe timings or WhisperX forced alignment
```

Current run result:

- OpenAI text comparison was worse than Scribe on this test set, with an `8.4%` median regression.
- Do not replace Scribe text based on the current evidence.

Still worth testing later:

- Only use alternate text for low-confidence spans.
- Keep Scribe timings unless a local aligner proves better timing.
- Use WhisperX or another forced aligner only for alignment, not necessarily text generation.

Source to re-check before implementation:

- OpenAI speech-to-text docs.

### Music-Heavy Videos: Try Demucs Only When Needed

For songs, lyric videos, or heavy background music, vocal separation may help more than general denoise.

Candidate approach:

```text
raw audio -> Demucs/HTDemucs vocal stem -> Scribe
```

Current run result:

- Demucs was unavailable locally, so this remains untested.
- ElevenLabs Voice Isolation alone hurt the music-heavy clip, so Demucs is still the stronger music-specific hypothesis.

Use only as:

- A music-heavy retry path.
- A manual high-quality mode.
- A class-specific pipeline after detection.

Sources to re-check before implementation:

- Demucs GitHub.
- HTDemucs paper.

### Recommended Experiment Matrix

Keep the internal matrix report-only until a candidate clearly wins:

- `raw_scribe`
- `ffmpeg_normalized_scribe`
- `deepfilternet_scribe`
- `elevenlabs_audio_isolation_scribe`
- `scribe_with_keyterms`
- `scribe_with_diarization`
- `openai_text_plus_scribe_timing` for low-confidence chunks

Score every candidate by:

- Accuracy.
- Subtitle timing.
- Latency.
- Cost.
- Failure rate.

If one path wins for a specific audio class, add it as an automatic retry or higher-quality mode instead of changing the default path globally.

## Manual Combination Ideas To Test

### 1. Adaptive FFmpeg Preprocess + Diarization

Hypothesis: multi-speaker clips benefit when diarization is paired with whichever FFmpeg preprocess scores best for that clip class.

Pipeline:

```text
raw audio -> ffmpeg_voice_norm or ffmpeg_denoise_norm -> Scribe with diarize=true
```

Why test:

- This was the only `keep` result.
- It improved the two multi-speaker clips by a median `5.4%`.

Manual test trigger:

- Multi-speaker dialogue.
- Child/adult conversations.
- Podcast/interview clips with clear speaker turns.

Do not make it global until it is tested on more multi-speaker clips.

### 2. FFmpeg Denoise + Keyterms, But Only When Baseline Is Not Already Excellent

Hypothesis: conservative denoise plus focused keyterms helps moderately difficult clips but can hurt already-clean clips.

Pipeline:

```text
raw audio -> highpass + conservative afftdn + loudnorm + speechnorm -> Scribe with keyterms
```

Why test:

- Improved `kid_dialogue` by `6.8%`.
- Improved `describing_things_podcast` by `4.3%`.
- Improved `easy_arabic_podcast` by `3.5%`.
- Improved `clean_beginner_dialogue` by `2.5%`.

Risk:

- Regressed `family_beginner_dialogue`, where baseline was already near perfect.

Manual test trigger:

- Moderate noise.
- Moderate missed words in baseline.
- Proper nouns or topic terms in title/channel metadata.

### 3. FFmpeg Denoise Alone As The Cheap Default Candidate

Hypothesis: denoise-only may be the safest low-cost candidate if keyterms are unstable.

Pipeline:

```text
raw audio -> highpass + conservative afftdn + loudnorm + speechnorm -> Scribe
```

Why test:

- Median improvement was only `1.6%`, but it helped several clips without adding provider feature cost.
- Best observed gain was `6.4%` on `daily_routine_beginner`.

Risk:

- It can harm already-clean clips.

Manual test trigger:

- Background hiss or room noise.
- Speech that sounds slightly buried but not music-heavy.

### 4. Voice Norm Only For Low-Volume Or Child/Dialogue Clips

Hypothesis: voice normalization helps when the problem is volume/speech dynamics rather than background noise.

Pipeline:

```text
raw audio -> highpass + loudnorm + speechnorm -> Scribe
```

Why test:

- Improved `kid_dialogue` by `10.2%`.
- Improved `daily_routine_beginner` by `6.0%`.
- Improved the music-heavy clip slightly, `1.8%`.

Risk:

- Hurt `walking_podcast` by `6.6%`.
- No median gain overall.

Manual test trigger:

- Quiet speakers.
- Uneven volume.
- Child/adult dialogue.

### 5. Smarter VAD Chunking + Overlap + Stitching

Hypothesis: chunking can help some dialogue, but the current silence split is too crude.

Pipeline:

```text
raw audio -> VAD chunks with overlap -> Scribe per chunk -> overlap-aware text/timing stitch
```

Why test:

- The crude version improved `kid_dialogue` by `10.2%`.

Required changes before retest:

- Add 300-500 ms chunk overlap.
- Avoid cutting inside short utterances.
- Merge repeated boundary words.
- Preserve absolute timing offsets.

Risk:

- Current version regressed median error by `3.7%`.
- It increases Scribe request count and cost.

### 6. Demucs Vocals + FFmpeg Denoise + Scribe

Hypothesis: Demucs may outperform Voice Isolation on music-heavy clips by separating vocals before transcription.

Pipeline:

```text
raw audio -> Demucs vocals stem -> highpass + loudnorm/speechnorm -> Scribe
```

Why test:

- Not run because Demucs was unavailable.
- Voice Isolation was not good enough on the music-heavy clip, so this remains the main untested music hypothesis.

Manual test trigger:

- Songs.
- Speech over music beds.
- Live performance clips.

Add keyterms only after the vocal stem alone improves text quality.

### 7. Demucs Vocals + ElevenLabs Voice Isolation + Scribe

Hypothesis: Voice Isolation may be more useful after Demucs has already removed most non-vocal content.

Pipeline:

```text
raw audio -> Demucs vocals stem -> ElevenLabs Voice Isolation -> Scribe
```

Why test:

- This was planned but infeasible because Demucs was unavailable.
- It is expensive, so test only on music-heavy clips.

Risk:

- Voice Isolation alone hurt the music-heavy clip in this run.
- This should be tested after `Demucs vocals -> Scribe`.

### 8. DeepFilterNet + Scribe

Hypothesis: DeepFilterNet may improve noisy speech without the heavier artifacts of FFmpeg `afftdn`.

Pipeline:

```text
raw audio -> DeepFilterNet -> Scribe
```

Why test:

- Not run because `deep-filter` was unavailable.
- It is a plausible middle ground between cheap FFmpeg denoise and expensive provider isolation.

Manual test trigger:

- Room noise.
- Fan/hiss.
- Outdoor ambience without music.

Avoid stacking DeepFilterNet and FFmpeg denoise at first. Test DeepFilterNet alone, then compare against `ffmpeg_denoise_norm`.

### 9. DeepFilterNet + Keyterms

Hypothesis: if DeepFilterNet improves raw transcription, keyterms may recover proper nouns/topic words on top.

Pipeline:

```text
raw audio -> DeepFilterNet -> Scribe with keyterms
```

Gate:

- Only test if `DeepFilterNet -> Scribe` beats baseline on at least half of the target clips.

Risk:

- Keyterms alone did not help globally.

### 10. Scribe Text + Local Alignment Only

Hypothesis: keep Scribe text, but use local alignment only if timing is the pain point.

Pipeline:

```text
Scribe text/cues -> WhisperX/local aligner probe -> compare timing/readability
```

Why test:

- OpenAI text was worse than Scribe, so text replacement is not justified.
- WhisperX was unavailable, so alignment quality was not measured.

Manual test trigger:

- Text is good, but cue boundaries/timing drift are bad.

Do not use this to replace Scribe text unless a later text-quality run proves another model is better.

## Suggested Manual Retest Order

1. Install and preflight Demucs, DeepFilterNet, and WhisperX in an isolated environment.
2. Retest `Demucs vocals -> Scribe` on `music_heavy_mawlaya` first.
3. Retest `DeepFilterNet -> Scribe` on the noisiest non-music clips.
4. Expand `best_preprocess_plus_diarization` to more multi-speaker clips.
5. Retest smarter VAD chunking only after adding overlap-aware stitching.
6. Only test expensive Voice Isolation combinations after a local preprocess shows a clear win.

## Current Best Bets

- Best practical bet now: adaptive FFmpeg preprocess plus Scribe diarization for multi-speaker clips.
- Best cheap global candidate to keep investigating: `ffmpeg_denoise_norm`.
- Best untested music candidate: Demucs vocal stem into Scribe.
- Best untested noisy-speech candidate: DeepFilterNet into Scribe.
- Do not prioritize: OpenAI text replacement, keyterms alone, current VAD chunking, or Voice Isolation alone.
