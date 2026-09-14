# Plan: Speed up lyrics replacement

Status: completed — user accepted integration into main on 2026-09-13
Owner: agent
Created: 2026-09-13
Last updated: 2026-09-13

## Goal

Reduce full lyrics replacement latency while preserving cancellation and atomic publication. Final accepted scope uses shared Luna alignment and Cerebras analysis, removes replacement content verification, and retains saved provider selection for ordinary generation and Quick Fix. The user authorized merging this work into main on 2026-09-13. Earlier decisions and experiments below are historical.

## Scope

- Parallel independent analysis jobs after Luna alignment, using existing account concurrency limits.
- Job-aware batch balancing and publication in the final successful analysis transaction.
- Two-second targeted replacement polling, with normal account/history refresh every ten seconds.
- Conditional completed-track loading and safe per-unit timing logs.
- Shared hybrid replacement routing and removal of replacement validators and partial-merge mode. No new dependencies, tables, queues or progressive replacement UI.

## Acceptance Criteria

- [x] Independent batches can overlap and complete out of order without losing results.
- [x] Duplicates, late failures, cancellation, expired tracks, entitlement changes, and stale attempts cannot publish invalid data.
- [x] Recovery dispatches only unfinished work; already queued serial attempts remain resumable.
- [x] Replacement polling avoids frequent account/history fetches and publishes through existing guards.
- [x] Full repository checks pass; branch is ready for user testing.

## Decisions

Local Ponytail, Laravel best practices, subtitle pipeline, AI SDK and security skills informed the work. Laravel Boost documentation verified overlap locks and encrypted state. The required Laravel rules reader reviewed the concurrency design.

Keep one encrypted attempt row. Each worker returns only its own slice and merges it into current locked state. This still serializes the encrypted state per completed batch, but avoids introducing a second private-data store and cleanup lifecycle without evidence that serialization dominates latency. Provider requests execute outside database locks. Cost estimates are committed with each accepted result exactly once.

Analysis revision stays stable while batches complete; a completed-index set rejects duplicate work. Last completion assembles and publishes in the existing user/job/track/correction lock order. No separate finalization job is scheduled for new attempts. Existing serial state is normalized on claim and its remaining work is dispatched.

## Validation

Use focused backend race, middleware, recovery and API tests; extension background and real panel timer tests; PHP Pint; scripts/agent/check.ps1. User owns real-provider quality and speed acceptance. No speedup percentage claimed before that test.

## Progress

- Implemented queue fan-out, guarded slice merges, inline finalization, job-aware planning, targeted polling, and timing logs.
- Focused correction, API, interleaved completion, duplicate middleware, recovery, and panel polling regressions passed.
- Full `scripts/agent/check.ps1` passed: 551 backend tests (4417 assertions), 258 extension tests, contracts, TypeScript, extension production build, and docs lint. PHP Pint passed. Evidence: `app/backend/storage/logs/lyrics-speed-check.log`.
- Restarted the local backend/workers after verifying zero active generations/corrections. Runtime check reports `ok: true`; extension build is ready to reload. Evidence: `app/backend/storage/logs/lyrics-speed-runtime.log` and `lyrics-speed-runtime-check.json`.
- Working branch: `codex/lyrics-replacement-speed`; main remains untouched. User acceptance and real-provider speed comparison remain pending.

## Rollback

The pre-experiment main commit is `c218a77`. After integration, use reviewed revert commits to restore earlier behavior; switching to main no longer rolls back these changes. Finish or cancel active replacement attempts before changing runtime versions, then restart workers and rebuild/reload the extension. No schema rollback is needed.

## Collapsed timing fix (2026-09-13)

User testing exposed a pre-existing alignment validation gap: Cerebras assigned the entire paste to the first 240ms slot; splitting produced16 cues of3–16ms. A later failed attempt preserved this previously corrupted publication. Parallel analysis did not create the timings.

Before splitting, reject assigned text longer than the maximum of12 Unicode code points, three times the source-slot text length, or60 code points per second of slot duration. These generous limits detect gross allocation errors, not ordinary subtitle reading speed. Apply to complete and partial replacement; retain the previous removal of whole-track coverage thresholds. Prompt instructions now explicitly prohibit placing a whole song in an intro slot. The failure retains the old track and requests a retry without adding automatic AI calls.

