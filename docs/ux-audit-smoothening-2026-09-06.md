# Smoothening UX Audit

Date: 2026-09-06

Baseline: `smoothening` at `4969225d80731fc3f5ad95d2a9a3f87b7d685487`.

Audit worktree: `C:\Users\jaden\AppData\Local\Temp\opencode\smoothening-ux-audit`.

Follow-up: the G3/G4 and G2/G5/U4 implementation and validation below were added after the read-only audit. Other findings and the original audit evidence remain baseline observations.

## Commit Foundation Scope (2026-09-07)

This documentation-only checkpoint preserves the original audit and historical evidence from the uncommitted worktree. It does not contain the implementation: following individual G3, G4, G2, G5, and U4 commits package that work with per-item status updates. Historical suite totals below are not claims about this checkpoint's code tree.

- Branch: `audit/smoothening-fixes`, isolated audit worktree only. Overall plan: `docs/exec-plans/active/2026-09-07-resolve-smoothening-ux-audit.md`.
- User now authorizes every audit item except password-related work, one fix per commit. Foundation task stops after packaging existing changes; remaining batches are not started.
- Explicit exclusions: R24; password recovery and email-verification portions of G5; password requirements/hints portion of U4. Our remaining register/reset hints, minlength attributes, and associated test were withdrawn before the first commit. Pre-existing auth routes, enforcement, password rules, and security are untouched.
- G2's honest navigation-only action and G3's visible manual GET refresh are accepted scoped resolutions, not reasons to invent true retry or automatic polling. Browser validation remains deferred, and runtime limitations remain documented.

## Executive Summary

The most disruptive established gap is the lack of a clear cancellation action in the extension. The strongest code-supported explanations for stale or disappearing UI are wrong-job reconciliation, polling that stops after transient failures, and content bindings that do not reliably recover after navigation or player replacement.

No application bug was browser-reproduced during this audit. The report separates directly established implementation gaps, conditional code-supported risks, and optional usability improvements. Passing helper tests are not presented as proof that the user journeys work.

The branch already has useful foundations: native TextTrack playback, partial-track delivery, backend history recovery, server-side ownership and credit accounting, run IDs, batch cancellation checks, and targeted unit/feature tests. The recommendation is to make these paths agree, not replace the UI, introduce a new framework, or redesign generation.

No fixes, paid generation, billing changes, destructive application actions, or production mutations were performed. The ongoing lyrics-editing checkout was not modified. Dependency installation and generated build artifacts were confined to the audit worktree. This report is the only intended tracked addition.

## Baseline And Evidence

- The initial workspace was not on `smoothening`; work stopped until the user authorized a separate worktree.
- The audit worktree was created on `smoothening` at the commit above.
- This baseline uses a side panel opened through the toolbar action, not a toolbar popup. The toolbar-to-panel path was inspected; there is no popup UI to audit on this branch.
- Lyrics/subtitle editing is absent and explicitly excluded from this baseline's first-release scope. Features from the lyrics-editing branch were not assumed to exist.
- Read: `AGENTS.md`, `ARCHITECTURE.md`, product specifications, frontend/design, reliability, and review guidance. Focused reviews also consulted relevant security, harness, and backend routing guidance.
- Three focused source reviews covered panel/background, content/overlay/playback, and backend/dashboard. Their results were consolidated and checked against relevant source and test output.

### Evidence Labels

- **Confirmed implementation gap:** The missing action or current behavior is directly established by source. This does not mean it was exercised in a browser.
- **Code-supported risk:** A concrete conditional failure path exists in source, but its trigger and end-to-end impact were not reproduced.
- **Reproduced application bug:** None. A source-equivalent JavaScript ordering check was run for the startup candidate, but it was not an application-entrypoint reproduction.
- **Optional improvement:** A bounded usability improvement rather than an established broken runtime flow.

Severity: **P1** blocks a core task, uses the wrong account/player context, or risks credit/run integrity. **P2** significantly weakens recovery or usability. **P3** is useful polish.

All source references are repository-relative and refer to this exact `smoothening` baseline. Line numbers are not references to the concurrently edited lyrics branch.

## Status Of The Three User Reports

| User report | Audit status | Conclusion |
| --- | --- | --- |
| UI sometimes fails to update when underlying state changes | Unverified at application runtime; strong code support | Startup ordering, wrong-job reconciliation, terminal local errors after failed polls, missing recovered-job monitoring, cue relay gaps, and content-insensitive transcript rendering are concrete candidates. See R1-R3, R7, R10-R12. |
| Users lack actions such as canceling generation | Confirmed implementation gap | No dedicated extension cancellation action/API exists. Dashboard deletion is the implemented stop/remove path, but it is destructive and separate from the generation progress journey. See G1. |
| Lyrics/transcript panel disappears until refresh | Unverified at application runtime; strong code support for overlay loss | SPA entry, stale hydration, detached binding, and fullscreen containment can explain missing on-page captions. They do not prove why Chrome's native side panel would disappear. This baseline has no lyrics editor. See R4-R6 and R13. |

Important distinction: the caption rail intentionally renders nothing when a ready track has no active cue. A normal silent gap, a hidden overlay preference, a detached overlay, and a closed native side panel are different states. Reproduction should identify which element disappears before selecting a fix.

## Confirmed Implementation Gaps

These findings are established by source inspection, not browser reproduction.

### G1. P1: No Clear Generation Cancellation Action

**Surface:** Side-panel Watch/History, generation API, dashboard.

**Impact:** A learner who selects the wrong video or options cannot stop the operation from its progress screen. Closing the panel or hiding captions does not explain whether work and minute reservations continue.

**Trigger/manual steps:** With a local fake provider, submit one running and one queued job. Inspect Watch and History for a stop action. Close the panel or hide the overlay, then inspect job status separately. Do not use paid generation for this check without approval.

**Expected versus actual:** An explicit cancellation action should explain what stops and what happens to minutes. The extension has no cancellation request/action. Dashboard deletion is the existing stop/remove path and removes the job rather than leaving a clear canceled outcome.

**Evidence:** `app/extension/entrypoints/background.ts:89-138`; `app/backend/tests/Feature/SubtitleJobApiTest.php:1922-1928`; `app/backend/app/Http/Controllers/WebSubtitleJobController.php:61-71,99-127`; `docs/RELIABILITY.md:49-51`.

**Confidence:** High, source-confirmed absence. No cancellation operation was executed.

**Smallest useful fix:** Add owner-scoped cancellation for queued/running jobs and expose it in Watch and History. Reuse run guards, batch cancellation, reservation settlement, and admission. Keep cancellation distinct from deleting completed tracks.

**Acceptance:** Cancel queued and running jobs. Queued work must make no provider call; late results must not resurrect the canceled run; reservation settlement must occur once; the next queued job must be admitted. Cancellation must not claim to interrupt a provider request already in progress.

**Current semantics:** Hiding UI is not stopping backend work. Batch cancellation can skip jobs before execution but does not interrupt external calls already underway. Deleting a completed track does not mean completed usage is refunded. Credit-race concerns in R16 must be addressed before presenting cancellation as reliable.

### G2. P2: History Retry Does Not Reliably Retry The Selected Job

**Follow-up status (2026-09-07): Partially mitigated.** History now offers native Open video links only, with explicit instructions to review Watch settings and a warning that saved job options are not restored. The misleading Retry control and its global-default submission handler were removed. True selected-job retry is deferred; backend compatible-job reuse/reset is unchanged.

**Surface:** Side-panel History.

**Impact:** Users can retry the wrong language/layer combination or click Retry and receive only a new video tab.

**Trigger/manual steps:** Fail a job using local provider fakes, change generation defaults, switch videos, and click the failed card's Retry action.

**Expected versus actual:** Retry should use that job's video/options or clearly open a review step. For another video, the current action only opens the video. For the current video, it generates using current global settings. The action does not carry the selected job ID.

**Evidence:** `app/extension/entrypoints/sidepanel/render/job-history.ts:72-82`; `app/extension/entrypoints/sidepanel/main.ts:466-488`; `app/extension/entrypoints/background.ts:308-319`.

**Confidence:** High, source-confirmed behavior.

**Smallest useful fix:** Carry the selected job identity and use its existing video/options. If navigation alone is intended, rename the action to "Open video to retry."

**Acceptance:** Retry two differently configured failures after changing defaults. The intended payload must be preserved, with no unexpected submission on a navigation-only action.

### G3. P2: Dashboard Waiting States Never Update On Their Own

**Follow-up status (2026-09-06): Partially mitigated.** Dashboard and detail pages now offer native GET "Refresh status" links, timestamped snapshot labels, and explicit non-updating copy. Checkout-return copy directs users to refresh. This meets the audit's manual-refresh acceptance, but does not add automatic synchronization or prove a browser/webhook journey.

**Surface:** Dashboard, job details, checkout return.

