# Plan: Integrate reversible audio acquisition

Status: implemented; awaiting user acceptance before merge
Owner: agent
Created: 2026-09-12
Last updated: 2026-09-13

## Goal and scope

Integrate the acquisition benchmark winner into normal generation: fetch metadata while the extension panel is open, then copy the complete selected M4A directly with FFmpeg. Preserve existing chunking, transcription, analysis and billing. Opening-first acquisition is excluded because its additional complexity saved only about 0.2 seconds in the acquisition-only experiment.

## Decisions

- Both backend flags and the extension build flag default off. Enable locally for user testing; do not merge until the user approves.
- The authenticated write-scoped endpoint accepts only an 11-character video ID. It queues a unique, single-attempt job on the existing base generation queue and returns immediately. Jobs waiting 15 seconds become no-ops. This avoids blocking the local Windows HTTP server.
- Metadata resolution has an eight-second timeout and a 15-second cache lock. Store encrypted metadata for 60 seconds, scoped to account and video. Recheck public availability, live status and duration before use.
- The extension prefetches only with an open panel, a signed-in account and a supported video that is not already loading/ready. It does not await the result. A 45-second client dedupe and 60-second server TTL are best effort; idle/refresh timing can leave a cold-cache gap.
- Direct acquisition accepts HTTPS Googlevideo hosts, verifies TLS and copies M4A without re-encoding. WebM and unsupported formats use the existing downloader. Direct failure falls back; failed cached acquisition discards metadata and resolves it fresh once.
- Metadata prefetch makes no provider calls, creates no subtitle generation and reserves no credits. Queue payloads contain account/video IDs and a timestamp, not signed URLs.
- Log cache-hit and direct-download success/duration only. Download failure context excludes commands, signed URLs and process output.

## Acceptance and validation

- [x] API, service, queue and extension integration implemented behind flags.
- [x] Test encrypted/account-scoped/expired/corrupt cache, authorization, ID validation, asynchronous dispatch, fallback cleanup, invalid hosts and failed cached-media refresh.
- [x] Test panel lifecycle, deduplication and nonblocking prefetch.
- [x] Real service smoke: Spanish video, metadata 2.714 seconds before Generate, direct full M4A acquisition 1.030 seconds, 3,546,900 bytes.
- [x] Real complete generation smoke for Spanish and Japanese, with transcript cache disabled and isolated database/Redis prefixes.
- [x] Final `scripts/agent/check.ps1`, local runtime restart and enabled extension build.
- [ ] User subtitle-quality and playback acceptance; merge requires explicit approval.

Initial full validation passed: 545 backend tests and 256 extension tests, contracts, type checking and build. Queue review then added two backend tests; the targeted eight-test suite passes. Final evidence is recorded below after completion.

## Review and rollback

Independent Laravel rules review found synchronous prefetch could block local HTTP requests; replaced it with the existing generation queue. Review confirmed signed-download failure sanitization and account-scoped cache checks. A near-expiry refresh gap is accepted for this reversible experiment; ordinary generation falls back immediately on a cache miss.

Disable `SUBTITLE_YOUTUBE_DIRECT_DOWNLOAD` and `SUBTITLE_YOUTUBE_METADATA_PREFETCH`, clear backend config and restart drained workers. Disable `WXT_AUDIO_METADATA_PREFETCH`, rebuild and reload the extension to stop speculative requests. No migration or user-data reset is needed. Existing metadata expires within 60 seconds.

Related evidence: [acquisition-only benchmark](../evidence/2026-09-13-audio-acquisition-experiments.md). Its 89% acquisition reduction is not an end-to-end subtitle speedup claim.

## Final evidence (September 13)

Full harness passed: 547 backend tests (4,349 assertions), 256 extension tests, contracts, type checking and production build. The extension was then rebuilt with prefetch enabled. Both backend flags are enabled in the ignored local environment; config was cleared and local workers restarted after confirming zero active public jobs. Strict runtime check and HTTP health check passed. User must reload the unpacked extension before testing.

Two fresh complete generations used isolated schema `latency_bench_20260913c` and Redis/cache prefixes, unique fixture accounts, normal saved provider/model settings and transcript-cache TTL zero. Metadata was prepared by the new queued job before starting generation. Both logged metadata hits and successful direct acquisition. Existing public jobs and transcript-cache rows matched the before snapshot afterward.

| Baseline case | Language | Direct audio copy | First source cues | First analyzed cues | Complete |
| --- | --- | ---: | ---: | ---: | ---: |
| 152 | Spanish | 0.427 s | 5.699 s | 8.338 s | 19.001 s |
| 155 | Japanese | 0.614 s | 4.891 s | 11.563 s | 16.906 s |

These two samples verify integration; they do not establish a percentage speedup. Metadata work happened before Generate. Raw local evidence is in ignored `app/backend/storage/app/latency-benchmark-integration/` and check logs in `app/backend/storage/logs/`. Subtitle quality and actual browser playback acceptance remain with the user. No merge performed.
