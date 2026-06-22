# Plan: Extension & backend hardening — tab-switch responsiveness, panel perf, backend correctness

Status: active
Owner: agent (handoff to implementer)
Created: 2026-06-21
Last updated: 2026-06-21

## How to use this document

This is a **single, self-contained work order** for one implementer working on **one
branch**. It bundles three things:

1. **Item 1 — the headline fix:** the side panel is slow/stale on tab switches (and a
   latent multi-window correctness bug behind it).
2. **Items 2–7 — extension performance & robustness** found while auditing the rest of the
   code (transcript re-render/scroll hijack, missing request timeouts, always-on polling,
   service-worker wakeups, out-of-order responses, SW eviction).
3. **Items 8–11 — backend correctness & scale** (failJob race, concurrency counter leak,
   Stripe event ordering, bulk-delete transaction).

Each item is **independently shippable** and has its own problem statement, root cause,
detailed implementation, edge cases, and tests. Where items interact, the dependency is
called out. Do them in the **suggested commit order** at the bottom.

Branch & review workflow:
- Do all work on a single feature branch off `main` (suggested name
  `feature/extension-backend-hardening`). Reference each commit to its item number below.
- Keep commits small and per-item so review can map findings → diffs.
- `app/extension` and `app/backend` validation must pass (commands in §Validation).
- When done, the branch will be reviewed against this document item-by-item.

Severity / confidence table (triage at a glance):

| # | Item | Area | Severity | Confidence |
|---|------|------|----------|------------|
| 1 | Tab-switch staleness + multi-window correctness | Extension | **High** | High |
| 2 | Transcript panel full-rebuild + scroll hijack | Extension UX/perf | **High** | High |
| 3 | Missing request timeouts (only history is bounded) | Extension robustness | **High** | High |
| 4 | Panel backend poll runs forever, even when hidden | Extension perf/cost | Medium | High |
| 5 | `activeCueChanged` wakes the SW every cue boundary | Extension perf | Medium | High |
| 6 | Out-of-order panel responses clobber newer state | Extension correctness | Medium | High |
| 7 | SW eviction abandons in-memory generation poll loop | Extension robustness | Low | Medium |
| 8 | `failJob` non-atomic read-then-write | Backend race | Low–Med | Medium |
| 9 | Per-user batch concurrency counter leak / sliding TTL | Backend perf | Medium | Medium |
| 10 | Stripe subscription events applied without ordering guard | Backend billing | Medium | Medium |
| 11 | `clearAll` loads every job into one long transaction | Backend scale | Low | High |