**Impact:** Users can wait indefinitely on an obsolete progress percentage, reservation balance, or subscription state.

**Trigger/manual steps:** Open a local running-job page and complete the job through a fake worker. Separately simulate a delayed billing webhook in an isolated test environment. Leave the page open.

**Expected versus actual:** The page should update or explicitly identify itself as a snapshot and offer refresh. Values are server-rendered once; loaded JavaScript does not refresh them. "Billing updates can take a moment" suggests that waiting will resolve the visible state, but it will not.

**Evidence:** `app/backend/resources/views/dashboard.blade.php:44-79,103-118`; `app/backend/resources/views/account/job-show.blade.php:14-47`; `app/backend/public/js/site-interactions.js:1-53`.

**Confidence:** High, source-confirmed behavior.

**Smallest useful fix:** Add a safe GET "Refresh status" action and a last-checked/snapshot label. Automatic polling is optional, not required for a useful first fix.

**Acceptance:** Users can obtain completion, released minutes, and subscription updates through a visible action without guessing that browser refresh is necessary. Refresh must not replay a mutation.

### G4. P2: Dashboard Deletion Is Hard To Identify And Underexplained

**Follow-up status (2026-09-06): Fixed in code; browser validation deferred.** Rows/details show stored video IDs and canonical YouTube links. Clear all shows the complete owner-scoped count, including jobs beyond eight rows and older processing versions, even when the recent list is empty. Visible copy and existing confirmations describe targets, track removal/lost reuse, completed-usage non-refund, attempted reservation release, and in-progress provider limitations. Deletion semantics and R16 are unchanged.

**Surface:** Dashboard and job details.

**Impact:** Users may delete the wrong generation or remove more completed tracks than intended.

**Trigger/manual steps:** Seed more than eight jobs, including several with the same language pair/duration. Inspect individual delete and Clear all confirmations without submitting them.

**Expected versus actual:** Jobs should have recognizable video identity, and confirmation should describe total scope and credit consequences. Rows/details primarily expose metadata and UUID without a source-video link. Only eight recent jobs are displayed, but Clear all deletes every owned job. Copy does not adequately explain removal of completed tracks, loss of reuse, or the absence of a completed-usage refund.

**Evidence:** `app/backend/app/Http/Controllers/DashboardController.php:75-89`; `app/backend/resources/views/dashboard.blade.php:82-118`; `app/backend/resources/views/account/job-show.blade.php:7-71`; `app/backend/app/Http/Controllers/WebSubtitleJobController.php:89-109`.

**Confidence:** High, source-confirmed behavior.

**Smallest useful fix:** Show the stored video ID and canonical YouTube link; show the total affected count; state track and credit consequences. No new title-fetching service is needed.

**Acceptance:** With more than eight mixed-status jobs, users can distinguish targets and see the complete deletion scope before confirming.

### G5. P2: Account Recovery Has Dead Ends And Contradictory Access Copy

**Follow-up status (2026-09-07): Partially mitigated; scope corrected at user request.** Account and billing navigation, billing-denial directions, honest preference labels, and removal of disabled Upgrade remain. The added Forgot password/Verify email links, their error directions, and verification-page identity/account-switch UX were intentionally withdrawn: the user had intentionally omitted these additions to keep account creation easy. Existing auth routes, verification enforcement, recovery functionality, and security remain unchanged. Do not reintroduce this withdrawn UX without an explicit request.

**Surface:** Extension Account and web email verification.

**Impact:** Users encountering password, verification, or subscription problems lack an effective next action.

**Trigger/manual steps:** Inspect Account while signed out and with a non-entitled test account. Register a local unverified account using the wrong inbox, then try the verification page's sign-in link.

**Expected versus actual:** Recovery should link to existing account flows and describe actual access. Extension Account offers sign-in/out but no useful password/verification/billing route; Upgrade is disabled; authenticated users receive unconditional enabled/available feature copy. Web verification links "Back to sign in" without signing out the already-authenticated user and exposes no explicit account switch.

**Evidence:** `app/extension/entrypoints/sidepanel/index.html:244-282`; `app/extension/entrypoints/sidepanel/render/account.ts:12-19`; `app/extension/utils/api.ts:219-232`; `app/backend/resources/views/auth/verify-email.blade.php:5-19`; `app/backend/resources/views/layouts/account.blade.php:23-33`.

**Confidence:** High for missing actions and copy. The verification redirect behavior was not runtime-tested.

**Smallest useful fix:** Link existing recovery/dashboard routes using the configured origin, avoid unsupported entitlement promises, and add a CSRF-protected logout/account-switch action identifying the verification email.

**Acceptance:** Each auth/billing denial has a relevant next step. An unverified user can switch accounts without clearing cookies. Account copy does not contradict generation denial.

## Code-Supported Risks

These paths require the stated conditions to reproduce. They are not confirmed application-runtime bugs.

### R1. P1: Panel Startup Can Fail Before Synchronization Listeners Attach

**Surface:** Side-panel initialization.

**Impact:** Polling, tab-change handling, panel connection, and active-cue listeners may never initialize.

**Trigger:** Execute the entrypoint under native lexical `let` semantics, including a development/module path that preserves these declarations.

**Expected versus actual:** Poll variables should initialize before scheduling. Startup calls `scheduleNextBackendPoll()` at line 203; that function reads `backendPollTimer`, declared later at line 228. This is a temporal-dead-zone access in native-module execution. Important listener registrations occur between these points.

**Evidence:** `app/extension/entrypoints/sidepanel/main.ts:199-239`.

**Confidence:** High for source ordering. Production impact is unverified and depends on emitted bundle behavior. A source-equivalent JavaScript check demonstrated the language-level error, not an import of the real app. The production build succeeded; that does not establish runtime behavior.

**Smallest useful fix:** Move polling variable initialization above startup calls.

**Acceptance:** Import the actual entrypoint with browser/DOM mocks and smoke-test WXT dev plus the production extension. Assert no startup exception, one poll timer, a connected panel port, and functioning tab/cue listeners.

### R2. P1: Old History Can Replace A Newly Submitted Generation

**Surface:** Background state, Watch, overlay.

**Impact:** A new operation can immediately appear completed or failed using an older result, and its monitor can stop.

**Trigger:** Generate another language/layer variant for a video with older history while its POST is delayed.

**Expected versus actual:** The submitting operation and then its returned job ID should remain authoritative. Generation publishes loading, then builds panel state against cached history. Reconciliation chooses by video ID, not the current job/options. It classifies failed jobs as active because its predicate is `status !== 'completed'`. An older terminal state can replace loading and terminate the new monitor's guard.

**Evidence:** `app/extension/entrypoints/background.ts:261-282,390-426,627-633`; `app/extension/utils/backend-subtitle-state.ts:18-64`.

**Confidence:** High, conditional code-supported race.

**Smallest useful fix:** Preserve submitting state until POST returns; then reconcile the exact job ID. Use video-history fallback only without a current operation. Do not prefer failed history over a usable completed track.

**Acceptance:** Defer POST with old completed/failed variants present. Watch stays pending and subsequently follows only the returned job. An old result cannot terminate the new monitor.

### R3. P1: Transient Poll Failure Or Job Recovery Can Strand UI

**Surface:** Background polling, Watch, partial overlay.

**Impact:** Backend work can finish while Watch stays failed or loading. Users lose usable partial results and may retry unnecessarily.

**Trigger:** Fail one status GET; expire authentication during generation; restart the background while a job runs; or open another tab for an in-flight job and then close the panel.

**Expected versus actual:** Preserve job identity/partial data and resume synchronization after recovery. A status exception exits monitoring and publishes local error without the known job ID/partial track. Reconciliation returns a local error unchanged even if history now says completed. Independently, history recovery stores loading state but starts no monitor; only fresh submission calls the polling loop.

**Evidence:** `app/extension/entrypoints/background.ts:145-205,320,361-375,390-450`; `app/extension/utils/backend-subtitle-state.ts:18-20`.

**Confidence:** High, code-supported.

**Smallest useful fix:** Distinguish connection/auth interruption from terminal job failure. Retain operation identity and partial data. Reuse one monitor when recovering queued/running work, with current-operation guards.

**Acceptance:** One failed GET followed by running/completed responses recovers without another POST. Repeat after reauthentication and background restart, with the panel closed during completion.

### R4. P1: Home/Search SPA Entry Can Leave Videos Without A Content Script

**Surface:** YouTube entry journey, overlay, shortcuts, transcript seek.

**Impact:** Normal discovery-to-watch navigation can lack captions and page controls until refresh.

**Trigger/manual steps:** Load YouTube Home/search/channel first, then navigate to watch or Shorts without a document reload. Compare with a direct watch-page load using an existing track.

**Expected versus actual:** Every supported destination should initialize. Static content-script matches only cover watch/Shorts. Its SPA listeners exist only after injection. Broad host permission does not itself inject a content script into a document whose initial URL did not match.

