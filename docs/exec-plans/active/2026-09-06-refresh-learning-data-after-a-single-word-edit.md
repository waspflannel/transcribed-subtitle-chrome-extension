# Refresh learning data after a single word edit

Status: active
Owner: agent
Created: 2026-09-06
Last updated: 2026-09-06

## Goal

A Quick fix updates the word and its learning data together, rather than publishing text with missing pronunciation and meanings.

## Scope

One backend AI request refreshes the edited cue. Preserve token boundaries, timing, other cues, video-minute balance and expiry. Keep the existing PATCH endpoint and canonical track response. No full-replacement redesign or billing repair.

## Acceptance Criteria

- [x] Refresh word translation, gloss and cards, plus enabled cue translation and cue/token romanization.
- [x] Keep multiword replacements as one token and preserve surrounding text and timing.
- [x] Reject incomplete or rewritten AI output; preserve the old track on failure.
- [x] Recheck entitlement, track/run identity and active replacement before publication.
- [x] Show pending Save feedback and allow enough time for the AI request.
- [x] Run repository checks and record remaining baseline failures.

## Relevant Context

- Product: `docs/product-specs/lyrics-editing.md`
- Architecture: `ARCHITECTURE.md`
- Quality: `docs/quality/golden-principles.md`
- Skills: ponytail, subtitle-pipeline, laravel-best-practices, ai-sdk-development.
- Context7 Laravel 13 docs checked for transaction locking and structured-output agent testing. Installed SDK fake callbacks receive prompt strings.

## Decisions

- User request supersedes the former provider-free rule and historic builder-only restrictions.
- Reuse the enrichment schema and strict provider validators in a specialized one-cue agent; one request refreshes all contextual card data for that cue.
- Honor the job's translation and romanization options. Refresh cards even in on-demand mode.
- Keep provider work outside database locks; publish only after a second locked state check.
- Backend timeout 45 seconds, extension timeout 60 seconds. This bounded single-cue request needs no new queue state or table.

## Progress and Validation

- Focused HTTP tests: 13 passed, 91 assertions, including unchanged track on provider failure, stale response rejection, entitlement, phrase preservation and canonical contract responses.
- Provider tests: 11 passed, 22 assertions, including Japanese readings, settings, same-language output and malformed output rejection.
- Corrected the existing contract fixture's invalid 13-character video ID while testing the modified endpoint.
- Corrected the existing extension test helper's missing stage override type so compile can run.
- Final focused backend/provider/contract checks: 26 passed, 121 assertions, adding publication-time entitlement/replacement checks and preservation of concurrent word-card updates to other cues.
- Full `check.ps1`: docs and contracts passed; backend 398 passed / 2 failed (2,793 assertions). The two pre-existing full-replacement cancellation failures remain at `LyricsCorrectionContinuationTest.php:457` and `:475`.
- Full extension tests: 167 passed. After adding the pending-editor regression, all 39 focused API/transcript tests passed; TypeScript and build passed again.
- Live provider check: Japanese-to-English edited cue completed in 7.53 seconds with translation, cue/token romanization and token translation/gloss; words and boundaries preserved. No user track was mutated for this smoke check.
- Self-review: no AI call under a database lock; original data survives failures; second locked check prevents stale publication; latest other-cue updates are merged; no new dependencies or queue workflow.
- An unrelated user edit to `CreateNewUser.php` appeared during work and was preserved.

## Completion Notes

Implementation and scoped validation complete. Full-replacement cancellation failures are pre-existing and remain for follow-up. Native extension browser visual QA was not run; DOM regression tests, live provider smoke, compile and production build passed. No production deployment or branch merge is part of this work.