> Item 6 is implemented *inside* Item 1 (it's the "request sequencing" piece). Items 3 and 4
> reinforce each other — doing 4 shrinks the blast radius of 3.

All file paths are relative to the repo root. Extension code lives under `app/extension/`,
backend under `app/backend/`.

---

# Item 1 — Snappy side-panel state on tab switches (+ multi-window correctness)

## Problem

The Chrome side panel (and Firefox sidebar) is **per-window and persistent** — it does
**not** reload when you switch tabs inside a window. But the panel only re-reads state on
(a) first open, (b) a user action, and (c) a 10-second backend poll. There is **no listener
for tab activation or in-tab navigation** (confirmed: the only `browser.tabs.query` in the
whole extension is `getActiveTab()` in the background). So after switching tabs, the panel
keeps rendering the *previous* tab's "Now playing", transcript, progress, and generate
button until the next 10 s poll happens to read the new active tab — exactly the reported
"tab 2 shows tab 1's generation info for a bit before it resets."

The same staleness hits **in-tab navigation** (video A → related video C in the same tab):
the content overlay clears immediately (it listens to YouTube route events,
`content.ts:47-51`), but the panel stays on A until the poll.

### Root cause (where)

- `entrypoints/sidepanel/main.ts:165-166` — state is only pulled at startup and on a 10 s
  `setInterval`.
- No tab/window event listeners anywhere.
- `entrypoints/background.ts:735-742` — `getActiveTab()` resolves the active tab with
  `browser.tabs.query({ active: true, currentWindow: true })`. From an MV3 service worker
  there is no "current window," so `currentWindow` resolves to the last-focused window.
  With **two browser windows**, the panel in the unfocused window is fed the focused
  window's active tab. Every panel-originated action that resolves "the active tab"
  inherits this bug: `getPanelState` (`:500`), `generateSubtitlesFromPanel` (`:181`),
  `updateSettingsFromPanel` (`:150`), `clearLocalStateFromPanel` (`:447`), `tabIdForVideo`
  fallback (`:691`).

## Solution overview

Make the panel **event-driven** and **window-scoped**:

- **A. Window identity:** the panel discovers its own `windowId` and sends it with every
  panel request; the background resolves the active tab of *that* window. Fixes multi-window
  correctness and makes every refresh target the right tab.
- **B. Event-driven refresh:** the panel listens to `tabs.onActivated` / `tabs.onUpdated`
  filtered to its own window; on change it does a fast local pull then a backend sync.
- **C. Request sequencing (= Item 6):** ignore out-of-order responses so rapid switching
  never lands on an intermediate tab.
- **D. (Optional) Optimistic neutralize:** blank tab-specific UI on change to kill the
  sub-100 ms flash — only if measurement shows it's needed.

Panel-side listeners (not a background broadcast) are chosen because the panel already knows
its window, the listeners auto-clean when the panel closes, and the path is one hop shorter.

## Implementation

### A. Thread `windowId` through panel requests; scope the background query

**A1. `utils/messages.ts`** — add optional `windowId?: number` to the panel-originated
request variants that resolve "the active tab": `panel.getState`, `panel.generateSubtitles`,
`panel.updateSettings`, `panel.clearLocalState`, `panel.seekToCue`. Example:

```ts
| { type: 'panel.getState'; syncBackend?: boolean; windowId?: number }
```

Update `isRuntimeMessage`: add an `optionalNumber(value, key)` helper (sibling of the
existing `optionalBoolean`) and assert `optionalNumber(value, 'windowId')` for each affected
`panel.*` case. Keep it permissive (absent = valid) so old/queued messages still validate.

**A2. `entrypoints/background.ts`** — make `getActiveTab` window-aware (`:735`):

```ts
async function getActiveTab(windowId?: number): Promise<Browser.tabs.Tab | undefined> {
  const query: Browser.tabs.QueryInfo = typeof windowId === 'number'
    ? { active: true, windowId }
    : { active: true, currentWindow: true }; // back-compat fallback
  const [activeTab] = await browser.tabs.query(query);
  return activeTab;
}
```

**A3.** Thread `message.windowId` from `handleRuntimeMessage` (`:77`) into the handlers that
call `getActiveTab`: `getPanelState({ syncBackend, windowId })`,
`generateSubtitlesFromPanel(windowId)`, `updateSettingsFromPanel(patch, windowId)`,
`clearLocalStateFromPanel(windowId)`, and `tabIdForVideo(videoId, windowId)` (whose fallback
`getActiveTab()` at `:691` becomes `getActiveTab(windowId)`). Keep every `windowId`
parameter optional so a request without it degrades to today's behavior. `getContentState`
uses `sender.tab?.id` and is unaffected.

### B. Panel discovers its window and listens for tab changes

**`entrypoints/sidepanel/main.ts`** — resolve the window once, attach listeners, debounce:

```ts
let panelWindowId: number | undefined;
let lastActiveTabId: number | undefined;
let tabChangeTimer: ReturnType<typeof setTimeout> | undefined;

async function resolvePanelWindowId(): Promise<void> {
  try {
    const win = await browser.windows.getCurrent();
    panelWindowId = typeof win.id === 'number' ? win.id : undefined;
  } catch {
    panelWindowId = undefined; // background falls back to currentWindow:true
  }
}

function scheduleTabChangeRefresh(): void {
  if (tabChangeTimer) clearTimeout(tabChangeTimer);
  tabChangeTimer = setTimeout(() => void onActiveTabChanged(), 60); // coalesce rapid switches
}

async function onActiveTabChanged(): Promise<void> {
  await sendPanelRequest({ type: 'panel.getState', syncBackend: false }); // fast, no network
  void refreshBackendState();                                            // then backend sync
}

browser.tabs.onActivated.addListener((info) => {
  if (panelWindowId !== undefined && info.windowId !== panelWindowId) return;
  lastActiveTabId = info.tabId;
  scheduleTabChangeRefresh();
});

browser.tabs.onUpdated.addListener((_tabId, changeInfo, tab) => {
  if (panelWindowId !== undefined && tab.windowId !== panelWindowId) return;
  if (!tab.active) return;
  if (!changeInfo.url) return; // only react to real navigation (incl. YouTube SPA pushState)
  scheduleTabChangeRefresh();
});
```

Attach `windowId` to outgoing requests inside `sendPanelRequest` (`:250`) — merge
`windowId: panelWindowId` into `request` when defined (all requests from this file are
`PanelRequest`, so attaching unconditionally is safe; the background ignores it where
unused).

Bootstrap ordering: the existing synchronous setup (`setupTabs`, `bindTranscriptView`, etc.)
must still run on load. Kick `resolvePanelWindowId()` immediately and attach the tab-event
listeners after it resolves; don't block the first paint on it (if the window id is slow,
`loadPanelState()` can still fire and the background falls back). Practically: call
`resolvePanelWindowId().then(attachTabListeners)` while `loadPanelState()` runs in parallel.

Permissions: **do not** add the `tabs` permission. `tabs.onActivated` needs none;
`tabs.onUpdated.changeInfo.url` is visible because the manifest already has youtube
`host_permissions` (`wxt.config.ts:14`). If `onUpdated.url` ever proves unreliable for SPA
nav, the fallback is `webNavigation.onHistoryStateUpdated` (needs `webNavigation` perm) — try
without it first.

### C. Request sequencing (this is Item 6)

`sendPanelRequest` currently applies whatever response arrives. Add a monotonic guard so an
older response can't overwrite a newer one:

```ts
let stateSeq = 0;
let latestAppliedSeq = 0;

// inside sendPanelRequest, around the showPanelState call:
const seq = ++stateSeq;
// … await response …
if (seq < latestAppliedSeq) return true; // a newer response already rendered
latestAppliedSeq = seq;
showPanelState(response);
```

### D. (Optional) Optimistic neutralize

If, after B+C, a stale flash is still visible during the round-trip, neutralize tab-specific
UI (`nowPlayingTitle`, `nowPlayingMeta`, transcript list, progress bar) to a "Loading…" state
the instant `onActiveTabChanged` starts — **without** touching account/usage/settings (not
tab-specific). Gate it behind a short delay (only neutralize if the pull hasn't resolved in
~80 ms) to avoid a 1-frame flicker on warm round-trips. Ship B+C first and only add D if
measurement shows the flash.

### Guardrails (don't regress these)

- **Keep the default-view selection first-open-only.** The `hasAppliedDefaultView` guard
  (`main.ts:108`, `:426-429`) must stay so a tab switch never yanks the user off their
  current rail (Settings/Jobs/etc.).
- Keep the 10 s poll as a backstop (it also covers usage/history drift). Item 4 will add
  visibility gating on top.
- The content script's route-change handling already keeps the in-page overlay correct on
  in-tab nav; no change there.

## Edge cases

| Scenario | Expected | Handled by |
|----------|----------|------------|
| Tab 1 → tab 2 (both YouTube, same window) | Panel flips within a round-trip | B + A |
| Switch to a non-YouTube tab | Panel resets to "Open a YouTube video" promptly | B; `getPanelState` returns DEFAULT |
| In-tab SPA nav (A → C) | Panel follows without poll | B (onUpdated + `changeInfo.url`) |
| Rapid A→B→C | Ends on C, no intermediate clobber | B (debounce) + C (seq) |
| Two windows, two panels | Each shows/acts on its own window's tab | A |
| Window focus change, no tab change | Each panel already correct (reads are window-scoped, not focus-scoped) | A |
| Tab 1 generating, switch away and back | Tab 1 still shows progress (reconstructed from backend history if SW recycled) | existing `stateWithBackendProgress` |
| Close active tab; browser activates another | onActivated → refresh; `tabs.onRemoved` already prunes `tabSubtitleStates` (`background.ts:72`) | B + existing |
| `windows.getCurrent()` unavailable / no id | `panelWindowId` undefined → background falls back to `currentWindow:true`; listeners don't window-filter | A fallback |
| Firefox sidebar | Same APIs work; sidebar is per-window + persistent | cross-browser |
| Mid-action (typing password) when tab switches | Account/settings aren't tab-specific; D (if added) must scope neutralize to tab-specific UI only and not wipe form input | D scoping |
| onUpdated spam (favicon/title/status) | Ignored — only `changeInfo.url` triggers | B (url filter) |

**Window-id acquisition caveat:** `browser.windows.getCurrent()` from a side-panel/sidebar
document is expected to return the host window. **Verify empirically** in Chrome and Firefox
(open two windows, log `panelWindowId` in each). If it ever returns the wrong window, the
fallback is to adopt the window id from the first `onActivated` event, or have the background
stamp the resolved tab's `windowId` into `PanelState` and the panel adopt it.

## Tests (Item 1)

- Unit-test the window-scoped query builder: extract `activeTabQuery(windowId?)` returning
  `{active:true, windowId}` when given an id and `{active:true, currentWindow:true}`
  otherwise; assert both. Mirror `tests/panel-view-state.test.ts` style.
- Unit-test the seq guard (Item 6): an older seq response is dropped in favor of a newer one.
- Manual (capture short video evidence): the five scenarios in §Validation.

---

# Item 2 — Transcript panel rebuilds the whole list and hijacks scroll

## Problem

`entrypoints/sidepanel/transcript-view.ts` `render()` sets
`dom.transcriptList.innerHTML = panelTranscriptListHtml(...)`, destroying and recreating
**every** transcript row (each is an `<article>` with three `<button>`s,
`utils/panel/transcript.ts:38-57`). `render()` runs from:

- `setActiveCue(cueId)` — on **every cue boundary** during playback (driven by
  `background.activeCueChanged`, `main.ts:170`).
- `setData(...)` — from `showPanelState` on **every** `panel.getState` response, including
  the 10 s poll and every settings change; the cues array is a fresh object each poll, so it
  rebuilds even when nothing changed.

Every `render()` then calls `activeRow?.scrollIntoView({ block: 'nearest' })`.

**Impact:** for a normal video (hundreds of cues) the list DOM is rebuilt every 1–3 s during
playback plus every 10 s idle → jank + GC churn; and the repeated `scrollIntoView` **yanks
the user back to the active cue** on every cue change and every poll, making the transcript
effectively un-scrollable during playback. Button hover/focus is lost on each rebuild.

The page overlay already solved exactly this with a `renderedHtml` diff-guard
(`utils/overlay.ts:134`) — the sidepanel transcript is missing that pattern.