**Evidence:** `app/extension/entrypoints/content.ts:30-33,76-99`; `app/extension/wxt.config.ts:13-16`; `app/extension/entrypoints/background.ts:842-847`.

**Confidence:** High for same-document navigation under these conditions; current real-site reproduction is pending.

**Smallest useful fix:** Inject on YouTube documents broadly and activate supported-video behavior through the existing URL parser. Keep non-video pages free of an unnecessary permanent rail.

**Acceptance:** Home/search to watch/Shorts and back/forward yields one functioning host/binding without refresh.

### R5. P1: Player Replacement And Fullscreen Lack Reliable Overlay Recovery

**Surface:** Overlay mounting and video binding.

**Impact:** Captions can disappear, stay stale, sit outside the player, or seek a detached video.

**Trigger:** Replace the video element without a handled route event; remove the host; delay first video mount beyond roughly three seconds; or fullscreen the player element.

**Expected versus actual:** One live binding and one visible overlay should follow the current player. Same-track deduplication checks track ID/revision without validating video identity/connectivity. Overlay mounting checks references, not connection to the document. Missing-video retries expire. The host is fixed to the document viewport under `body`, outside a player-element fullscreen subtree.

**Evidence:** `app/extension/entrypoints/content.ts:292-312,347-374,445-524`; `app/extension/utils/overlay.ts:62-65,306-331`; `app/extension/utils/overlay/overlay-styles.ts:1-47`.

**Confidence:** High for missing checks. Frequency on current YouTube, exact fullscreen behavior, and theater geometry were not browser-tested.

**Smallest useful fix:** Validate binding liveness before deduplication; reattach a detached host; respond narrowly to player replacement; mount relative to the active player/fullscreen container.

**Acceptance:** Replace the player while paused, remove the host, delay mounting, and transition normal/theater/fullscreen with the panel docked. Recover the existing track without regeneration, duplicate tracks, or duplicate listeners.

### R6. P1: Late Content Responses Can Clear Or Replace The Current Video

**Surface:** Content hydration and on-click word cards.

**Impact:** A delayed response for video A can blank video B or place A's cue/card data into B's UI.

**Trigger:** Delay A's hydration/enrichment, navigate to B or clear state, then resolve/reject A's request.

**Expected versus actual:** Obsolete responses should be ignored before changing B. Hydration has no operation/disposal guard and clears the binding before rejecting wrong-video state. Enrichment unconditionally replaces the current track and matches active cue by cue ID alone. Its catch path can dereference `.track` after current state becomes non-ready.

**Evidence:** `app/extension/entrypoints/content.ts:250-267,347-403,778-832`.

**Confidence:** High, code-supported.

**Smallest useful fix:** Capture video/track/operation identity at request start and validate it before applying success or failure. Reject mismatched state before clearing a valid binding. Log captured identifiers rather than mutable state.

**Acceptance:** Resolve and reject requests after navigation, regeneration, reset, and invalidation. No foreign cue, destination clearing, post-teardown binding, or unhandled exception occurs.

### R7. P1: Transcript Controls Can Follow Or Seek The Wrong Tab

**Surface:** Background cue messages and panel transcript navigation.

**Impact:** A hidden tab can move the visible highlight; Jump can start playback in another tab/window.

**Trigger/manual steps:** Open two generated videos, then two copies of the same video, across one and two windows. Play one while using the other panel's Jump action.

**Expected versus actual:** Each panel should follow/control its displayed tab. Cue notices lose sender-tab identity and broadcast to all panels; receivers do not check even video ID. Seek requests omit target tab ID and the background chooses the first matching video in its map. Several mutation responses also drop the supplied window ID.

**Evidence:** `app/extension/entrypoints/background.ts:118-137,208-220,282,781-798`; `app/extension/entrypoints/sidepanel/main.ts:217-224`; `app/extension/entrypoints/sidepanel/transcript-view.ts:69-73`.

**Confidence:** High, code-supported.

**Smallest useful fix:** Carry displayed tab identity through notices/actions, preserve window scope in responses, and validate expected video at delivery. Video ID alone is insufficient for duplicate-video tabs.

**Acceptance:** Only the owning panel highlights and only its displayed tab seeks/plays, including duplicate videos and a focus change while an action is pending.

### R8. P1: Account Changes Do Not Isolate Cached And Pending State

**Surface:** Sign-in/out, remembered tracks, account/history requests.

**Impact:** Account B can receive account A's local transcript or account summary; a late A failure can invalidate B's new session.

**Trigger:** Load a track as A, leave account/history requests pending, sign out, sign in as B, then complete A's requests.

**Expected versus actual:** Account-owned local state and writes should not cross sessions. Logout removes the session but retains tab/remembered-track state keyed by video. Old auth failures can clear the current session; old account successes can update the currently stored session's summary.

**Evidence:** `app/extension/entrypoints/background.ts:578-596,741-778,858-870`; `app/extension/utils/active-tracks.ts:7-18`; `app/extension/utils/account-session.ts:41-55`.

**Confidence:** High, code-supported. This is local account isolation, not evidence of backend authorization bypass.

**Smallest useful fix:** Clear or account-scope tracks/history/tab state on session changes and guard pending writes/clears with their originating session identity.

**Acceptance:** Resolve A's successful and 401 responses after B signs in. B's token, summary, history, and displayed tracks remain unchanged by A.

### R9. P2: Overlapping Actions Can Lose Preferences Or Duplicate Submission

**Surface:** Settings storage, generation setup, background handlers.

**Impact:** Selected options can silently revert; rapid Generate clicks can produce overlapping requests/monitors.

**Trigger:** Change two controls quickly, overlap a shortcut with a panel preference change, or double-click Generate while settings/page-snapshot reads are delayed.

**Expected versus actual:** Preference patches should accumulate and generation should have one claimed operation. Settings use independent whole-object read/merge/write calls, so the last write can erase another patch. Generate is not immediately disabled, and separate background handlers can both observe non-loading state before awaited preparation completes.

**Evidence:** `app/extension/utils/settings.ts:23-32`; `app/extension/entrypoints/background.ts:208-237,261-279,817-820`; `app/extension/entrypoints/sidepanel/main.ts:165-189,294-299,531-532`.

**Confidence:** High, conditional code-supported races. Duplicate client POSTs do not establish duplicate provider charges; backend reuse may prevent those.

**Smallest useful fix:** Serialize the shared settings read/merge/write operation. Claim submission before awaited preparation, show immediate busy feedback, and use operation identity for later writes. Submit only after pending preference changes settle.

**Acceptance:** Deferred concurrent patches both persist. Two Generate messages produce one POST/monitor and use the latest selected settings.

### R10. P2: Same-ID Transcript And Word-Card Updates Can Remain Stale

**Surface:** Panel transcript and overlay learning cards.

**Impact:** Rendered text/readings/search results can disagree with current data; learned word metadata can revert when revisiting a cue.

**Trigger:** Deliver updated cue data retaining cue IDs, revisit an enriched cue, or complete two word-card requests in reverse order.

**Expected versus actual:** Visible/searchable content and loaded cards should reflect the latest matching track. Transcript rendering hashes video ID, cue IDs, query, and two flags, not cue content. The WebVTT callback resolves cues from its originally captured track. Concurrent enrichment uses pre-request track snapshots, so a later whole-track replacement can lose another token's metadata locally.

**Evidence:** `app/extension/entrypoints/sidepanel/transcript-view.ts:18-37,74-88`; `app/extension/utils/webvtt-track.ts:41-63,170-180`; `app/extension/entrypoints/content.ts:499-512,820-832`; `app/extension/entrypoints/background.ts:469-500`.

**Confidence:** High for update suppression and snapshot behavior. No lyrics-editing trigger is assumed; enrichment is present on this branch. A shipped source/translation mutation with identical IDs was not established beyond the renderer's supported update interface.

**Smallest useful fix:** Include render/search-affecting content or a real revision in transcript invalidation. Resolve active cue IDs against the latest guarded track and merge returned token metadata into the latest matching state using the existing helper.

**Acceptance:** Same-ID data updates refresh visible rows/search. Enrich two words in either response order, leave/replay the cue, and retain both cards without another request.

### R11. P2: Panel Reopen And Extension Seeks Can Miss Active-Cue Updates

**Surface:** Transcript highlighting and playback relay.

**Impact:** The video/overlay moves while the panel remains unhighlighted or highlights the previous cue.

**Trigger:** Reopen the panel while paused mid-cue, or use Jump/previous/next/replay at zero timing offset.

**Expected versus actual:** Opening should obtain a current-cue snapshot, and seeks should publish the destination cue. Cue notices are only forwarded while a panel is open; no snapshot is supplied on connection. Seek code assigns `activeCue` without broadcasting, and later cue callbacks can return early because that same cue is already assigned.