Regressions cover the65-slot/240ms collapse, partial mode, and the short-slot boundary; existing Punjabi/Thai long-slot wrapping remains valid. Recovery of local job170 uses matching cached transcript57, fresh track/cue identities, unchanged expiry/billing, and an encrypted backup of the corrupted track. Original translations/cards cannot be reconstructed without analysis, so recovery restores source cues and timing only.

Validation passed:555 backend tests (4431 assertions),258 extension tests, contracts, TypeScript, production build and docs checks; PHP Pint passed. Evidence: `app/backend/storage/logs/lyrics-timing-guard-check.log`. Read-only validation against all56 cues of the successful Luna job171 found zero excessive slots. Local job170 recovery completed with65 source cues spanning2220–158579ms; the encrypted backup remains outside Git and the one-off recovery script was removed.

## Validation bypass experiment (2026-09-13)

- User requested removing semantic rules temporarily to test replacement alone after fresh Cerebras mismatch and Luna allocation rejections.
- Added one reversible setting, `SUBTITLE_LYRICS_VALIDATION_ENABLED=false` by default on this branch. Prompt requests direct complete alignment; service bypasses match/completeness judgments and per-slot allocation cap. Complete mapping consumes all pasted parts; partial confirmation is not used during the experiment.
- Retained structural parsing, positive timing, exact consumption, ownership, cancellation and atomic publication. Saved provider routing and concurrent analysis are unchanged.
- Laravel validation/testing rule review highlighted the empty-cues prompt trap; both prompt and service gates changed together. No dependency or schema changes.
- Restore previous behavior by setting the flag true and restarting workers. Existing validation tests explicitly enable it; experiment tests cover negative model flags, over-cap text, invalid part bounds and zero-duration rejection.
- Validation: full agent checks passed (559 backend tests / 4446 assertions, 258 extension tests, contracts, type checks, build and documentation lint). Evidence: `app/backend/storage/logs/lyrics-validation-bypass-check.log`. Live model behavior remains for user testing.
- Local runtime restored after Docker stopped during restart: 32 processes started, runtime check `ok: true`, effective `lyrics_validation_enabled=false`. Evidence: `lyrics-validation-bypass-runtime-retry.log` and `lyrics-validation-bypass-runtime-check.json` in backend storage logs.

## Cerebras mapping diagnosis (2026-09-13)

Read-only investigation of Spanish video `Yw2-9wasyx0`, job 176, attempt `cd4a8c79-b848-4015-bd93-1eb856b9d6af`: published 34 cues all within 352027–354286 ms. Alignment produced 34 draft cues in 1851 ms before analysis. Original cached transcript 73 has 97 cues.

Bounded live diagnostics used cached source cues and pasted text reconstructed from the published replacement (504 parts). This reproduces the task but not the exact original whitespace, cue UUIDs or original request. No generation or track was mutated. Numeric response summaries only were printed; full prompts/lyrics/responses were not retained.

- Current SDK format: one replay returned 97 allocations (one word each for the first 96, all remaining words in the final slot); another returned one allocation to slot 0 ending at part 503. Raw API content exactly matched the SDK parsed output in the latter replay, excluding adapter collapse.
- JSON mode with the same indexed task returned mechanical five-word allocations, with a remainder in the final slot. Removing strict schema did not establish semantic alignment.
- Simpler text-per-cue prompt/schema returned 92 and 97 distributed cues, but the checked output failed exact text preservation (3146 returned characters versus 2753 pasted). Distribution alone is not correctness; this is not a ready fix.
- Flattening the index schema still exhausted the 12000 completion-token limit without a usable result.
- Positive controls used pasted text identical to source text: 8 Spanish cues / 44 parts mapped every boundary correctly in 713 ms; all 97 cues / 547 parts exhausted 12000 completion tokens with no usable mapping (6498 ms). This demonstrates a whole-song mapping reliability problem independent of transcription mismatch, without proving a universal model capacity limit.

Engineering conclusion: the whole-song cumulative endPartIndex assignment is unreliable for the current Cerebras model/prompt. The contract allows one allocation to consume all remaining parts; backend wrapping preserves that selected slot rather than realigning it. Concurrent analysis preserves input timestamps and is not the source of this collapse. Next candidate experiment is bounded, text-anchored windows with exact pasted text assembled by code, not free-text rewriting. No production prompt/parser changes or validation re-enablement were made during diagnosis. Temporary diagnostic script removed afterward.

