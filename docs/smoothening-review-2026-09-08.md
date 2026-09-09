# Smoothening implementation review

Baseline: lyrics merge `4a25575`. Initial integrated implementation reviewed at `3791353`.

Status: complete at `69c883b`. All findings below were addressed and rereviewed. Code review and Ponytail review are source reviews; browser validation belongs to the user.

## Code review findings

| ID | Category / severity | Location and problem | Why it matters | Required improvement |
| --- | --- | --- | --- | --- |
| CR1 | State ownership / must fix | `background.ts` account snapshots and `account-session.ts` invalid-session clears cross awaited storage/network boundaries without atomic originating-session checks. | A delayed account A result can overwrite or remove account B state. | Compare and clear within serialized session mutation; revalidate before publishing snapshots, state or cleanup. |
| CR2 | State ownership / must fix | Quick-fix failure and remembered-track recovery paths delete or restore tab state without confirming the original video/track still owns it. | Navigation or a newer operation can lose its current track. | Mutate only the exact originating session, video and track. |
| CR3 | Concurrency / must fix | Enrichment reads the latest track before an awaited session check. | Two simultaneous word-card responses can merge into the same old snapshot and lose one result. | Complete awaited checks before the synchronous latest-state read, merge and write. |
| CR4 | Recovery / must fix | Panel history recovery can leave running work without a monitor; exact-job GET failure drops partial progress; pending pre-response operations and terminal GET errors lack a complete recovery outcome. | Closing the panel or restarting the background can strand known work or regress readable results. | Preserve exact operation and partial state; resume one monitor through every recovery path and distinguish terminal errors from temporary interruption. |
| CR5 | Cancellation / must fix | Cleanup can match old operation A and delete newer tab state B; tab-wide cancellation markers can block an unrelated future job. | Canceling one generation can damage another or prevent its recovery. | Scope cleanup and markers to the exact job/operation and guard late completions. |
| CR6 | Identity / must fix | Runtime parser retains incomplete-identity seek/notice forms and a first-matching-tab fallback; login/logout lose captured window scope. | Duplicate-video tabs and window changes can redirect panel actions. | Require current caller identities and retain window scope throughout account actions. |
| CR7 | User interface / should improve | Partial transcript renders a Jump action whose handler only accepts ready tracks; queued checklist uses running status. | Controls imply actions that do not happen and queued work looks admitted. | Remove unsupported partial action, preserve reading position, and render the actual queue state. |
| CR8 | Playback / must fix | Study pause ownership assumes the native pause event occurs synchronously inside `video.pause()`. | The later native event clears extension ownership, so leaving study cannot resume playback. | Consume the expected native event against the current video lifecycle and retain manual ownership rules. |
| CR9 | Playback / must fix | Cue hold resumes an expiry timer for actively timed cues as well as actual held gaps. | Leaving a word can hide a long cue before its end. | Resume expiry only for a suspended held cue. |
| CR10 | Focus / must fix | Keyboard click sets hover ownership; moving focus into popover controls releases study ownership; close controls lack stable focus keys. | Keyboard study can strand playback or lose focus on async updates. | Separate pointer and focus events, preserve focus within study controls and restore the same control after rerenders. |
| CR11 | Accessibility / should improve | Editable ancestor fallback ignores native false islands; popover clamp handles only horizontal bounds. | Shortcuts can be suppressed in non-editable islands and controls can leave a short viewport. | Use native editability and constrain available vertical space as well as width. |
| CR12 | Validation / should improve | Publication tests exercise the failure handler without throwing through actual dispatch sites; cancellation tests do not execute a stale pipeline path. | Passing helper tests do not establish the new integration behavior. | Focused dispatch-site failure tests and stale-worker-after-cancel tests with fake providers and isolated storage. |
| CR13 | Support UI / should improve | Cancelled job detail labels its outcome as a failure. | A requested cancellation is misrepresented. | Use an outcome label appropriate to cancelled status. |
| CR14 | Queue fairness / must fix | The new promotion-failure regression demonstrates that a later retry immediately takes the free slot while an earlier submission remains queued. | This violates R19's earlier-waiting-before-later-retry acceptance after publication failure. | Preserve waiting backlog priority when generation or retry sees an available slot; avoid recursive publication attempts during an outage. |