**Evidence:** `app/extension/entrypoints/background.ts:60-64,118-126`; `app/extension/entrypoints/content.ts:368-373,503-520,553-560,653-676`; `app/extension/entrypoints/sidepanel/main.ts:214-224`.

**Confidence:** High, code-supported.

**Smallest useful fix:** Pull the current cue from the target tab on connection/tab change and publish through a shared active-cue assignment path. Deduplicate against last broadcast identity, not just render state.

**Acceptance:** Reopen while paused and perform every extension seek path. Correct highlighting appears immediately without waiting for the next natural cue transition or rebinding the track.

### R12. P2: Background Refresh Can Swallow Sign-In Feedback

**Surface:** Account form and global panel rendering.

**Impact:** The panel can say "Signing in" after a failed action, say "Signed in" beside stale anonymous state, or re-enable controls during a pending operation.

**Trigger:** Delay login while a newer ordinary state refresh completes.

**Expected versus actual:** Action outcomes and busy state should survive unrelated snapshot reads. All requests share one sequencing counter, so a newer refresh can suppress an older login outcome. Rendering can also overwrite account-specific disabled state through general settings control updates. A request-level error can replace a previously valid view with synthetic anonymous/no-video state.

**Evidence:** `app/extension/entrypoints/sidepanel/main.ts:306-328,345-393,528-552,706-754,785-808`.

**Confidence:** High, code-supported.

**Smallest useful fix:** Sequence snapshots separately from account action outcomes. Let account rendering own account busy controls. Retain the last valid snapshot on a failed action and show its error locally.

**Acceptance:** Resolve delayed login with success and failure after a newer refresh. Feedback, account identity, and disabled controls agree. A failed preference action does not pretend the user signed out or left the video.

### R13. P2: Learning Interaction Can Lose Focus, Pause Ownership, And Feedback

**Surface:** Overlay word cards, source hover-pause, cue hold, transcript shortcut.

**Impact:** Keyboard study loses its place; playback can resume while a word remains focused; a held cue can disappear during study; recovery/status feedback can be invisible.

**Trigger:** Open/close or retry a word card with the keyboard; focus and hover the same token then end only one interaction; hover a held cue just before expiry; invoke transcript/copy/replay in a silent gap with the panel closed.

**Expected versus actual:** Focus and extension-owned pause should persist for the active interaction, and status/recovery should remain visible. Changed overlay HTML replaces focused controls; focus restoration requires keys absent from the rendered controls. Blur/pointerleave resume independently. An already-running cue-hold timer still expires after study pauses playback. Ready-with-no-cue returns empty HTML including status; the transcript shortcut only sends a focus notice, not an open-panel action. The native toolbar remains a fallback.

**Evidence:** `app/extension/utils/overlay.ts:134-201,268-303`; `app/extension/entrypoints/content.ts:50-62,238-240,315-336,535-600`; `app/extension/utils/overlay/overlay-render.ts:27-32,164-175,205-231`; `app/extension/entrypoints/background.ts:128-130`.

**Confidence:** High for lifecycle/event behavior; no keyboard or pointer journey was browser-reproduced.

**Smallest useful fix:** Add cue-scoped stable focus keys and close/Escape return focus; resume only when no relevant hover/focus remains; suspend held-cue expiry while study owns the pause; explicitly release owned pause during teardown. Render transient feedback independently of cue text and provide truthful panel-opening guidance.

**Acceptance:** Keyboard open/result/close returns to the correct word. Ending hover while focus remains does not resume. A held cue remains usable during study. Hiding/rebinding does not strand playback or resume a manually paused video. Closed-panel recovery is actionable during silence.

### R14. P2: Partial Captions And Local Binding Failures Have Unusable States

**Surface:** Partial overlay and completed-track attachment.

**Impact:** Early captions can be unreadable with study blur enabled; an attachment failure can look like a normal gap or missing generation.

**Trigger:** Enable source blur during partial-track delivery. Separately force a hidden-track load error or exhaust video-binding retries.

**Expected versus actual:** Partial text should be revealable, and local attachment failures should offer retry without regenerating. Partial source uses a token-blur class on a plain span, but reveal selectors require a token-card ancestor; it is not focusable. Text-track errors only log, while ready-with-no-cue renders nothing. Exhausted video retries have no actionable terminal binding state.

**Evidence:** `app/extension/utils/overlay/overlay-render.ts:101-140`; `app/extension/utils/overlay/overlay-styles.ts:207-225`; `app/extension/utils/webvtt-track.ts:66-80`; `app/extension/entrypoints/content.ts:292-312,488-497`.

**Confidence:** High, code-supported.

**Smallest useful fix:** Make partial source one independently focusable/revealable layer, without inventing partial word cards. Expose a local attachment error and retry-binding action separate from generation status.

**Acceptance:** Each partial blur layer reveals independently by pointer/keyboard. Track-load failure and exhausted binding show a retry action that uses the existing track and clears after successful attachment.

### R15. P2: Clear Local State Can Preserve Login And Immediately Restore The Track

**Surface:** Study's this-device reset.

**Impact:** The action does not deliver the documented reset and can appear to do nothing to the active transcript.

**Trigger:** Clear local state while signed in on a video with a stored backend track.

**Expected versus actual:** The documented operation removes session/account cache, settings, and active-track state, returning defaults/no track. The shared clear helper removes only settings/install ID. The handler clears local tracks/map but retains the session, then synchronizes backend history and can recover the just-cleared track.

**Evidence:** `app/extension/utils/settings.ts:35-37`; `app/extension/entrypoints/background.ts:542-564`; `docs/RELIABILITY.md:55`.

**Confidence:** High, code-supported mismatch with documented behavior.

**Smallest useful fix:** Choose and label the exact reset scope. For the documented full local reset, clear session/account-owned cache, invalidate old requests, and return local defaults without immediate backend recovery. Do not delete backend tracks.

**Acceptance:** Seed login, settings, history, and track; clear once. The promised state remains after delayed old requests complete, with backend tracks untouched.

### R16. P1: Failure/Delete Races Can Affect A Replacement Run's Credits

**Surface:** Backend failure, immediate retry, dashboard deletion.

**Impact:** A replacement generation can lose its reservation/artifacts, or reserved minutes can remain held after the job is gone.

**Trigger:** Interleave immediate retry with failure cleanup or dashboard deletion. Alternatively stop a worker after failed status commits but before credit settlement.

**Expected versus actual:** A terminal transition should settle its own run once, and old cleanup must not adopt a new run. Failure status commits before settlement/cleanup; a later callback can return because status is already terminal. Refreshing the model during cleanup can adopt a retried run, while some artifact deletion is scoped only by job ID. Deletion also uses an unlocked earlier snapshot; retry can create a new reservation before that row is deleted without settling the replacement run.

**Evidence:** `app/backend/app/Services/Subtitles/SubtitleJobFailureHandler.php:41-62,109-113`; `app/backend/app/Services/Subtitles/SubtitleJobArtifactStore.php:303-312`; `app/backend/app/Http/Controllers/WebSubtitleJobController.php:61-71,99-127`; `app/backend/app/Services/Billing/UsageLedger.php:320-330`; `app/backend/database/migrations/2026_05_22_044309_create_billing_usage_events_table.php:16-18`.

**Confidence:** High, source-supported concurrency risk. No credit loss or concurrent database execution was reproduced.

**Smallest useful fix:** Lock/re-read the owned current run within terminal/delete transactions. Commit terminal status and ledger settlement consistently; scope artifact cleanup to the captured run; perform filesystem cleanup afterward using that immutable identity. Apply to individual and bulk deletion and reuse for cancellation.

**Acceptance:** Pause failure/deletion at each boundary and interleave retry. Replacement reservations/artifacts remain intact, deleted jobs leave no active reservation, and interrupted failure handling is recoverable. Use disposable Postgres for real locking evidence.

### R17. P1: Some Pipeline Continuations Can Adopt A Retried Run

**Surface:** Transcript merge, cached-transcript continuation, provider-cost recording.

**Impact:** Stale work can advance replacement generation out of order or dispatch duplicate analysis.

**Trigger:** Reset a failed compatible job while an earlier merge/cache-hit continuation is already executing.

**Expected versus actual:** Every continuation should retain its queued run identity and stop when stale. These paths guard initially but later perform unguarded updates/refreshes. A refreshed model can represent the replacement run; later artifacts and dispatch can then use that new run ID. Stage and cost updates are not uniformly scoped to the expected run.

**Evidence:** `app/backend/app/Services/Subtitles/SubtitleGenerationPipeline.php:278-315,354-373,480-515,730-738`; `app/backend/app/Services/Subtitles/SubtitleProviderCostRecorder.php:89-95`.

**Confidence:** High, source-supported race; not concurrency-tested.

**Smallest useful fix:** Carry immutable queued run identity through continuation work. Use the existing locked-current-run pattern for persistence/stage changes, scope cost writes, and dispatch only after a successful guarded transition.