## Implementation

In `transcript-view.ts`, separate "data changed" from "active cue changed":

1. **Guard full rebuilds.** Keep a cached signature of what was last rendered (e.g. a string
   key of `youtubeVideoId` + cue count + `query` + the render-affecting settings
   `showRomanization`/`showTranslation`, or simply compare the produced HTML string like the
   overlay does). Only set `innerHTML` when that signature changes. `setData` should no-op
   the DOM when nothing render-affecting changed (still update internal `cues`/`settings`
   refs).
2. **Active-cue change = class toggle, not rebuild.** In `setActiveCue`, instead of
   `render()`:
   - Remove `.on` + `aria-current="true"` from the previously active row
     (`transcriptList.querySelector('.cue.on')`), set it to `aria-current="false"`.
   - Find the new row by `querySelector('[data-cue-id="…"]')`, add `.on` /
     `aria-current="true"`.
   - Call `scrollIntoView({ block: 'nearest' })` **only** here (active cue genuinely
     changed), and ideally only when the cue moved.
3. **Stop auto-scroll fighting the user.** Do not `scrollIntoView` from the data-render path
   (poll/settings). Optionally track the last manual-scroll timestamp on `transcriptList`
   and suppress auto-scroll for a short window after the user scrolls, so reading isn't
   interrupted. Minimum bar: never auto-scroll on the 10 s poll.

Escape `cueId` for the attribute selector (there's already `cssAttributeValue` in
`overlay.ts` you can mirror, or use `CSS.escape`).

## Edge cases

- Active cue becomes `null` (between cues / cleared): remove `.on` from the current row, no
  scroll.
- The active cue isn't in the current filtered view (search active): toggling finds no row —
  that's fine, no scroll.
- Settings toggle that changes row content (romanization/translation visibility) **does**
  require a rebuild — include those in the signature.
- Search input change still rebuilds (query is part of the signature).

## Tests (Item 2)

- Extend `tests/panel-transcript.test.ts` (or add one): rendering twice with identical
  inputs produces identical HTML and the second call is a no-op (assert the rebuild guard).
- Active-cue change toggles the `.on` class on exactly the right rows without rebuilding
  (assert node identity is preserved — same element references before/after).

---

# Item 3 — Extension API calls have no timeout (except job history)

## Problem

`utils/api.ts` only sets `timeoutMs` on `listSubtitleJobs` (2500 ms, `:23`). `request()`
only builds an `AbortController` when `timeoutMs` is set (`:110-114`), so
`createSubtitleJob`, `getSubtitleJob`, `getExtensionAccount`, `enrichLearningToken`,
`loginExtension`, `logoutExtension` have **no** timeout.

**Impact:** `getPanelState` awaits `syncExtensionAccount → getExtensionAccount` (unbounded,
`background.ts:510-511`) *before* the bounded history call, so a slow/hung account endpoint
hangs **every** panel refresh. The background poll loop `waitForCompletedSubtitleJob`
(`background.ts:320`) calls `getSubtitleJob` (unbounded) every 2 s; one hung poll stalls
progress.

## Implementation

In `utils/api.ts`:

- Give `request()` a **default** timeout when the caller doesn't specify one (e.g.
  `const timeoutMs = init.timeoutMs ?? DEFAULT_REQUEST_TIMEOUT_MS`), and always build the
  `AbortController`/timeout from that. Pick a sane default (e.g. 10 000 ms) and keep
  history's tighter 2500 ms.
- Consider a shorter explicit budget for the poll-loop `getSubtitleJob` (e.g. 4000 ms) so a
  stalled poll fails fast and the loop retries on the next tick rather than blocking.
- Confirm the existing abort→`TypeError('Backend request timed out.')` mapping
  (`:135-138`) still surfaces a friendly message via `publicSubtitleErrorMessage` (it maps
  `TypeError` to "Could not reach the subtitle backend…", `:178-180`). Good.

## Edge cases

- Login/enrich are user-initiated; a timeout should surface as a normal error in the panel
  (already handled by `sendPanelRequest`/`showRequestError`). Don't make these timeouts so
  short that a slow-but-valid transcription request mid-generation fails — only the
  per-request HTTP calls are being bounded, not the overall generation (that's the server's
  job to finish; the extension just polls).
- The background poll loop already exits when `isCurrentLoadingState` flips, so a fast-fail
  timeout simply means one more 2 s tick.

## Tests (Item 3)

- Extend `tests/api.test.ts`: a request whose `fetchImpl` never resolves rejects with the
  timeout `TypeError` after the default budget (use fake timers / an abort-aware stub).
- A request that resolves quickly is unaffected.

---

# Item 4 — Panel backend poll runs forever, even when hidden

## Problem

`entrypoints/sidepanel/main.ts:166` — `setInterval(refreshBackendState, 10000)` fires
indefinitely while the panel document is alive, even when the panel/window is hidden. Each
tick is `panel.getState` with `syncBackend:true`, which server-side fans out to
`syncExtensionAccount` + `listBackendJobHistory` (+ sometimes `resolveCompletedSubtitleJob`)
— ~2–3 backend round-trips every 10 s per open panel, regardless of visibility. No
`document.hidden` gating, no back-off when idle.