## Ponytail review findings

These historical findings describe `3791353`; all five cuts are now applied. Unused `isCurrentAccount` and `storeReadySubtitleState` helpers were also removed after their final callers changed. The surrounding correctness guards add necessary code, so this is not a claim that the whole branch has fewer lines.

- `app/extension/entrypoints/background.ts:1705`: delete: first-matching-video tab lookup and legacy seek routing. Use the exact tab already supplied by the panel.
- `app/extension/utils/messages.ts`: delete: incomplete-identity compatibility branches. Require the current message contract and update stale fixtures.
- `app/extension/utils/panel/transcript.ts`: delete: partial Jump control with no supported handler. Keep the requested read-only source transcript and Copy.
- `app/extension/utils/overlay.ts`: delete: focus-to-hover callback fallback when the sole production caller supplies both callbacks. Keep explicit event ownership.
- `app/backend/app/Services/Billing/UsageLedger.php`: shrink: second ledger query for reserved minutes after the exact run events have already been fetched. Sum the loaded reservation deltas.

## Review verdict

Initial verdict: needs corrections before merge. The existing module boundaries are usable; a rewrite is unnecessary. The main risks are asynchronous ownership checks separated from the state they protect. Legacy compatibility branches and helper-only tests add apparent coverage without securing the current path. The assigned corrections favor direct guards and targeted regressions over a new state framework.

## Correction disposition

- CR8-CR11: addressed through `1bff7e7`. Rereview covered native pause event ownership, true held-cue expiry, keyboard focus through other rail controls, stable close focus, viewport constraints and deferred playback completion after a new study interaction. The content agent reports 33 focused tests and TypeScript compile passing, including the owning content entrypoint. These are automated DOM/mocked media checks, not browser evidence.
- CR12-CR14 and the U2 duplicate query: addressed through `7a0d7f2`. Rereview covered the real dispatch-site throwing connector, stale worker after cancellation, cancellation support copy and consistent ordering of queued retries/new submissions at second precision. The backend agent reports 6 focused API tests/83 assertions, 5 billing/admission tests/18 assertions and the publication failure regression passing; earlier detail/failure-handler checks also passed. SQL lock behavior still needs runtime Postgres evidence.
- CR1-CR7: addressed through `833f1c3`, including the follow-up guards in `cd2a00f`. Session storage compares and clears within its queue, recovery commits retain operation/page ownership, cancellation rereads state after awaited cleanup, reset broadcasts to all owned tabs and serializes the persistent recovery flag, and live submissions remain preparing until their POST resolves. Exact identities replace legacy routing. Partial Watch retains source/search/Copy and scroll position; queued stages remain pending. The independent runtime regressions first reproduced metadata loss, stale cancellation notifications and missing reset broadcasts, then passed with the fixes. Additional runtime tests cover a delayed POST and delayed old content history during newer generation/navigation.

## Final evidence and verdict

- Root `scripts/agent/check.ps1` passed at `375cfc8`: documentation, contracts, 439 backend tests/3,107 assertions, 218 extension tests, TypeScript compile and Chrome production build.
- The final extension-only correction at `69c883b` was rereviewed and validated in the integration worktree: 219 tests in 30 files, TypeScript compile and production build all passed. Backend/contracts did not change after the root harness pass.
- Final documentation lint and Git whitespace checks passed before the delivery commit. No browser tests, live services or paid providers were used.
- Browser behavior, production Postgres concurrency, real Redis outage ambiguity and external-provider timing still require the user's runtime validation. The manual checklist records these limits; passing fake-service/SQLite tests does not establish them.

Overall quality: good to merge. The implementation uses the existing module, storage and run-ledger boundaries. The background entrypoint remains large, but named functions and explicit ownership guards keep the reviewed paths inspectable. No rewrite, new dependency or speculative state framework is needed for this scope.

Complete improvement list: CR1-CR14 and the five Ponytail cuts above are resolved; no actionable review finding remains open. The early helper-only coverage and legacy fallbacks had the signs of rushed implementation. The corrected paths now have focused integration evidence and are maintainable within the existing architecture. This verdict covers code readiness; the user's manual validation remains pending.

Final Ponytail verdict: Lean already. Ship.