**Acceptance:** Change A to B during merge and cached-transcript processing. A cannot update B's stage, duration, language, cost, artifacts, reservation, or dispatched work.

### R18. P2: Queue Publication Failure Leaves Work Looking Running

**Surface:** Submission, queued-job promotion, credits/progress.

**Impact:** The user can receive an error while a job holds minutes and a slot but has no published worker task.

**Trigger:** Make Redis queue publication throw after initial creation or queued-job promotion commits.

**Expected versus actual:** Submission/promotion should either publish work or expose a stable recoverable failure and release reservation/capacity. Both publication sites occur after state commits without compensation. An immediate duplicate can reuse the non-stale preparing job instead of dispatching. With defaults, the stalled backstop can take roughly 17-22 minutes absent an intervening recovery path.

**Evidence:** `app/backend/app/Services/Subtitles/SubtitleJobService.php:163-168,199-205`; `app/backend/app/Services/Subtitles/SubtitleJobAdmission.php:30-33,65-72`; `app/backend/config/subtitles.php:16,239,252`.

**Confidence:** High, code-supported; Redis outage was not induced.

**Smallest useful fix:** Route publication exceptions through run-scoped failure/settlement at both dispatch sites. Do not leave promoted work without either publication or a terminal outcome.

**Acceptance:** Inject publication failure. The reservation/slot releases, the UI sees a stable actionable failure, and a later retry dispatches once.

### R19. P2: Queue Admission And Timeout Decisions Can Be Stale Or Misordered

**Surface:** Duplicate submission, queued-job order, stalled-job recovery.

**Impact:** An identical accepted request can be reported as queue-full/minutes-exhausted; retry can overtake waiting work; a just-progressing job can be failed by the stalled sweep.

**Trigger:** Submit identical new work concurrently at the last slot/balance; retry an older job behind newer queued jobs; or advance progress between stalled selection and failure claim.

**Expected versus actual:** Duplicate reuse should precede new admission charging, FIFO should follow current submission time, and a fresh heartbeat should invalidate a timeout decision. Compatibility lookup occurs before the account lock, so the second duplicate can fail entitlement checks before reaching unique-row reuse. FIFO orders by persistent row ID although retry refreshes creation time. The timeout claim checks run/status but not the timestamp/stage used to decide expiry.

**Evidence:** `app/backend/app/Services/Subtitles/SubtitleJobService.php:108-111,147-183,297-317`; `app/backend/app/Services/Billing/BillingEntitlementService.php:30-33,55-75`; `app/backend/app/Services/Subtitles/SubtitleJobAdmission.php:46-50`; `app/backend/app/Console/Commands/FailStalledSubtitleJobs.php:32-46,66-72`; `app/backend/app/Services/Subtitles/SubtitleJobFailureHandler.php:41-50`.

**Confidence:** High, code-supported independent boundary defects grouped by queue control. No duplicate charge or false timeout was reproduced.

**Smallest useful fix:** Perform compatibility/admission under a consistent account lock; order FIFO by current submission timestamp with ID tie-breaker; condition/recheck timeout under a lock against the observed heartbeat/stage.

**Acceptance:** Concurrent identical requests return one job/reservation/dispatch at capacity; an earlier waiting submission precedes a later retry; a new heartbeat between selection and claim prevents failure. Lock tests require disposable Postgres.

### R20. P2: History Can Hide Work That Still Holds Capacity Or Minutes

**Surface:** Extension history, dashboard discovery, job support links.

**Impact:** Active work becomes invisible but continues consuming capacity; users lose their route to inspect/recover old failures.

**Trigger:** Let a queued job age beyond four hours during outage/backlog; inspect a newly failed job created more than four hours ago; deploy a processing-version change with older active jobs.

**Expected versus actual:** Active owned work should remain discoverable, and track compatibility should not erase support/billing history. API history applies a four-hour creation cutoff to queued/running/failed states. API/dashboard/details also filter processing versions, while older active work can still count against capacity. Old support links can become 404s.

**Evidence:** `app/backend/app/Http/Controllers/Api/SubtitleJobController.php:22-46,75-83`; `app/backend/app/Http/Controllers/DashboardController.php:75-80`; `app/backend/app/Http/Controllers/WebSubtitleJobController.php:29-34`; `app/backend/app/Services/Billing/BillingEntitlementService.php:55-58`.

**Confidence:** High, code-supported conditions; no long-running outage/deployment was exercised.

**Smallest useful fix:** Do not age-filter active work. Retain failures according to an explicit terminal-time policy. Separate permission to view owned status/billing metadata from permission to reuse/render an old track.

**Acceptance:** A five-hour-old queued job remains visible; a newly failed old job remains inspectable; old-version active work stays visible/cancelable without reusing incompatible tracks.

### R21. P2: API Timeouts Do Not Cover Response Body Consumption

**Surface:** Login/account/history/job requests and associated busy states.

**Impact:** A request can hold the UI or a polling single-flight operation beyond the advertised timeout.

**Trigger:** Return response headers promptly, then leave the JSON body stream unfinished.

**Expected versus actual:** Timeout should cover the complete usable response. The abort timer clears when `fetch()` resolves, before `response.json()` finishes.

**Evidence:** `app/extension/utils/api.ts:133-167`.

**Confidence:** High, code-supported. Existing timeout tests stall fetch, not body consumption.

**Smallest useful fix:** Keep the timeout active through body consumption/validation and clear it in the encompassing finally block.

**Acceptance:** A response backed by a never-ending stream times out within the configured budget and caller busy state clears.

### R22. P2: Local Timing And Player Selection Have Concrete Edge Cases

**Surface:** Delayed captions, cue navigation, Shorts/multiple video elements.

**Impact:** The first cue can appear before its configured delay or be skipped by Next; playback controls can bind to an offscreen video.

**Trigger:** Put cue one at source time zero with a +5-second offset and seek to playback time 2 seconds. Separately expose an offscreen playing video and an onscreen paused video, both with positive size.

**Expected versus actual:** No cue should be active before delayed start, and controls should use the visible player. Playback-to-source conversion clamps negative time to zero, creating a match for cue one. Video selection treats positive width/height as visibility and can prefer an offscreen playing element.

**Evidence:** `app/extension/utils/cue-navigation.ts:14-19,85-103`; `app/extension/entrypoints/content.ts:553-567,641-650`; `app/extension/utils/youtube-video.ts:1-18`.

**Confidence:** High for algorithmic conditions; real Shorts incidence was not tested.

**Smallest useful fix:** Preserve negative inverse source time, while retaining destination clamping for actual seeks. Prefer the active player or require viewport intersection/visibility before playback preference.

**Acceptance:** Before 5 seconds, active lookup returns null and Next selects cue one. After 5 seconds it becomes active. An onscreen paused player wins over an offscreen playing element.

### R23. P2: Overlay Accessibility And Popover Placement Have Recovery Gaps

**Surface:** Top-position word cards and editable-page shortcut handling.

**Impact:** Card content/close controls can be outside the viewport, and shortcuts can intercept typing in valid editable surfaces.

**Trigger:** Use top position with a tall card or edge token above the mobile breakpoint. Focus `contenteditable=""` or `contenteditable="plaintext-only"` and invoke a shortcut chord.

**Expected versus actual:** Cards should remain readable/dismissible and editing should not trigger playback actions. Standard cards open above/centered on the token without top-position flip, viewport clamp, or height scrolling. Editable checks recognize only the explicit `contenteditable="true"` form rather than native editability.

**Evidence:** `app/extension/utils/overlay/overlay-styles.ts:17-20,306-330,384-437,608-615`; `app/extension/utils/keyboard-shortcuts.ts:114-160`; `app/extension/entrypoints/content.ts:189-200`.

**Confidence:** High for missing layout constraints and editable forms; clipping and keyboard behavior were not browser-measured.

**Smallest useful fix:** Flip top-mode cards below tokens, constrain height/width and permit card scrolling. Use native `isContentEditable` while retaining composed-path/non-native textbox handling. No positioning library is necessary.

**Acceptance:** Long cards at top/bottom/compact positions, first/last token, zoom, and narrow widths retain reachable close controls. All valid editable forms and their descendants ignore extension shortcuts.

### R24. P1: Password Recovery May Leave Existing Web Sessions Authorized

**Execution status (2026-09-07): Excluded by user.** Retain this baseline observation for traceability; do not implement password/session-recovery changes under the audit authorization. Existing security is not removed.

**Surface:** Web account recovery and protected account actions.

**Impact:** Resetting a compromised password may not remove another browser's access to the dashboard and account actions.

**Trigger:** Establish two test web sessions, then reset the password while one remains authenticated.

**Expected versus actual:** Password recovery should revoke the other session's protected access. Reset changes the password/remember token and revokes extension tokens, but does not explicitly revoke web sessions. Protected routes use auth/verified without authenticated-session password-hash invalidation middleware in the inspected configuration.