## Implementation

In `main.ts`:

- **Visibility gating:** skip `refreshBackendState()` when
  `document.visibilityState === 'hidden'`; on `visibilitychange → visible`, do one immediate
  refresh and resume the interval. (Pairs naturally with Item 1's tab-change refresh.)
- **Idle back-off (optional but recommended):** when there's no in-flight job for the active
  tab (subtitle state not `loading`), lengthen the effective interval (e.g. poll fast — 10 s
  — only while a job is `loading`; otherwise every 30–60 s). Implement by tracking the last
  rendered `subtitleState.type` and either using a variable timeout or counting skipped
  ticks.

Keep `backendRefreshInFlight` (the existing reentrancy guard, `:178`) intact.

## Edge cases

- A job that completes while the panel is hidden: on `visible` the immediate refresh picks
  it up; Item 1's tab listeners also cover the common "switch back to this tab" path.
- Don't gate the *event-driven* refreshes from Item 1 on visibility — those fire because the
  user is acting; only gate the periodic timer.

## Tests (Item 4)

- Unit-test the "should poll now" decision function (extract it): returns false when hidden,
  true when visible; respects the idle back-off counter. Keep the DOM/`document` access
  behind a small injectable so it's testable.

---

# Item 5 — `activeCueChanged` wakes the service worker every cue boundary

## Problem

`content.ts:328` sends `content.activeCueChanged` on every `onCueChange`; the background
re-broadcasts it as `background.activeCueChanged` via `runtime.sendMessage`
(`background.ts:106-114`). During playback this spins up the MV3 service worker on every cue
boundary just to forward a message to the panel — even when no panel is open (the broadcast
`.catch(()=>{})`es the "no receiver" error).

## Implementation

Lowest-risk option: **only emit when a panel is actually listening.**

- Have the side panel open a long-lived `runtime.connect` port (e.g.
  `browser.runtime.connect({ name: 'panel' })`) on load; the background tracks whether any
  `panel` port is connected (add/remove on `onConnect` / port `onDisconnect`).
- In the `content.activeCueChanged` handler, skip the re-broadcast when no panel port is
  connected. Optionally also have the content script skip *sending* when it knows no panel
  is interested — but the port lives in the background, so the simplest correct cut is to
  drop the broadcast in the background when there's no panel port. (The content→background
  message still wakes the SW; if you want to also avoid that, gate sending in content behind
  a cached "panelOpen" flag the background pushes on port connect/disconnect.)

If the port approach is too invasive for this branch, a smaller win is acceptable: coalesce
in the content script so identical consecutive `cueId`s (including repeated `null`s) aren't
re-sent — but the port-gating is the real fix. Document whichever you choose.

## Edge cases

- Panel opens mid-playback: on port connect, the panel already pulls fresh state (Item 1),
  so the active cue will be set on the next boundary anyway; no need to replay.
- Multiple panels (two windows): track a count/set of ports, broadcast if ≥1.

## Tests (Item 5)

- `tests/messages.test.ts` / background message tests: with no panel port registered, a
  `content.activeCueChanged` does not call `runtime.sendMessage`; with one registered, it
  does. (Mock the port registry.)

---

# Item 6 — Out-of-order panel responses (implemented in Item 1.C)

See **Item 1 → C. Request sequencing.** This is the monotonic `stateSeq`/`latestAppliedSeq`
guard in `sendPanelRequest`. Called out separately only so review can check it landed; no
extra work beyond Item 1.

---

# Item 7 — SW eviction abandons the in-memory generation poll loop

## Problem

`background.ts:320` `waitForCompletedSubtitleJob` polls every 2 s using the in-memory
`tabSubtitleStates` map (`:40`) and a `setTimeout` delay. A pending `setTimeout` does not
reliably keep an MV3 service worker alive, so the loop and the in-memory loading state can be
torn down mid-generation.

**Why it's Low:** the job keeps running server-side; the panel reconstructs progress from
backend job history via `stateWithBackendProgress` (`background.ts:527`) and republishes the
ready state to the active tab (`:531-533`). The only observable gap is the **page overlay**
sitting on a stale "Generating…" until the next panel poll republishes — and only while the
panel is open.

## Implementation (only if you want tighter overlay liveness)

- Treat backend job history as the recovery source of truth (it already mostly is) — this is
  fine to **leave as-is** and just document the behavior, given Low severity.
- If improving: drive overlay progress from a `chrome.alarms`-based poll (alarms survive SW
  eviction) instead of an in-memory `setTimeout` loop, **or** have the content script poll
  the background on a timer while it's in a `loading` state so the overlay self-heals without
  an open panel.

Recommendation: **scope this item to a documented decision** (keep current behavior, note the
backend-history recovery) unless the reviewer asks for the alarms-based loop. Don't gold-plate
on this branch.