## Shared hybrid replacement (2026-09-13)

- User authorized a common replacement pipeline across generation selections: configured OpenAI/Luna alignment, configured Cerebras parallel analysis. No automatic fallback or global provider mutation. Existing validation bypass remains active.
- Updated actual stage provider/model in completion logs and cost estimates. Cost recorder accepts an optional explicit selection, preserving saved-provider defaults for generation. Quick Fix remains on the saved job provider.
- Relevant Laravel config/testing rules reviewed; routing exception documented in architecture and local pipeline skill. No new dependencies, migrations or frontend changes.
- User owns backend restart and live provider testing. No restart or track mutation performed for this implementation. Previously collapsed tracks still require regeneration before testing because replacement uses existing timing slots.
- Hybrid validation: full agent checks passed, 561 backend tests (4475 assertions) and 258 extension tests, plus contracts, types, build and documentation lint. Evidence: `app/backend/storage/logs/lyrics-hybrid-check.log`. Focused tests cover both saved generation providers, unchanged selection/timing and actual-provider cost records. User restart and live quality testing remain pending.

## Luna invalid range investigation (2026-09-13)

- Fresh Spanish job 177 retained its original 97 timing cues. Hybrid alignment used Luna successfully at the transport level but attempts `8d10e043-091a-4a47-926c-194b4ff1ba94` and `edd326cd-f566-4136-b403-5ba74faafa17` both failed `invalid_source_segment_bounds` before any Cerebras analysis (15139 ms and 8986 ms provider durations).
- This means an ending part index was less than the derived next starting index. Exact returned indices and the failed paste were not retained; requested the exact paste to reproduce rather than infer whether this was an empty allocation or backward overlap.
- Added allowlisted numeric boundary diagnostics to the failure log, with regression coverage for repeated and backward endpoints, preserved tracks and no transcript text in diagnostics. No range clamping, additional provider retry, worker restart or track mutation.
- Diagnostic validation: full agent checks passed, 563 backend tests (4515 assertions), 258 extension tests, contracts/types/build/docs. Evidence: `app/backend/storage/logs/lyrics-range-diagnostics-check.log`. Root-cause reproduction and alignment fix remain pending the exact Spanish paste; diagnostics alone do not fix the failure.

## Full validator bypass correction (2026-09-13)

- User clarified that all replacement validation must be off. Prior semantic-only bypass did not satisfy that request; diagnostics were not a substitute.
- Disabled mode now selects a separate permissive converter before `validatedAlignment`. No match, completeness, cue-count/order/identity, boundary, exact-consumption or allocation rejection gates run. Repeated/backward ranges are skipped without moving the global cursor; oversized endings stop at the available parts; usable text is grouped by known slot and rendered in original slot order. Omitted parts no longer reject the replacement. No usable output still cannot form a replacement.
- Replacement finalization bypasses its WebVTT validator too. Normal wrapping remains, with unsplittable or tiny-slot text kept together. Quick Fix keeps its existing validation. Existing strict mode is retained for later re-enablement.
- Regression cases cover the reported reversed-range error, repeated ranges, excessive endpoints, empty/unknown entries, duplicate/out-of-order cue IDs, incomplete consumption and tiny-slot wrapping. No live provider calls or worker restart; user owns restart/testing.
- Full bypass validation: 567 backend tests (4529 assertions) and 258 extension tests passed; contracts, types, build, docs and Pint passed. Evidence: `app/backend/storage/logs/lyrics-full-bypass-check.log`. Rule review found no unintended alignment validator call in disabled mode. Backend restart and live user testing remain pending.

## Analysis validator bypass and progress label (2026-09-13)

- Latest job 178 (`yAbMYLPdyKI`) attempt `d8967bdb-b7ae-4632-bc26-ae535a00f9dc`: Luna alignment succeeded with 53 cues in 9668 ms; Cerebras analysis then failed `cue_identity_mismatch`. The same public error and stale "Checking and aligning lyrics" label obscured the new failure stage. User reiterated that validators must be off.
- Disabled mode now bypasses analysis output validation too. Fixed source cue identity/timing/text stay authoritative; matching IDs attach returned details first, remaining entries map in response order. Missing details and malformed token indices do not block publication; missing translation falls back to source text and missing tokens/readings stay absent. Generation and Quick Fix remain strict. No extra model call or global configuration mutation.
- Label changed to "Aligning lyrics". Remaining analysis exceptions use a stage-specific public message. SDK/HTTP failures remain possible, independent of validation; a parseable response is still needed.
- User owns backend restart and extension reload. No restart or production job mutation performed.
- Validation evidence: 571 backend tests (4553 assertions) passed in `lyrics-analysis-bypass-check.log`; after correcting the label assertion, all 258 extension tests, compile and build passed in `lyrics-analysis-bypass-extension-check.log`. Contracts/docs/Pint passed. Total 829 backend/extension tests. Repeated-feedback rule: disabling replacement validation must be checked across alignment, analysis, final formatting and UI labels, with an integration test using the real analysis adapter plus mocked AI output.