**Evidence:** `app/backend/app/Actions/Fortify/ResetUserPassword.php:30-37`; `app/backend/routes/web.php:38-61`; `app/backend/bootstrap/app.php:31-42`.

**Confidence:** Medium-high, code-supported; framework behavior was not executed because backend dependencies were absent. Treat as a targeted security/recovery verification item, not a reproduced session compromise.

**Smallest useful fix:** Apply Laravel's authenticated-session invalidation behavior or explicitly revoke persisted web sessions during recovery, using the existing session driver.

**Acceptance:** After reset, the other independent session cannot open the dashboard or perform protected actions. Existing extension-token revocation remains effective.

## Optional Usability Improvements

These should follow core state/lifecycle fixes. They are bounded changes, not a redesign or new learning feature set.

### U1. P2: Describe Queued Work As Waiting, Not Active Generation

**Surface/impact:** Watch progress can imply active processing while the job waits for an account slot.

**Trigger and actual/expected:** Return a queued job. History/state know it is waiting, but Watch builds a timeline with running status and "Active now" language. It should show a waiting state and explain admission without inventing a completion ETA.

**Evidence/confidence:** `app/extension/entrypoints/sidepanel/main.ts:595-606`; `app/extension/utils/panel-progress.ts:21-26`. High, source-inspected; not browser-reproduced.

**Smallest fix/acceptance:** Preserve queued status/message in the view model. A queued fixture reads "Queued"/"Waiting for a generation slot," and switches to processing only after admission.

### U2. P2: Distinguish Estimated Duration From Charged Minutes

**Surface/impact:** Failed-job details can imply a charge even when the reservation was refunded.

**Trigger and actual/expected:** Inspect an acquisition failure with unknown duration. The view labels a duration-derived value as "Billable minutes" and unknown duration becomes one minute. It should distinguish unknown/estimated video minutes from actual reserved, charged, and released usage.

**Evidence/confidence:** `app/backend/app/Http/Controllers/WebSubtitleJobController.php:36-40`; `app/backend/resources/views/account/job-show.blade.php:33-38`; `app/backend/app/Services/Billing/UsageLedger.php:272-276`. High, source-inspected.

**Smallest fix/acceptance:** Rename the estimate, show unknown when appropriate, and use existing ledger data for settlement wording. A refunded failure clearly shows no charge and does not claim a measured minute.

### U3. P2: Keep Last Known History On A Refresh Failure

**Surface/impact:** A temporary API failure can replace real job history with first-use empty copy.

**Trigger and actual/expected:** Return successful history, then time out the next GET. Current handling empties the cache and can show "Nothing generated yet" alongside the error. Preserve the last successful same-session list with a stale/error indication instead.

**Evidence/confidence:** `app/extension/entrypoints/background.ts:669-674,712-716`; `app/extension/entrypoints/sidepanel/render/job-history.ts:9-18`. High, source-inspected.

**Smallest fix/acceptance:** Retain session-scoped successful history and expose refresh failure separately. Offline history remains useful but cannot leak across the account transition in R8.

### U4. P3: Make Password Rules And Connection Expiry Explicit

**Follow-up scope (2026-09-07): Connection-expiry fix only; password portion excluded.** Dashboard filters per-token and Sanctum-wide expiration before taking three connections; expired-only accounts show no connected installs. Our registration/reset hints, minlength, and associated test were withdrawn under the broader password exclusion. Existing backend password enforcement remains unchanged.

**Surface/impact:** Registration/reset requires avoidable re-entry; dashboard connection labels can disagree with an expired extension session.

**Trigger and actual/expected:** Submit a password without the server's required ten characters/letters/numbers; inspect an account whose extension tokens have expired. Password requirements currently arrive after submission, and connection listing does not filter expiry before its limit. Requirements and active/expired connection state should be explicit.

**Evidence/confidence:** `app/backend/app/Actions/Fortify/PasswordValidationRules.php:15-17`; `app/backend/resources/views/auth/register.blade.php:29-34`; `app/backend/resources/views/auth/reset-password.blade.php:17-22`; `app/backend/app/Http/Controllers/DashboardController.php:96-108`; `app/backend/resources/views/dashboard.blade.php:158-168`. High, source-inspected.

**Smallest fix/acceptance:** Show existing rules before submission with `minlength`, retaining server checks; filter or label expired tokens. Requirements are available to assistive technology, and expired-only accounts are not described as actively connected.

### U5. P3: Consider Making Existing Partial Results Readable In Watch

**Surface/impact:** The overlay can use partial cues, but the side-panel transcript remains unavailable until final completion.

**Trigger and actual/expected:** Supply a running job with partial cues. Watch stays in its progress-only state. An optional improvement is to permit reading those existing cues with a clear "Still generating" label while progress remains visible.

**Evidence/confidence:** `app/extension/entrypoints/sidepanel/main.ts:521-525,583-603`; `app/extension/utils/backend-subtitle-state.ts:55-65`. High for the current ready-only design; this is not a violation of a promised partial panel transcript.

**Smallest fix/acceptance:** Only after partial-data preservation in R3 is reliable, render existing partial source cues read-only without adding editing or partial word-card enrichment. Updating partial revisions must not reset reading position, and final completion must preserve continuity.

## Completed Tasks And Testing

Keep one section here for each completed task: what changed, manual steps and expected results, automated validation, and remaining limits. User browser testing is deferred until the implementation batches are finished; continue automated checks for every batch. Keep audit implementation in the isolated smoothening worktree, separate from the lyrics branch.

### G3: Manual Status Refresh

**What changed:** Dashboard and job details have native GET "Refresh status" links, timestamped snapshot labels, and explicit notice that they do not update automatically. Checkout-return copy directs users to refresh.

**How to test:**

1. Serve the smoothening worktree with a disposable persistent local database and test account. Its current test-only in-memory `.env` is not ready for an interactive session. Do not use production billing or paid generation to create fixtures.
2. Open the dashboard and a job detail page. Both should show a snapshot timestamp, non-updating notice, and Refresh status link.
3. Change a fixture job's status/progress or the account's reserved minutes in the local test data. The open page should remain unchanged until Refresh status is clicked, then display the updated values.
4. Open `/dashboard?billing=success`. Confirm the message explains refreshing; clicking Refresh status should navigate to `/dashboard` without replaying checkout or any deletion.
5. Repeat on desktop and mobile widths; confirm the refresh controls remain reachable.

**Automated validation:** The dashboard/detail refresh regression tests passed in `SaasWebsiteAndSeoTest`.

**Remaining limits:** G3 is partially mitigated, not live synchronization. Browser testing is pending.

### G4: Job Identity And Deletion Scope

**What changed:** Jobs show stored video IDs with canonical YouTube links. Clear all shows the full owned-job count, including jobs outside the eight-row recent list and older processing versions. Confirmations identify targets and explain track removal, lost reuse, completed-usage non-refund, and limitations for provider requests already running.

**How to test:**

1. Use disposable local fixtures with more than eight owned jobs, at least one old-version job, and a second account's job. Confirm only eight recent jobs appear, but Clear all counts every owned job and none from the second account.
2. Follow a source video link from a row and job details. It should point to YouTube using that job's displayed video ID.
3. Click a row's Delete. Confirm the prompt names its job and video and explains consequences. Cancel first and confirm nothing is deleted.
4. Confirm deletion for a disposable completed job with a stored track. The job and track should disappear; completed usage should not be refunded.
5. Click Clear all. Confirm the prompt includes hidden/old-version jobs and the full scope. Cancel first; then confirm against disposable fixtures only. All owned jobs should be removed while the other account's jobs remain.
6. With only old-version owned jobs, confirm Clear all remains available despite an empty recent list. With no owned jobs, it should be absent.
7. Check the wider table and controls on mobile and with keyboard navigation.

**Automated validation:** Both focused suites passed: 29 tests, 242 assertions. Run from `app/backend`:

```powershell
php artisan test --compact --filter="SaasWebsiteAndSeoTest|WebSubtitleJobDeletionTest"
```

**Remaining limits:** Browser testing is pending. Deletion/credit concurrency risk R16 is unchanged; this task clarifies existing behavior rather than fixing settlement races.

### G2: Honest History Navigation

**What Changed:** Failed History cards no longer have a Retry button. Their native Open video links cannot submit generation, regardless of the currently displayed tab. Cards instruct users to review Watch settings and explicitly say the saved job options are not restored. The old History click handler was deleted, not retained as a hidden retry path.

**How to Test (deferred):**

1. After all batches, build/load this worktree's extension against a disposable local backend with persistent fixture storage and fake providers. The current in-memory PHPUnit `.env` is not an interactive runtime profile. Seed two failed jobs with different videos/languages/layers; do not use paid generation to create failures.
2. Change global generation defaults. Open History while viewing one failed video's tab, then repeat on another video. Each card should show Open video, no Retry, and the warning about reviewing settings.
3. Activate each Open video link with pointer and keyboard. It should open that card's YouTube URL in a new tab and make no subtitle-job POST. Defaults should not be silently replaced by historical options.
4. To generate again, explicitly review the Watch language/layer controls first. Only press Generate with the local fake provider configured. This is a new explicit request using reviewed settings, not a guaranteed retry of that history job.