## Tests (Item 7)

- If you implement alarms/content-poll: a test that a `loading` overlay transitions to
  `ready` after the backend reports completion without an open panel. Otherwise none.

---

# Item 8 — `failJob` does a non-atomic read-then-write status check

## Problem

`app/backend/app/Services/Subtitles/SubtitleJobFailureHandler.php:23-57` loads `$job`, checks
`in_array($job->status, ['completed','failed'])`, then `markFailed()` updates — with no row
lock spanning check-and-set. Within one batch (e.g. an *analysis* batch where a tokenize and
a translate job fail near-simultaneously) two `failed()` callbacks can both pass the guard
and both run `markFailed` + `cleanupReservedWork`.

**Why Low–Med:** billing is protected — `releaseReservation` uses an idempotency key
(`refund:<jobId>:<runId>:failure`, `UsageLedger.php:200`) so no double refund; `markFailed`
and `deleteForJob` are effectively idempotent. Damage is limited to duplicate work and
duplicate failure telemetry.

## Implementation

Make the claim atomic, mirroring the existing `claimPreparingJob` pattern
(`SubtitleGenerationPipeline.php:364-389`):

- Replace the read-then-check-then-`markFailed` with a single conditional update and act on
  the affected-row count, e.g.:

```php
$claimed = SubtitleJob::query()
    ->whereKey($subtitleJobId)
    ->where('run_id', $runId)
    ->whereNotIn('status', ['completed', 'failed'])
    ->update([
        'status' => 'failed',
        'stage' => $stage,
        'error_code' => $errorCode,
        'error_message' => $errorMessage,
    ]);

if ($claimed !== 1) {
    // someone else already finalized this run (or run_id is stale) — record stale-run
    // telemetry if run_id mismatched, then return without re-running cleanup/telemetry.
    return;
}
```

- Keep the `run_id` mismatch → `recordStaleRunSkipped` behavior. Only the winner proceeds to
  `cleanupReservedWork` + failure telemetry. Preserve the existing branching for
  `BillingEntitlementException` / `SubtitleProcessingException` vs unexpected (the public
  error code differs); compute `errorCode`/`errorMessage` before the conditional update.

Watch the ordering: the current code calls `markFailed` then reads `$job->refresh()` for
logging — after switching to the conditional update you'll need to `find()`/`refresh` the
job for the loggers since you no longer hold the hydrated post-update model from `update()`.

## Edge cases

- Stale `run_id` (a reset happened): the `where('run_id',$runId)` clause means `claimed`
  is 0 → return; preserve the stale-run telemetry path.
- Already completed: `whereNotIn(... 'completed')` means 0 rows → return, no spurious
  "failed" overwrite of a completed job.

## Tests (Item 8)

- Feature/unit test: two concurrent `failJob` calls for the same job/run result in exactly
  one `failed` transition, one reservation refund event, one failure telemetry record.
  (Simulate by calling `failJob` twice in sequence and asserting idempotent side effects —
  the conditional update gives single-winner semantics deterministically.)

---

# Item 9 — Per-user batch concurrency counter leak / sliding TTL

## Problem

`app/backend/app/Jobs/Middleware/LimitSubtitleBatchConcurrency.php` claims a slot by
incrementing a cache counter (`claimSlot`) and releases it in a `finally` (`releaseSlot`).
Every `put` writes a **fresh** TTL (`now()->addSeconds(...)`, `:74-78`, `:111-115`). Two
problems:

1. **Sliding TTL:** a continuously busy counter never reaches the safety-net expiry, so the
   TTL only helps once the user goes fully idle.
2. **Leak on hard stop:** `finally` runs on thrown exceptions but **not** on SIGKILL/OOM. A
   leaked +1 then persists (kept alive by problem 1 while the user has other batch activity),
   permanently lowering that user's effective concurrency until they go fully idle.

**Impact:** after an unclean worker death a user gets silently throttled (batches repeatedly
`release()`d with delay) for as long as they keep generating.

## Implementation

Move from a single integer counter to a structure that ages out individual slots on a fixed
deadline regardless of other activity. Recommended: a per-(user,tier) **sorted set / set of
slot tokens** keyed by `runId` (or job id + batch index), each with an absolute expiry:

- On claim: add a unique token with score = `now()+slotTtl`; first evict tokens whose score
  is in the past; count remaining; allow if `< limit`.
- On release: remove this token.
- Crucially, do **not** refresh other tokens' deadlines on claim/release — only the touched
  token. A dead worker's token then ages out at its own absolute deadline even under
  continuous load.

If staying with the integer counter is preferred for minimal change, at minimum: (a) do
**not** rewrite the TTL on decrement, and (b) add a periodic reconciliation (scheduled
command) that recomputes the counter from actually-running batch jobs for that user/tier and
corrects drift. The token-set approach is cleaner and self-healing — prefer it.

Relevant tunables already exist: `SubtitleTier::concurrencyCounterSeconds()`,
`concurrencyLockSeconds()`, `concurrencyReleaseDelaySeconds()`. Use the counter-seconds value
as the per-slot absolute TTL.

