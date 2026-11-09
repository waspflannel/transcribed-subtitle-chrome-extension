# Plan: Review PR 5 captions and translation context


## Creator captions and transcript context

`SUBTITLE_CREATOR_CAPTIONS=true` prefers manually uploaded source-language captions. New jobs pin this preference and record `transcript_source` as `scribe` or `creator_captions`. Job processing version v20 distinguishes caption preference in reuse keys. Existing jobs migrate with captions disabled. The transcript cache remains raw Scribe text/timings, so its version v9 stays unchanged; caption selection is applied again for each new generation. Learning-card cache v11 also stays unchanged.

The backend fetches only manual subtitles using yt-dlp `--write-subs --no-write-auto-subs`. Caption text is parsed from VTT and aligned through the existing lyrics alignment agent onto Scribe-derived timing slots. The alignment uses the saved generation model and consumes an additional provider call when manual captions exist. Download, language mismatch, oversized files/text and alignment failures fall back to Scribe. Temporary caption files are deleted. Raw caption text and prompts are not logged.

Manual-caption jobs wait for full-transcript alignment before publishing drafts so an early cue cannot later change identity or text. Without matching manual captions, progressive drafts remain enabled. If automatic source selection has no metadata language, manual-caption selection waits for Scribe's detected language. Missing/failed caption retrieval is pinned for that run. Successful and fallback final tracks remain distinguishable on the job. Disable the flag to use Scribe-only generation; restart workers after config changes.

`SUBTITLE_FULL_TRANSCRIPT_CONTEXT=true` prepends full merged transcript text to analysis input. `SUBTITLE_FULL_TRANSCRIPT_MAX_CHARACTERS=60000` bounds that stable leading section by Unicode characters; zero disables it. Batches started before merge keep their existing nearby-cue context. Full text is untrusted prompt data. Caption-derived jobs use caption text for this section. Stable leading text allows provider prompt caching where supported. No new wire fields or API endpoints are added.

After deployment run migrations, clear/rebuild Laravel config and restart workers. Back up before the preceding deletion migration. Use `subtitles:eval` on saved generations to compare these settings; no paid comparisons were run during implementation.

Applied Laravel best practices, subtitle pipeline and AI SDK guidance. Reuse the lyrics parts/allocation validator and timing slots. Keep raw transcript caching independent of caption correction. Pin preference on job and distinguish it in processing versions. Test with fake processes/agents only. Full harness required before push.

Complete. Full harness passed: 711 backend tests and 428 extension tests. Release ZIP verification and npm audit passed. Composer runtime audit passed. No paid calls or real YouTube generation runs.