**Automated Evidence:** `npm test -- tests/job-history-render.test.ts tests/account-state.test.ts tests/api.test.ts` from `app/extension`: 27 tests passed after the G5 scope correction. History cases cover same/different active video, distinct historical options, native link targets, absence of action buttons/forms, and review copy. See the latest validation entry below for full-harness results.

**Remaining Limits:** G2 is partial: selected-history options are not restored or submitted. R1/R2/R9 and backend retry integrity are not fixed here. No browser journey was executed.

### G5: Account And Billing (Partial)

**What Changed:** Account retains only the Account and billing link using `WXT_BACKEND_API_BASE_URL`'s origin, with no extension credentials or email in the URL. Feature rows show selected preferences rather than entitlement promises; billing/usage/feature denials point to billing or Watch selections. The new password-recovery/verification links, added auth-error directions, verification inbox identity and account-switch form, and their added test expectations were removed at the user's request. The verification view was restored to its baseline. No pre-existing recovery routes, verification enforcement, or security were removed. The subsequent broader password exclusion also withdraws U4 password hints/minlength and their test.

**How to Test (deferred):**

1. After all batches, use an isolated browser profile, disposable persistent local backend, and fixture account. Build the extension with that backend origin. The current in-memory PHPUnit environment is not an interactive runtime profile; do not use production billing or paid generation.
2. Open Account signed out and signed in. Expect Account and billing but no newly added Forgot password or Verify email links. Follow the billing link: expect `/dashboard` on the configured origin, never under `/v1`, with no token/email query parameters. Check keyboard access and 320px width.
3. Use fake generation denials for billing/usage/feature restrictions. Expect an Account and billing or Watch-selection next step. Bad-credential and unverified-email error messages should retain baseline wording, without the withdrawn directions.
4. Inspect Account with a signed-in but non-entitled fixture. Expect Selected/Not selected preferences, access checked on generation, actual backend queue-speed copy, and no disabled Upgrade or unconditional Enabled/Included claims.

**Automated Evidence:** Account/API Vitest tests now expect only the dashboard link, no credential query, honest preferences, baseline verification-error wording, and the three retained billing/feature denial directions. Focused extension: 27 tests passed. `php artisan test --compact tests/Feature/WebAuthTest.php tests/Feature/SaasWebsiteAndSeoTest.php` from `app/backend`: 21 tests, 220 assertions passed. The added verification-switch test was removed; pre-existing registration, verification, login/logout, and password-reset tests remain. `git diff` confirms no changes to the verification view, auth routes, or Fortify actions.

**Remaining Limits:** G5 is partial, not fixed. Recovery/verification UX additions are intentionally withdrawn, not scheduled for reimplementation. Website/extension sessions remain separate; browser testing, R8/R12, and R24 are unchanged.

### U4: Connection Expiry (Password Portion Excluded)

**What Changed:** Dashboard excludes tokens expired by explicit expiry or configured Sanctum age before limiting the list, so newer expired tokens cannot hide older valid ones. No tokens are revoked or modified by viewing the page. Password-rule hints/minlength and their added test are withdrawn; register/reset forms and backend password enforcement are baseline behavior, not part of this fix.

**How to Test (deferred):**

1. Use disposable local account/token fixtures, not production accounts. Seed one valid older extension token and at least four newer expired tokens. Refresh the dashboard: the valid token should remain visible, and expired tokens should not consume the three-item limit. Other accounts' tokens and non-extension tokens must stay hidden.
2. Check a future-expiry token older than `sanctum.expiration`, and a recent token with no explicit expiry. The first should be hidden; the second should appear. With global expiry disabled, only explicit expiry governs filtering.
3. Expire every owned extension token and refresh. Expect No connected extension installs, not a connected count. Refresh itself must not revoke/delete tokens.

**Automated Evidence:** SaasWebsiteAndSeoTest checks pre-limit expiry filtering, owner/type scope, explicit boundary expiry, global expiry, null expiry, disabled global expiry, and expired-only state. Historical totals below predate withdrawal of the password-hints test; foundation validation will record the current totals.

**Remaining Limits:** Connection listing remains a three-token snapshot, not proof that a browser is currently online. Browser and assistive-technology testing are deferred. U1 was not included: queued-state propagation needs a separate shared-flow change.

## Tested And Inspected

### G2/G5/U4 Follow-Up Validation (2026-09-07)

The results in this subsection precede the user-requested recovery/verification UX withdrawal. Current task sections above describe the retained scope; updated validation follows below.

- Plan: `docs/exec-plans/completed/2026-09-06-g2-g5-history-and-account-recovery.md`.
- Existing dependencies and test-only environment reused; no installs/upgrades, real-data migrations, production/provider requests, browser work, or commits. Prior G3/G4 work preserved. Worker delegation unavailable in this session.
- Focused extension: **29 passed across 3 files**. Focused backend: **22 passed, 238 assertions**. Initial backend run had one failing expiry fixture because `created_at` was mass-assignment protected; changed only the fixture setup to `forceFill`, then all passed.
- `php vendor/bin/pint --dirty --format agent`: **passed**. `npm run compile`: **passed**.
- `scripts/agent/check.ps1`: every component **passed**: docs lint; contracts schema/OpenAPI validation and type generation; backend **329 tests/2655 assertions**; extension **159 tests/26 files**; TypeScript; Chrome MV3 build. These results do not establish browser reproduction of other audit findings.

### Recovery/Verification UX Withdrawal Validation (2026-09-07)

- Removed only the batch's new password/verification UX at user request; retained G2/G3/G4, account/billing navigation, honest preferences, and U4. No pre-existing auth routes, enforcement, security, or recovery functionality removed. Verification view has no baseline diff.
- `npm test -- tests/account-state.test.ts tests/api.test.ts tests/job-history-render.test.ts`: **27 tests passed, 3 files**. `php artisan test --compact tests/Feature/WebAuthTest.php tests/Feature/SaasWebsiteAndSeoTest.php`: **21 tests passed, 220 assertions**.
- `php vendor/bin/pint --dirty --format agent`: **passed**. `git diff --check`: **passed**, line-ending warnings only.
- `scripts/agent/check.ps1`: **all components passed**: docs lint, contract validation/type generation, backend **328 tests/2637 assertions**, extension **157 tests/26 files**, TypeScript compile, and Chrome MV3 build. No test failures on this correction pass.
- No browser, commits, provider/production requests, dependency changes, or original-checkout edits. G5 remains partial; its withdrawn UX is intentionally excluded, not awaiting reimplementation.

### G3/G4 Follow-Up Validation (2026-09-06)

- Plan: `docs/exec-plans/completed/2026-09-06-g3-g4-dashboard-status-and-deletion-clarity.md`.
- Installed only existing lockfile dependencies locally: Composer (135 installs, zero updates, scripts/plugins disabled, local cache/home) and contracts `npm ci` (27 packages, local cache). Lockfiles unchanged. Existing extension dependencies reused.
- Created a new ignored worktree-local `.env` with a dummy test key, SQLite `:memory:`, array cache/session/mail, sync queue, local filesystem, no provider credentials, and worker auto-start disabled. No secrets or environment/config cache copied; no runtime migrations or production services used.
- `php artisan test --compact tests/Feature/SaasWebsiteAndSeoTest.php tests/Feature/WebSubtitleJobDeletionTest.php`: **29 passed, 242 assertions**. Covers refreshed billing/reservation and job completion fixtures without outbound requests/queue work, canonical video links, owner-scoped full counts, old-version-only/empty lists, confirmation copy, and completed-track deletion preserving charged usage.
- `php vendor/bin/pint --dirty --format agent`: **passed**.
- `scripts/agent/check.ps1`: **all component checks passed**: docs lint; contracts schema/OpenAPI validation and type generation; backend **326 tests, 2613 assertions**; extension **26 files, 148 tests**; TypeScript compile; Chrome MV3 production build. Unlike the original audit run below, no component failed.
- No browser, screenshots, live Stripe webhook, real provider generation, concurrency test, deployment, or commit. Existing wrapping actions and horizontally scrollable table styles were reused, not visually measured. R16 remains unaddressed; G3 is manual recovery rather than live status.

### Commands Actually Executed

The following table records the original read-only audit, before the G3/G4 follow-up.