## Edge cases

- Lock timeout during claim (`LockTimeoutException`, `:81`): keep current behavior (treat as
  not-claimed, release the queued job with delay).
- Sync queue driver short-circuit (`:18`) and the `user_id === null` bypass (`:24`) stay.
- Make sure the eviction-on-read can't itself exceed the limit due to a race — do it inside
  the existing `Cache::lock(...)->block(...)` critical section.

## Tests (Item 9)

- Unit/integration: claim `limit` slots, then a stale slot (token with past deadline) is not
  counted on the next claim; an un-released (leaked) slot whose deadline has passed frees up
  capacity; releasing a token frees capacity immediately. Use the array/redis cache store the
  tests already use for concurrency.

---

# Item 10 — Stripe subscription events applied without ordering guard

## Problem

`app/backend/app/Services/Billing/StripeWebhookService.php:125-155`
(`handleSubscriptionChanged`) is idempotent per `stripe_event_id` (`lockForUpdate`,
`processed_at` short-circuit) — good — but Stripe does **not** guarantee delivery order. The
handler unconditionally overwrites `billing_current_period_start/end`, `status`,
`cancel_at_period_end`, etc. So an older `customer.subscription.updated` delivered/processed
after a newer one overwrites the user's current period/status with stale values, which then
feed `UsageLedger.ensureMonthlyGrant` and entitlement checks.

## Implementation

Add a per-subscription "last applied" marker and skip older events:

- Use a monotonic source from the event. Best available: the top-level `event.created`
  timestamp (pass it through `handle()` → `apply()` → `handleSubscriptionChanged`), or the
  subscription object's own updated marker if present. `event.created` is simplest and
  reliably monotonic per-account for a given object's lifecycle in practice.
- Persist the last-applied value on the user (new nullable column, e.g.
  `billing_subscription_event_at` / `..._synced_at`). In `handleSubscriptionChanged`, if the
  incoming marker is **older than or equal to** the stored one, skip the mutating writes
  (still mark the webhook event processed for idempotency).
- Apply the same guard to `handleInvoicePaymentFailed`/`checkout.session.completed` only if
  they write period/status fields that ordering could corrupt — for this codebase the main
  risk is `handleSubscriptionChanged`; scope there unless review wants more.