## Remaining preflight bypass (2026-09-13)

- User requested all replacement validation off for now and asked about impact on generation. Audited request, submission, alignment, analysis and publication. Found the pre-AI letters/numbers check, heading-only/sung-text check and cue-count times 84 capacity limit still active.
- Disabled mode now bypasses those content checks and heading removal too. Existing schema/shape, nonempty input, 25000-character request bound, owner/entitlement, current-track/cancellation and atomic-publication protections remain. Parseable partial provider responses are not rejected merely for token-limit finish reason while validation is off; SDK/HTTP failures remain possible.
- Normal generation and Quick Fix keep their own routing and strict validation. Replacement may publish omitted or poorly timed lyrics, mismatched analysis, or missing details; a successful status does not establish content quality. Since later replacement uses current timing slots, an accepted bad timing map can require regeneration to recover.
- User owns restart/testing. No backend restart, remote push or production track mutation in this step.
- Validation: full agent checks passed with 574 backend tests (4565 assertions), 258 extension tests, contracts, compilation, build and documentation lint. Evidence: `app/backend/storage/logs/lyrics-preflight-bypass-check.log`. Added submission coverage for punctuation/emoji-only text, heading-only text and text exceeding the old cue capacity. Updated the existing API rejection case to use empty input, which remains invalid.

## Delete replacement validator implementation (2026-09-13)

- User clarified that the code must be deleted for a future rewrite, not retained behind a switch. Removed the replacement alignment validator, content/heading/capacity checks, allocation constants, classification fields and instructions, partial-source reconstruction, specialized rejection factories/branches and configuration/env switch. Replacement always assembles usable allocations and uses permissive analysis plus direct WebVTT formatting.
- Deleted the unused partial-merge UI state and retry confirmation. Full-track replacement confirmation remains. Legacy optional `allowPartial` input and historical error codes remain compatible at the API boundary; the backend ignores the old field. No new migration or client contract break.
- Removed tests exclusive to deleted validation/partial merging and updated retained lifecycle, privacy, cost and source-identity coverage to use empty output or provider failures. Kept the generation/Quick Fix validators and their tests, request-size/type/access checks, cancellation and atomic publication.
- Applied ponytail, Laravel best practices, AI SDK, subtitle-pipeline and security guidance. Rule-reader review confirmed the shared validator boundary; no added abstraction, retry, dependency or infrastructure. User's deletion request authorizes removing obsolete validator tests as part of the implementation.
- User owns restart and live testing. No service restart, paid provider call, production track mutation, commit or push in this step.
- Final reference search found a second lyric validator in the evaluation CLI. Deleted that checker and its partial-merge fixture/test, updated alignment-only inputs, and report lyric contract results as unassessed (`null`) for human review. Other agents still use automated checks. Focused evaluation tests passed with faked providers.
- Final validation: full agent checks passed with 556 backend tests (4435 assertions), 258 extension tests, contracts, TypeScript compilation, extension build and documentation lint. Pint passed. Evidence: `app/backend/storage/logs/lyrics-validator-removal-check.log`. Reference search found no remaining replacement validator functions or config flag in application code, active tests or the example environment. No live quality result is claimed.

## Integration acceptance (2026-09-13)

- User authorized committing the final changes, updating and merging into main, pushing origin/main, and deleting stale merged branches. The previous branch-only restriction is superseded.
- Final implementation evidence remains 556 backend tests (4435 assertions), 258 extension tests, contracts, TypeScript compilation and extension build; no application edits followed that passing run. Integration only updates project records and Git history.
- Next requested work is designing better lyric replacement verification. No new verifier is implemented or enabled by this merge. User owns runtime restart and further live testing.