| Check | Result | Scope/qualification |
| --- | --- | --- |
| Git branch/worktree verification | Confirmed `smoothening` at `4969225` in the separate worktree | Original lyrics-editing work was left untouched. |
| Extension `npm ci` | Passed | Installed audit-local dependencies and ran WXT prepare. No dependency upgrades/fixes were applied. |
| Extension `npm test` | 26 test files, 148 tests passed | Existing Vitest suite; not end-to-end app validation. |
| Extension `npm run compile` | Passed | TypeScript validation. |
| Extension `npm run build` | Passed | Chrome MV3 production build. |
| `scripts/agent/check.ps1` | Not fully successful | Docs lint passed; extension tests/typecheck/build passed again. Contracts and backend checks failed before their suites could run because dependencies were absent. |
| Source-equivalent JavaScript startup-order check | Demonstrated lexical initialization error | Not the actual panel entrypoint or production bundle. |
| `agent-browser` launch with built extension | Browser reached `chrome://extensions/` | No side-panel, YouTube, or account app flow was exercised. |

The harness printed "checks completed" despite native command failures. This is not a green full-repository result. Contracts could not resolve `@apidevtools/swagger-parser`; backend Artisan could not load `vendor/autoload.php`.

Extension installation also reported dependency-audit advisories. They were not triaged as part of this UX audit, and no audit-fix command was run. They should not be interpreted here as established exploitable application defects.

### Existing Tests Inspected

- `app/extension/tests/backend-subtitle-state.test.ts`: completed/running/queued/failed recovery helpers. Missing exact-operation reconciliation and recovery from local transport error.
- `app/extension/tests/panel-tab-sync.test.ts`: query/ordering helpers. Does not import the real panel entrypoint or prove tab routing.
- `app/extension/tests/transcript-view.test.ts`: JSDOM controller behavior and DOM stability. Missing same-ID changed-content, panel reconnect, and end-to-end seek relay.
- `app/extension/tests/api.test.ts`: fetch timeout behavior. Missing stalled body consumption.
- `app/extension/tests/account-session.test.ts`: storage expiry/removal. Missing full logout/reset and cross-session response races.
- `app/extension/tests/overlay.test.ts`: rendering strings, partial/blur/status/card markup. Does not mount OverlayShell or test focus, pointer ownership, clipping, or fullscreen.
- `app/extension/tests/webvtt-track.test.ts`: fake-track attach/cuechange/cleanup/offset behavior. Does not exercise YouTube player replacement or browser VTT failures.
- `app/extension/tests/cue-hold.test.ts`, `cue-navigation.test.ts`, `youtube-video.test.ts`, `keyboard-shortcuts.test.ts`: local algorithm checks. Missing interaction combinations, delayed-cue preroll, actual viewport visibility, and all editable forms.
- Backend `SubtitleJobApiTest.php`: sequential reuse, stale queued/provider paths, partial tracks, history, and absent cancellation route. These tests were inspected, not run.
- Backend `WebSubtitleJobDeletionTest.php`, `SubtitleJobFailureHandlerTest.php`, `SubtitleJobArtifactRunScopingTest.php`: ordinary deletion/refunds and sequential stale/duplicate guards. Missing the interleavings in R16-R17.
- Backend `BillingAndUsageTest.php`, `FailStalledSubtitleJobsTest.php`: normal reservations/admission/FIFO/static stalled rows. Missing concurrent identical admission, retry ordering, publication failure, and a heartbeat between selection/claim.
- Backend `WebAuthTest.php`, `ExtensionAuthApiTest.php`, `SaasWebsiteAndSeoTest.php`: auth/extension-token lifecycle and server-rendered content. They do not prove live dashboard refresh, verification account switching, or password-reset web-session revocation.

### Coverage Gaps And Safety Limits

- Browser checking was stopped at the user's request before any application flow was exercised. There are no repro screenshots, videos, browser console findings from the app, or visual measurements.
- No real YouTube Home/watch/Shorts SPA journey, fullscreen/theater transition, player replacement, native side-panel reopen, or multi-window control path was tested.
- No signed-in account/dashboard runtime session was established. No recovery email, billing action, deletion, cancellation, generation, or provider call was triggered.
- Backend dependencies and runtime configuration were absent in the isolated worktree. Backend tests were inspected but not run. No existing shared backend was started or modified.
- No queue outage, interrupted provider run, concurrent database transaction, credit settlement race, or delayed billing webhook was executed.
- Narrow layout at the documented 320px floor, zoom, contrast ratios, screen readers, keyboard focus, and long-card geometry were source-inspected only. There is no evidence supporting a contrast-compliance claim or a measured contrast defect.
- A future local backend run must use isolated dependencies, storage, test credentials, fake providers/mail/billing, and no production environment/config cache. SQLite in-memory tests cover many normal paths; actual lock races require disposable Postgres.
- Do not run shared runtime launchers against the concurrent development environment as an audit shortcut. This repository's runtime tools use shared ports/resources and worker discovery, so isolation must be explicit.

## Targeted Manual Reproduction Order

Use a test account, seeded tracks/jobs, fake generation providers, and a separate browser profile. None of these steps authorizes paid or production operations.

1. Open the real side panel in both WXT dev and the built extension. Check startup console, tab listeners, port connection, and an initial/updated snapshot before investigating downstream state.
2. With existing tracks, enter watch/Shorts from Home and search, navigate between videos, close/reopen while paused, and switch normal/theater/fullscreen. Identify whether the missing element is the rail, the transcript view, or the native panel.
3. Seed old completed/failed variants and defer a new submission response. Confirm Watch follows the new operation, not cached history. Repeat a rapid double-click and immediate option changes.
4. While a fake job runs, fail one poll, restore the connection, expire/reestablish the session, and restart background monitoring. Confirm completion appears without another submission.
5. Open two windows with different videos and duplicate copies of one video. Exercise Jump/replay and background playback; verify cue and seek ownership.
6. Delay enrichment/hydration across navigation and account requests across logout/login. Resolve successes and failures in both orders.
7. Study by keyboard and pointer through word-card opening, async completion, close/Escape, cue hold, overlay hide, and timing changes. Repeat with partial cues and all blur settings enabled.
8. Use isolated backend tests to interleave fail/retry/delete, queue dispatch failure, stale merge continuation, and timeout heartbeat. Inspect reservations and artifacts per run.
9. Review dashboard pending/completed/refunded states and deletion confirmations with more than eight seeded jobs. Test verification account switching and password-reset web-session invalidation without production mail/billing.

## Ordered Implementation Backlog

### Quick Wins

1. **Make initialization reliable:** Move poll declarations before startup and add one actual-entrypoint smoke test. R1. Verify both dev and production before assuming the rest of the panel can be exercised.
2. **Make labels/actions honest:** Fix Retry semantics or label, queued wording, safe status refresh, and estimated-versus-charged minutes. G2-G3, U1-U2.
3. **Account/billing clarity:** Retain dashboard navigation, honest preferences, and accurate connection expiry. G5 password/verification and U4 password-hint portions are excluded and must not be reintroduced. Only non-password subsets are packaged.
4. **Make deletion informed:** Add source-video links, affected count, and track/refund consequences. G4. This is copy/identity work, not a substitute for the lifecycle integrity fixes below.
5. **Fix bounded local defects:** Extend timeout through response bodies, make partial blur revealable, preserve negative inverse timing, use native editable detection, and add stable overlay focus keys. R13-R14, R21-R23. Keep each covered by a focused regression check.

### Larger Shared-Root-Cause Work

6. **Unify operation-aware synchronization and recovery:** Guard submitting/job/session identity, preserve interrupted jobs/partials, restart recovered monitoring, prevent duplicate submissions and preference lost updates, and separate action feedback from snapshot ordering. R2-R3, R8-R9, R12, R15, U3. This is the main stale-UI workstream; reuse current state/functions rather than introduce a generic state framework.
7. **Repair content and panel lifecycle:** Broad YouTube injection with route gating, live binding/host checks, stale-response rejection, player/fullscreen containment, tab-scoped messages, and current-cue snapshots/relay. R4-R7, R11. Add one browser journey covering Home to watch, player replacement, duplicate-video tabs, and reopen while paused.
8. **Make terminal work and credits run-safe, then ship cancellation:** Address atomic settlement/deletion, immutable continuation identity, publication failure, and stale admission/timeout decisions. Then expose cancellation with explicit queued/running/late-result/credit semantics. R16-R19, G1. Use disposable Postgres interleaving tests, not only sequential SQLite assertions.
9. **Preserve operational history:** Keep active/old-version job status discoverable. R20. Compatibility should govern track reuse, not erase owned support/billing history. R24 is explicitly excluded under the password-work restriction.
10. **Finish learning continuity:** Merge enrichment into current track data, invalidate transcript rendering on real content changes, preserve focus/pause ownership, and keep local binding errors/action feedback actionable. R10, R13-R14, R22-R23. Consider the optional partial Watch transcript only after these paths are stable. U5.

Do not fold lyrics editing, vocabulary systems, new provider integrations, cosmetic redesign, or unrelated abstractions into these fixes. The release criterion is that learners can complete, stop, recover, navigate, and study without refreshing, guessing, or losing their place.