Migration: add the nullable timestamp column to `users` (or wherever billing fields live —
check the existing billing columns migration). Backfill is unnecessary (null = "no event
applied yet", treat as oldest).

## Edge cases

- First event for a subscription (stored marker null): apply and set the marker.
- `subscription.deleted` arriving after a stale `updated`: deletion should still win — make
  sure the guard uses the event timestamp, so a genuinely newer delete is applied. If a
  delete and update share a timestamp, prefer the terminal state; keep it simple and rely on
  `event.created` ordering.
- Keep `ensureMonthlyGrant` gated on `status in ['active','trialing']` as today, now fed by
  non-stale period data.

## Tests (Item 10)

- Existing Stripe webhook tests: add a case where an older `subscription.updated`
  (smaller `event.created`) processed after a newer one does **not** overwrite the newer
  period/status; and the normal in-order path still applies.

---

# Item 11 — `clearAll` loads every job into one long transaction

## Problem

`app/backend/app/Http/Controllers/WebSubtitleJobController.php:69-103` `->get()`s **all** of
a user's jobs (unbounded, unlike the 25-row API index), then loops
`releaseReservationSafely` + `$job->delete()` inside a single `DB::transaction`. For a heavy
user (hundreds of jobs) this materializes the whole set and holds a long transaction (each
delete cascades to tracks/artifacts). Slow request, lock contention, possible timeout.

## Implementation

- Process in chunks instead of one big transaction/collection. Iterate with
  `SubtitleJob::query()->whereBelongsTo($user)->chunkById(200, ...)`, and within each chunk
  release reservations for `running` jobs and delete. Keep transactions **per chunk** (not
  one giant transaction) so locks are short-lived.
- Preserve the user-facing summary (count deleted, the `jobs_status` flash). Accumulate the
  count across chunks.
- For very large histories, consider dispatching a queued cleanup job and returning
  immediately with a "clearing…" message — optional; chunking is sufficient for now.

## Edge cases

- Reservation release must still only run for `status === 'running'` (`:107`).
- A job that finishes/changes status mid-clear: `chunkById` snapshots by id ranges; a status
  flip between read and delete is fine (delete by id still works; the reservation-release
  guard re-checks status on the loaded model).
- Keep `destroy` (single delete) as-is.

## Tests (Item 11)

- Feature test: a user with > chunk-size jobs has all cleared, reservations released for
  running ones, and the flash count matches — without relying on a single transaction.

---

# Validation (whole branch)

Extension (`app/extension/`):

```powershell
npm run compile   # tsc --noEmit
npm run test      # vitest run
```

Backend (`app/backend/`):

```powershell
# use the repo's standard check script if present, else:
php artisan test
```

(There is a repo check script referenced by the plan template:
`.\scripts\agent\check.ps1` — run it if it covers both apps.)

Manual evidence to capture (short clips / screenshots):

1. Two YouTube tabs, one window: generate in tab 1, switch to tab 2 → panel flips instantly
   (no ~10 s lag); switch back → tab 1 returns. (Item 1)
2. In-tab related-video click → panel follows. (Item 1)
3. Switch to a non-YouTube tab → "Open a YouTube video". (Item 1)
4. Two windows: generate in window A; window B's panel reflects B's tab and **Generate** in
   B acts on B's tab. (Item 1)
5. During playback, scroll the transcript freely — it is no longer yanked back each cue; the
   active row still highlights and auto-scrolls only when the cue actually changes. (Item 2)
6. Kill the backend mid-refresh → panel surfaces a timeout error instead of hanging. (Item 3)
7. Backgrounded panel stops polling; foregrounding resumes with an immediate refresh.
   (Item 4)

# Files to touch (master checklist)

Extension:
- `app/extension/utils/messages.ts` — optional `windowId` on `panel.*` + `optionalNumber`
  validator. (Item 1)
- `app/extension/entrypoints/background.ts` — `getActiveTab(windowId?)` + thread `windowId`;
  panel-port registry + gate `activeCueChanged` broadcast. (Items 1, 5)
- `app/extension/entrypoints/sidepanel/main.ts` — resolve `panelWindowId`; tab listeners;
  fast-pull-then-sync; attach `windowId`; seq guard; visibility gating + idle back-off.
  (Items 1, 4, 6)
- `app/extension/entrypoints/sidepanel/transcript-view.ts` — rebuild guard + class-toggle on
  active cue + scroll discipline. (Item 2)
- `app/extension/utils/api.ts` — default request timeout. (Item 3)
- `app/extension/tests/…` — new/extended tests per items. (Items 1–5)
- No `wxt.config.ts` / manifest change (no new permissions).

Backend:
- `app/backend/app/Services/Subtitles/SubtitleJobFailureHandler.php` — atomic claim. (Item 8)
- `app/backend/app/Jobs/Middleware/LimitSubtitleBatchConcurrency.php` (+ possibly
  `SubtitleTier`) — token-set concurrency. (Item 9)
- `app/backend/app/Services/Billing/StripeWebhookService.php` + a `users` migration —
  event-ordering guard. (Item 10)
- `app/backend/app/Http/Controllers/WebSubtitleJobController.php` — chunked `clearAll`.
  (Item 11)
- `app/backend/tests/…` — tests per items. (Items 8–11)

# Suggested commit order

1. Item 1 (incl. 6) — tab-switch + window scoping + seq guard. *Headline; everything else is
   additive.*
2. Item 2 — transcript rebuild/scroll. *Biggest felt perf/UX win.*
3. Item 3 then Item 4 — timeouts, then visibility gating (4 shrinks 3's blast radius).
4. Item 5 — SW-wake gating.
5. Item 8 — failJob atomic claim.
6. Item 9 — concurrency token-set.
7. Item 10 — Stripe ordering guard (+ migration).
8. Item 11 — chunked clearAll.
9. Item 7 — document the decision (or implement alarms poll if requested).

# Decision log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-21 | Panel-side tab listeners, not background broadcast | Panel knows its window; one fewer hop; auto-cleans on close. |
| 2026-06-21 | Thread `windowId` through panel requests | Fixes tab-switch targeting *and* the `currentWindow`-from-SW multi-window bug at once. |
| 2026-06-21 | No `tabs` permission | Avoids Web Store permission re-review; `activeTab` + youtube host perm suffice. |
| 2026-06-21 | Mirror the overlay's render-diff for the transcript | Proven pattern already in `utils/overlay.ts`. |
| 2026-06-21 | Default request timeout in the API client | A slow account/job endpoint must not hang panel refresh. |
| 2026-06-21 | Atomic single-winner `failJob` | Matches `claimPreparingJob`; removes the read-then-write race. |
| 2026-06-21 | Token-set concurrency over sliding-TTL counter | Self-heals leaked slots on a fixed deadline regardless of load. |
| 2026-06-21 | Stripe `event.created` ordering guard | Prevents stale subscription events from corrupting period/status. |
| 2026-06-21 | Item 7 likely documented, not gold-plated | Backend history already recovers state; Low severity. |
| 2026-06-21 | Item 7 decision: keep current `setTimeout` poll loop; backend job history is the recovery source of truth | `stateWithBackendProgress` reconstructs loading/ready state from `listBackendJobHistory` after SW eviction. The only gap is the page overlay sitting on stale "Generating…" until the next panel poll republishes — only while the panel is open. Alarms-based loop was considered but rejected for this branch as gold-plating given Low severity. |

# Progress log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-21 | Consolidated plan written (tab-switch + audit findings) for single-branch handoff. | This document. |
| 2026-06-21 | Items 1–6, 8–11 implemented; Item 7 documented as decision. | Commits on `feature/extension-backend-hardening`. |
