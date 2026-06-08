# Extension Side-Panel Redesign — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the toolbar popup with a native, sharp-styled browser **side panel** (left icon rail: Generate · Study · Jobs · Account) and restyle the on-video overlay (caption rail + word card) to match — reusing the popup's existing logic modules.

**Architecture:** Add a WXT `sidepanel` entrypoint that re-shells the popup. The popup is already decomposed into reusable modules (`view-model.ts` formatters, `render/*` HTML, `timing-control.ts`, and the generic `tabs.ts` `setupTabs`/`showTab` switcher). Phase 1 builds a new `index.html` (rail + now-playing header + view sections), a new `dom.ts` (panel selectors), a sharp `style.css` (ported from the committed prototype), and a thin `main.ts` that wires state to the new DOM via those existing modules. The overlay is restyled by rewriting `overlay-styles.ts` and reordering word-card rows in `overlay-render.ts`, preserving the `tests/overlay.test.ts` DOM contract. The popup entrypoint is removed and the toolbar action is configured to open the side panel.

**Tech Stack:** WXT (web-extension framework), TypeScript, Vitest, Chrome `side_panel` / Firefox `sidebar_action`, `@fontsource` for bundled Geist + IBM Plex Mono.

**Out of scope (Phase 2, separate plan):** Moving the transcript from the overlay Shadow DOM into a panel "Transcript" view + the active-cue relay/seek messages. In Phase 1 the transcript **stays in the overlay untouched**, so `overlay.test.ts`'s transcript cases keep passing. The Phase 1 rail therefore has **four** destinations (no Transcript icon yet).

**Canonical visual reference (committed):** `docs/design-assets/extension-sidepanel-redesign/prototype.html`. This approved hi-fi prototype is the source of truth for the sharp layout, the exact CSS (design tokens, square controls, hairline rules, segmented selectors, word card), and the markup structure. When a task says "port from the prototype," open that file and adapt its CSS/markup to the real DOM hooks named in the task.

---

## Working agreements

- **Run all commands from `app/extension/`** unless stated otherwise.
- Test/compile/build commands: `npm test` (vitest run), `npm run compile` (`tsc --noEmit`), `npm run build` (wxt build). Confirm these exact script names once at the start: `npm run` lists them.
- Keep the existing brand color tokens (black + crimson) from the prototype `:root`. Exact color tuning is the user's follow-up and is **not** part of this plan.
- Commit after every task with the message shown in the task's final step.

## File structure

**Create**
- `app/extension/utils/panel/view-state.ts` — pure `selectDefaultView(state)` (state-aware default view).
- `app/extension/tests/panel-view-state.test.ts` — tests for the above.
- `app/extension/entrypoints/sidepanel/index.html` — panel markup: icon rail, now-playing header, four `data-panel` view sections.
- `app/extension/entrypoints/sidepanel/dom.ts` — typed `getPanelDom()` selector bundle.
- `app/extension/entrypoints/sidepanel/main.ts` — bootstrap + state wiring (adapted from `popup/main.ts`).
- `app/extension/entrypoints/sidepanel/style.css` — sharp panel styles (ported from prototype) + font import.
- `app/extension/entrypoints/sidepanel/render/` — re-export or move the reused renderers (see Task 6).
- `app/extension/public/fonts/` — bundled font files (via `@fontsource`, see Task 1).

**Modify**
- `app/extension/package.json` — add `@fontsource/geist-sans`, `@fontsource/ibm-plex-mono` deps.
- `app/extension/entrypoints/popup/render/job-history.ts` — job rows become explicit YouTube links (shared renderer; popup is removed later but the renderer moves to the panel — see Task 6/7).
- `app/extension/wxt.config.ts` — add `sidePanel` permission (Chrome) and `web_accessible_resources` for fonts.
- `app/extension/entrypoints/background.ts` — `openPanelOnActionClick` on install/startup.
- `app/extension/utils/overlay/overlay-styles.ts` — full sharp restyle.
- `app/extension/utils/overlay/overlay-render.ts` — reorder word-card rows (romanization above translation).
- `docs/FRONTEND.md` — describe the side-panel architecture.

**Remove (last task)**
- `app/extension/entrypoints/popup/` (entire folder), after the panel reaches parity.

---

## Task 1: Bundle Geist + IBM Plex Mono

**Files:**
- Modify: `app/extension/package.json`
- Create: `app/extension/entrypoints/sidepanel/style.css` (font imports only in this task)

- [ ] **Step 1: Install the font packages**

Run (from `app/extension/`):
```bash
npm install @fontsource/geist-sans@^5 @fontsource/ibm-plex-mono@^5
```
Expected: both packages added to `dependencies` in `package.json`, present under `node_modules/@fontsource/`.

- [ ] **Step 2: Verify the weights we use exist**

Run:
```bash
ls node_modules/@fontsource/geist-sans/files | grep -E "latin-(400|500|600|700)-normal" | head
ls node_modules/@fontsource/ibm-plex-mono/files | grep -E "latin-(400|500)-normal" | head
```
Expected: woff2 files listed for those weights. (These packages self-host the woff2; importing the package CSS emits `@font-face` with bundled URLs.)

- [ ] **Step 3: Create the panel stylesheet with font imports**

Create `app/extension/entrypoints/sidepanel/style.css` with exactly:
```css
@import '@fontsource/geist-sans/400.css';
@import '@fontsource/geist-sans/500.css';
@import '@fontsource/geist-sans/600.css';
@import '@fontsource/geist-sans/700.css';
@import '@fontsource/ibm-plex-mono/400.css';
@import '@fontsource/ibm-plex-mono/500.css';

/* Sharp panel styles are added in Task 6. */
```
(`@fontsource/geist-sans` exposes the family `"Geist Sans"`; use that family name in Task 6's tokens.)

- [ ] **Step 4: Commit**
```bash
git add app/extension/package.json app/extension/package-lock.json app/extension/entrypoints/sidepanel/style.css
git commit -m "build(extension): bundle Geist + IBM Plex Mono fonts"
```

---

## Task 2: `selectDefaultView` — state-aware default (TDD)

The panel opens on the view that matches the current state: not signed in → Account; signed in with a ready track → Study; otherwise → Generate.

**Files:**
- Create: `app/extension/utils/panel/view-state.ts`
- Test: `app/extension/tests/panel-view-state.test.ts`

- [ ] **Step 1: Write the failing test**

Create `app/extension/tests/panel-view-state.test.ts`:
```ts
import { describe, expect, it } from 'vitest';

import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import { accountStateFromJobHistory } from '../utils/popup-saas-state';
import type { PopupState } from '../utils/messages';
import { selectDefaultView } from '../utils/panel/view-state';

function baseState(overrides: Partial<PopupState> = {}): PopupState {
  return {
    installId: 'install-1',
    settings: DEFAULT_EXTENSION_SETTINGS,
    accountState: accountStateFromJobHistory([]),
    subtitleState: { type: 'no-track' },
    jobHistory: [],
    ...overrides,
  };
}

describe('selectDefaultView', () => {
  it('opens Account when the user is anonymous', () => {
    expect(selectDefaultView(baseState())).toBe('account');
  });

  it('opens Study when signed in and a track is ready', () => {
    const state = baseState({
      accountState: { ...accountStateFromJobHistory([]), status: 'authenticated', planName: 'Local beta' },
      subtitleState: { type: 'ready', track: { trackId: 't', jobId: 'j', youtubeVideoId: 'v', sourceLanguage: 'spa', targetLanguage: 'eng', generatedAt: '', expiresAt: '', webVtt: 'WEBVTT', cues: [] } },
    });
    expect(selectDefaultView(state)).toBe('study');
  });

  it('opens Generate when signed in without a ready track', () => {
    const state = baseState({
      accountState: { ...accountStateFromJobHistory([]), status: 'authenticated' },
      subtitleState: { type: 'no-track' },
    });
    expect(selectDefaultView(state)).toBe('generate');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm test -- panel-view-state`
Expected: FAIL — `Cannot find module '../utils/panel/view-state'`.

- [ ] **Step 3: Implement `selectDefaultView`**

Create `app/extension/utils/panel/view-state.ts`:
```ts
import type { PopupState } from '../messages';

export type PanelView = 'generate' | 'study' | 'jobs' | 'account';

export function selectDefaultView(state: PopupState): PanelView {
  if (state.accountState.status !== 'authenticated') {
    return 'account';
  }

  if (state.subtitleState.type === 'ready') {
    return 'study';
  }

  return 'generate';
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm test -- panel-view-state`
Expected: PASS (3 passing).

- [ ] **Step 5: Commit**
```bash
git add app/extension/utils/panel/view-state.ts app/extension/tests/panel-view-state.test.ts
git commit -m "feat(extension): add state-aware default panel view selector"
```

---

## Task 3: Job rows link out to YouTube (TDD on the shared renderer)

The job renderer already groups Videos/Shorts and carries `data-video-url`. Make each row an explicit, accessible link to its YouTube watch/shorts URL (the prototype shows the row as a link with an external-link affordance), while keeping the existing `data-action="view-video"/"retry-job"` buttons for the click handler.

**Files:**
- Modify: `app/extension/entrypoints/popup/render/job-history.ts:66-93`
- Test: `app/extension/tests/job-history-render.test.ts` (create)

- [ ] **Step 1: Write the failing test**

Create `app/extension/tests/job-history-render.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { JSDOM } from 'jsdom';

import { renderJobHistory } from '../entrypoints/popup/render/job-history';
import { accountStateFromJobHistory } from '../utils/popup-saas-state';
import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { PopupState } from '../utils/messages';

function stateWithJob(): PopupState {
  return {
    installId: 'i',
    settings: DEFAULT_EXTENSION_SETTINGS,
    accountState: accountStateFromJobHistory([]),
    subtitleState: { type: 'no-track' },
    jobHistory: [
      {
        publicJobId: 'job-1',
        youtubeVideoId: 'dQw4w9WgXcQ',
        youtubeUrl: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        sourceLanguage: 'spa',
        targetLanguage: 'eng',
        status: 'completed',
        includeTranslation: true,
        includeRomanization: false,
        enrichmentMode: 'on-click',
        stage: 'finalizing',
      } as unknown as PopupState['jobHistory'][number],
    ],
  };
}
// If `publicJobTelemetry`/`stageTimeline` throw on a missing field at runtime,
// reuse the job-item builder from `tests/job-history-media.test.ts` instead of
// this inline literal — it constructs a complete `SubtitleJobHistoryItem`.

describe('renderJobHistory links', () => {
  it('renders each job as a link to its YouTube URL', () => {
    const dom = new JSDOM('<div id="list"></div><p id="err"></p>');
    const jobsList = dom.window.document.getElementById('list')!;
    const jobsError = dom.window.document.getElementById('err')!;

    renderJobHistory(stateWithJob(), { jobsList, jobsError });

    const link = jobsList.querySelector('a.job-open-link') as HTMLAnchorElement | null;
    expect(link).not.toBeNull();
    expect(link!.getAttribute('href')).toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    expect(link!.getAttribute('target')).toBe('_blank');
  });
});
```

- [ ] **Step 2: Confirm vitest can use jsdom; run the test to verify it fails**

Run: `npm test -- job-history-render`
Expected: FAIL — assertion fails (`a.job-open-link` is null). If instead it errors with `Cannot find package 'jsdom'`, add the dev dep: `npm install -D jsdom` (WXT already pulls jsdom transitively; install only if the import fails), then re-run and confirm the **assertion** failure.

- [ ] **Step 3: Add the link to the job article header**

In `app/extension/entrypoints/popup/render/job-history.ts`, inside `jobHistoryItemHtml`, wrap the title in an anchor. Replace the `<span class="job-title">…</span>` line (currently line ~70) with:
```ts
          <a class="job-title job-open-link" href="${escapeHtml(job.youtubeUrl)}" target="_blank" rel="noopener">${escapeHtml(job.youtubeVideoId)}</a>
```
Leave the existing `data-action` buttons in `.job-actions` unchanged (the click handler still uses them).

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm test -- job-history-render`
Expected: PASS.

- [ ] **Step 5: Run the full suite to confirm no regressions**

Run: `npm test`
Expected: all pass.

- [ ] **Step 6: Commit**
```bash
git add app/extension/entrypoints/popup/render/job-history.ts app/extension/tests/job-history-render.test.ts
git commit -m "feat(extension): link job rows to their YouTube video"
```

---

## Task 4: Panel DOM selectors (`getPanelDom`)

Mirror `popup/dom.ts`'s pattern: one typed accessor that queries every element `main.ts` needs. The element set is the same as the popup's (same controls) plus the rail buttons, view sections, the now-playing header nodes, and the collapse button; it omits popup-only chrome.

**Files:**
- Create: `app/extension/entrypoints/sidepanel/dom.ts`
- Reference: `app/extension/entrypoints/popup/dom.ts` (copy its query helpers and the per-control accessors verbatim, then add the panel-specific nodes below)

- [ ] **Step 1: Create `dom.ts` by adapting the popup version**

Open `app/extension/entrypoints/popup/dom.ts` and copy it to `app/extension/entrypoints/sidepanel/dom.ts`, renaming `getPopupDom` → `getPanelDom`. Keep every control accessor that the settings/account/generate/jobs logic uses (language inputs, all the setting checkboxes/selects, timing inputs, generate/clear/reset buttons, account form fields, usage nodes, jobs list/error, feature list, shortcut help list). Then **replace the popup-chrome accessors** (`tabButtons`, `panels`, `refreshButton`, `planPill`, the status/facts nodes) with the panel equivalents:
```ts
  railButtons: queryAll<HTMLButtonElement>('[data-tab]'),
  panels: queryAll<HTMLElement>('[data-panel]'),
  collapseButton: query<HTMLButtonElement>('[data-action="collapse-panel"]'),
  nowPlayingEyebrow: query<HTMLElement>('[data-now-playing-eyebrow]'),
  nowPlayingTitle: query<HTMLElement>('[data-now-playing-title]'),
  nowPlayingMeta: query<HTMLElement>('[data-now-playing-meta]'),
  statusText: query<HTMLElement>('[data-status]'),
  generateButton: query<HTMLButtonElement>('[data-action="generate"]'),
  // …keep the rest of the popup control accessors unchanged…
```
Use the same `query`/`queryAll` helpers `popup/dom.ts` already defines (copy them in). Keep `data-tab`/`data-panel` so the existing `setupTabs`/`showTab` work unchanged.

- [ ] **Step 2: Compile-check (will fail until index.html exists — that's fine, just verify TS shape)**

Run: `npm run compile`
Expected: no type errors *from `dom.ts` itself* (runtime `null` assertions are cast as in the popup). If `tsc` complains about unused exports, ignore until `main.ts` consumes them in Task 7. Do not commit yet — `dom.ts`, `index.html`, and `main.ts` commit together in Task 7.

---

## Task 5: Panel markup (`index.html`)

Build the panel's static markup: a left icon rail (`data-tab` buttons), a now-playing header with a collapse button, and four `data-panel` sections (generate/study/jobs/account) containing the **same controls/`data-*`/`name` hooks the popup used** so the reused renderers and `dom.ts` selectors bind unchanged.

**Files:**
- Create: `app/extension/entrypoints/sidepanel/index.html`
- Reference: `app/extension/entrypoints/popup/index.html` (control hooks) + the prototype (rail/now-playing/view structure)

- [ ] **Step 1: Author `index.html`**

Start from `popup/index.html` for the **control markup inside each panel** (language pickers, option toggles, study controls, timing control, account form, usage, jobs list, shortcut help — keep their `name`, `data-*`, `id` attributes identical so binding is unchanged). Wrap them in the prototype's shell:
```html
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>AI Subtitles</title>
  </head>
  <body>
    <div id="app" class="panel">
      <nav class="rail-nav" aria-label="Sections">
        <span class="brandmark" aria-hidden="true"><!-- cc glyph svg from prototype --></span>
        <button class="rbtn" type="button" data-tab="generate" aria-selected="true" aria-controls="panel-generate"><!-- icon --><span class="tip">Generate</span></button>
        <button class="rbtn" type="button" data-tab="study" aria-selected="false" aria-controls="panel-study"><!-- icon --><span class="tip">Study</span></button>
        <span class="rspacer"></span>
        <button class="rbtn" type="button" data-tab="jobs" aria-selected="false" aria-controls="panel-jobs"><!-- icon --><span class="tip">Jobs</span></button>
        <button class="rbtn" type="button" data-tab="account" aria-selected="false" aria-controls="panel-account"><!-- avatar icon --><span class="tip">Account</span></button>
      </nav>
      <div class="body">
        <header class="np">
          <div class="np-text">
            <div class="ey" data-now-playing-eyebrow>Now playing</div>
            <div class="title" data-now-playing-title>—</div>
            <div class="meta" data-now-playing-meta></div>
          </div>
          <button class="np-collapse" type="button" data-action="collapse-panel" title="Hide panel" aria-label="Hide panel">»</button>
        </header>
        <main class="scroll">
          <section class="view" data-panel="generate" id="panel-generate"><!-- generate controls from popup --></section>
          <section class="view" data-panel="study" id="panel-study"><!-- study controls from popup --></section>
          <section class="view" data-panel="jobs" id="panel-jobs"><!-- jobs list + error from popup --></section>
          <section class="view" data-panel="account" id="panel-account"><!-- account/usage/local-data from popup --></section>
        </main>
      </div>
    </div>
    <script type="module" src="./main.ts"></script>
  </body>
</html>
```
Copy the four rail icons and the brandmark glyph from the prototype's SVGs. **Do not** include a Transcript rail button (Phase 2). Keep all popup control hooks verbatim inside the sections.

- [ ] **Step 2: Sanity-check hooks are present**

Run:
```bash
grep -c 'data-panel=' app/extension/entrypoints/sidepanel/index.html   # expect 4
grep -c 'data-tab='   app/extension/entrypoints/sidepanel/index.html   # expect 4
grep -o 'name="[a-zA-Z]*"' app/extension/entrypoints/sidepanel/index.html | sort -u
```
Expected: 4 panels, 4 tabs, and every control `name` the popup had (overlayPosition, captionFontSize, showTranslation, etc.). Do not commit yet (commits with Task 7).

---

## Task 6: Sharp panel CSS + reuse renderers

Port the prototype's sharp styling into the panel stylesheet, and make the reused renderers available to the panel.

**Files:**
- Modify: `app/extension/entrypoints/sidepanel/style.css`
- Reference: `docs/design-assets/extension-sidepanel-redesign/prototype.html` (the `<style>` block), and the popup `styles/*.css` for any control specifics.
- Reuse (no change): `entrypoints/popup/render/{account,language-picker,shortcuts}.ts`, `view-model.ts`, `timing-control.ts`, `tabs.ts`.

- [ ] **Step 1: Port the design tokens + base**

Into `sidepanel/style.css` (after the font `@import`s), copy the prototype `:root` token block and base resets, but set the sans family to the bundled face: `--sans: 'Geist Sans','Inter',ui-sans-serif,system-ui,sans-serif;` and `--mono: 'IBM Plex Mono',ui-monospace,Consolas,monospace;`. Set `--radius` concepts to `0` everywhere (the prototype already uses square corners).

- [ ] **Step 2: Port the component styles**

Copy the prototype's styles for: `.panel`, `.rail-nav`/`.rbtn`/`.brandmark`/`.tip`, `.body`, `.np`/`.np-collapse`, `.scroll`, `.view`, `.sec`, `.lbl`, fields/`.input`, `.seg`, `.toggle-row`/`.chk`/`.sw`, `.btn`/`.btn-2`, `.slider`/`.readout`, `.facts`/`.fact`, `.meter`/`.mbar`, `.danger-zone`, `.job`/`.chip`/`.jlink`, `.sc-list`. Then map them onto the **actual class/`data-*` names** the popup renderers emit (e.g. the language picker emits `[data-language-code]` buttons; job-history emits `.job-section`, `.job-item`, `.job-badge`, `.job-actions`, `.job-open-link`; shortcuts emit the shortcut list). Where the renderer's class differs from the prototype's demo class, **style the renderer's real class** to the prototype's look. Keep it in one focused file; if it grows past ~500 lines, split into `sidepanel/styles/*.css` mirroring the popup split and `@import` them.

Also port responsive behavior (carry over the intent of `popup/styles/responsive.css`): the panel must stay usable down to ~320px wide — the rail stays fixed, the now-playing header sticks, the scroll region scrolls, and any two-column control grids (e.g. Size/Density) collapse to one column below ~360px.

- [ ] **Step 3: Build and screenshot every view**

Run: `npm run build`
Expected: build succeeds; `.output/chrome-mv3/sidepanel.html` exists.

Then load the unpacked build (`.output/chrome-mv3/`) in Chrome (`chrome://extensions` → Load unpacked) on a YouTube watch page, open the side panel, and screenshot all four views. Compare against the prototype. Fix spacing/hairline/type discrepancies. (Visual parity is the acceptance bar; there is no unit test for CSS.)

- [ ] **Step 4: Commit the panel stylesheet**
```bash
git add app/extension/entrypoints/sidepanel/style.css
git commit -m "style(extension): sharp side-panel stylesheet ported from prototype"
```

---

## Task 7: Panel bootstrap (`main.ts`) wiring state to the new DOM

Adapt `popup/main.ts` into `sidepanel/main.ts`: identical request/response + render logic, but bound to `getPanelDom()`, switching views via `setupTabs`/`showTab`, populating the now-playing header, applying the state-aware default, and wiring the collapse button.

**Files:**
- Create: `app/extension/entrypoints/sidepanel/main.ts`
- Reference: `app/extension/entrypoints/popup/main.ts` (the logic to reuse)

- [ ] **Step 1: Copy `popup/main.ts` → `sidepanel/main.ts` and rebind**

Copy the file. Change the imports: `import './style.css'`, `import { getPanelDom } from './dom'`, keep `setupTabs`/`showTab` from `'./tabs'` (reused), keep the `render/*`, `view-model`, `timing-control`, and `utils/*` imports (adjust relative paths to `../../utils/...` and `./render/...` — point `render`/`tabs`/`timing-control`/`view-model` imports at the popup folder for now, e.g. `'../popup/render/job-history'`, since those modules are shared and the popup folder is removed last in Task 12; in Task 12 they move to `sidepanel/`). Replace the destructured `getPopupDom()` with `getPanelDom()` and drop popup-only nodes (`planPill`, `refreshButton`, the status/facts nodes you removed).

- [ ] **Step 2: Replace tab setup with rail + state-aware default**

Where the popup calls `setupTabs(tabButtons, panels)`, use the rail buttons: `setupTabs(railButtons, panels)`. In `showPopupState` (rename to `showPanelState`), after applying state, set the now-playing header and the default view on first render:
```ts
nowPlayingEyebrow.textContent = supported ? 'Now playing' : 'No video';
nowPlayingTitle.textContent = supported ? (pageStatus.title ?? pageStatus.videoId) : 'Open a YouTube video';
nowPlayingMeta.textContent = supported ? `${videoDurationLabel(state)} · ${languageLabel(settings.sourceLanguage)} → ${languageLabel(settings.targetLanguage)}` : '';

if (!hasAppliedDefaultView) {
  hasAppliedDefaultView = true;
  showTab(railButtons, panels, selectDefaultView(state));
}
```
Add `let hasAppliedDefaultView = false;` near the other module state, and `import { selectDefaultView } from '../../utils/panel/view-state'`. (If `YoutubePageInfo` has no `title`, use `videoId` — confirm the field and use what exists.)

- [ ] **Step 3: Wire the collapse button**

Add: `collapseButton.addEventListener('click', () => { window.close(); });` — closing the side panel page returns the space to YouTube. (If `window.close()` does not close the panel in the target browser, this is the Phase-1 fallback documented in the spec; the toolbar action remains the guaranteed toggle. Note any browser where it no-ops in the task's verification.)

- [ ] **Step 4: Compile**

Run: `npm run compile`
Expected: no type errors. Fix any (most likely: a removed popup node still referenced — delete that reference; or a relative import path).

- [ ] **Step 5: Build + manual smoke**

Run: `npm run build`
Then load unpacked, open the panel on a YouTube page, and verify: it opens on the right default view per state (signed-out → Account; ready track → Study), the rail switches views with mouse + arrow keys, settings toggles persist (change one, reopen), generate is enabled only when signed in + supported, jobs list renders and links open the video, collapse closes the panel.

- [ ] **Step 6: Commit the entrypoint**
```bash
git add app/extension/entrypoints/sidepanel/index.html app/extension/entrypoints/sidepanel/dom.ts app/extension/entrypoints/sidepanel/main.ts
git commit -m "feat(extension): side-panel entrypoint reusing popup logic"
```

---

## Task 8: Manifest + background — open the panel from the toolbar action

**Files:**
- Modify: `app/extension/wxt.config.ts`
- Modify: `app/extension/entrypoints/background.ts`

- [ ] **Step 1: Add the `sidePanel` permission + font web-accessible resources**

In `wxt.config.ts`, extend the returned manifest:
```ts
permissions: ['activeTab', 'storage', 'sidePanel'],
host_permissions: ['*://*.youtube.com/*', backendApiHostPermission(backendApiBaseUrl)],
web_accessible_resources: [
  { resources: ['fonts/*'], matches: ['*://*.youtube.com/*'] },
],
```
(`sidePanel` is Chrome-only; WXT strips unknown permissions for Firefox, which uses `sidebar_action` from the entrypoint. Verify the Firefox build does not warn; if it does, gate the permission with WXT's `browser`-conditional manifest.)

- [ ] **Step 2: Open the panel when the toolbar icon is clicked (Chrome)**

In `background.ts`, inside the existing background `main()` (or add one via `defineBackground`), add:
```ts
// Chrome: clicking the toolbar action opens the side panel.
const sidePanel = (browser as unknown as { sidePanel?: { setPanelBehavior(o: { openPanelOnActionClick: boolean }): Promise<void> } }).sidePanel;
void sidePanel?.setPanelBehavior({ openPanelOnActionClick: true }).catch(() => {});
```
(Firefox opens the sidebar from its own toolbar button automatically — no code needed.)

- [ ] **Step 3: Build both targets**

Run:
```bash
npm run build
npm run build -- -b firefox
```
Expected: both succeed. Load the Chrome build; clicking the toolbar icon opens the side panel. Load the Firefox build (`web-ext run` or about:debugging); the sidebar entry is present.

- [ ] **Step 4: Commit**
```bash
git add app/extension/wxt.config.ts app/extension/entrypoints/background.ts
git commit -m "feat(extension): open side panel from the toolbar action"
```

---

## Task 9: Restyle the on-video overlay (sharp) — preserve the test contract

Rewrite `overlay-styles.ts` to the sharp look and load the bundled font inside the Shadow DOM. Do **not** change class names, `data-*`, `aria-*`, or rendered text — `tests/overlay.test.ts` is the guard.

**Files:**
- Modify: `app/extension/utils/overlay/overlay-styles.ts`
- Reference: prototype `.rail`, `.tok`/`.tok-src`/`.tok-rom`, `.rail-rom`, `.rail-tl`, `.wordcard`/`.wc-*` styles.

- [ ] **Step 1: Confirm the overlay tests pass before touching styles**

Run: `npm test -- overlay`
Expected: PASS (baseline).

- [ ] **Step 2: Load Geist in the Shadow DOM via an extension-URL `@font-face`**

At the top of the overlay style string, add `@font-face` rules pointing at the web-accessible font files, e.g.:
```ts
const fontFace = `
@font-face{font-family:'Geist Sans';font-weight:400 700;font-display:swap;src:url('${browser.runtime.getURL('fonts/geist-sans-latin-400-normal.woff2' as never)}') format('woff2');}
@font-face{font-family:'IBM Plex Mono';font-weight:400 500;font-display:swap;src:url('${browser.runtime.getURL('fonts/ibm-plex-mono-latin-400-normal.woff2' as never)}') format('woff2');}
`;
```
Copy the **actual** font filenames from `node_modules/@fontsource/*/files` into `public/fonts/` (add a build step or commit the chosen woff2 files to `app/extension/public/fonts/`) so `fonts/<name>.woff2` resolves as a web-accessible resource. Verify the path with the Task 8 `web_accessible_resources` glob.

- [ ] **Step 3: Rewrite the style string to the sharp system**

Map the existing overlay selectors to the prototype's look: `.rail` → black-glass, crimson left border, square; `.rail-meta`/`.eyebrow`/`.cue-time` → mono crimson timecode; `.token-area`/`.token-card`/`.token-text`/`.token-extra` → square stacked token cards (source above, `token-extra` romanization below, mono/muted); `.cue-romanization` → mono full-cue line; `.translation` → muted line; `.token-popover`/`.token-popover-header`/`.token-fields`/`.field`/`.field-label`/`.field-value` → the sharp word card; `.rail-controls`/`.study-control`/`.control-status` → square controls; `.rail--message`/`.title`/`.detail`/`.meta` → progress/error shell. Preserve `--accent`, position/size/density/contrast data-attribute variants, the high-contrast theme, and the existing **899px / 599px responsive breakpoints** (token row scrolls horizontally on narrow video; the rail adapts).

- [ ] **Step 4: Run the overlay tests — they must still pass unchanged**

Run: `npm test -- overlay`
Expected: PASS with **no edits to the test file**. If anything fails, you changed markup/text in a way you must not — revert that and keep the change purely in CSS.

- [ ] **Step 5: Build + visual check on a video**

Run: `npm run build`. Load unpacked, play a YouTube video with a generated track, and confirm the rail, stacked tokens (source + romanization), cue-romanization line above translation, and the word card (click a token) match the prototype.

- [ ] **Step 6: Commit**
```bash
git add app/extension/utils/overlay/overlay-styles.ts app/extension/public/fonts app/extension/wxt.config.ts
git commit -m "style(extension): sharp on-video overlay restyle with bundled font"
```

---

## Task 10: Word card — romanization above translation (markup order)

The word card already renders all metadata; reorder so the reading sits directly under the headword, above translation/gloss. `overlay.test.ts` uses substring assertions, so order changes are safe — but run it to be sure.

**Files:**
- Modify: `app/extension/utils/overlay/overlay-render.ts:399-418` (`tokenDetailRows`)

- [ ] **Step 1: Reorder the rows**

In `tokenDetailRows`, order the pushed rows as: `Text`, then `Romanization` (when `settings.showRomanization`), then `Translation`/`Gloss`, then `Part of speech`, `Lemma`, `Root`, `Usage note`. Keep the existing `.filter(row => row.value.trim() !== '')` so absent fields (e.g. `root`) are hidden. Add a `Translation` row from `token.translation` if not already present (today only `Gloss` is shown — add Translation above it):
```ts
const rows: { label: string; value: string }[] = [{ label: 'Text', value: token.text }];
if (settings.showRomanization) rows.push({ label: 'Romanization', value: token.romanization ?? '' });
rows.push({ label: 'Translation', value: token.translation ?? '' });
if (settings.showGloss) rows.push({ label: 'Gloss', value: token.gloss ?? '' });
rows.push({ label: 'Part of speech', value: token.partOfSpeech ?? '' });
rows.push({ label: 'Lemma', value: token.lemma ?? '' });
rows.push({ label: 'Root', value: token.root ?? '' });
rows.push({ label: 'Usage note', value: token.usageNote ?? '' });
return rows.filter((row) => row.value.trim() !== '');
```

- [ ] **Step 2: Run the overlay tests**

Run: `npm test -- overlay`
Expected: PASS. The pinned-token test asserts presence of `Root`, `hol`, `Usage note`, `Common greeting` (still present) — order is not asserted. If `Translation`'s new row makes a previously-translation-suppressed assertion fail, adjust only by confirming the test's fixture token has no `translation` set where the test expects none (the default fixture token has `gloss: 'hello'`, no `translation`, so a Translation row stays filtered out).

- [ ] **Step 3: Commit**
```bash
git add app/extension/utils/overlay/overlay-render.ts
git commit -m "feat(extension): order word-card reading above translation"
```

---

## Task 11: Remove the popup, relocate shared renderers, update docs

**Files:**
- Move: `entrypoints/popup/render/*`, `view-model.ts`, `tabs.ts`, `timing-control.ts` → `entrypoints/sidepanel/`
- Remove: `app/extension/entrypoints/popup/`
- Modify: `docs/FRONTEND.md`

- [ ] **Step 1: Move the shared modules into the panel**

Move `entrypoints/popup/render/`, `view-model.ts`, `tabs.ts`, `timing-control.ts` into `entrypoints/sidepanel/`. Update import paths in `sidepanel/main.ts` (and the moved render files' `../../../utils` → `../../utils`). Move any `popup/styles/*.css` rules still referenced by reused renderers into the panel stylesheet (or delete if superseded by Task 6).

- [ ] **Step 2: Delete the popup entrypoint**

Run:
```bash
git rm -r app/extension/entrypoints/popup
```
Update `tests/` imports that referenced `entrypoints/popup/...` (the Task 3 test and any popup-progress/saas tests import from `utils/`, which is unaffected; fix only the job-history test path to `entrypoints/sidepanel/render/job-history`).

- [ ] **Step 3: Compile + test + build**

Run:
```bash
npm run compile && npm test && npm run build
```
Expected: all green. The popup no longer appears in `.output/chrome-mv3/` (no `popup.html`); `sidepanel.html` is present.

- [ ] **Step 4: Update `docs/FRONTEND.md`**

Replace the popup paragraph(s) with a description of the side-panel architecture: native side panel (`entrypoints/sidepanel`), left icon rail (Generate/Study/Jobs/Account), state-aware default, persistent now-playing header, toggle via the toolbar action + collapse, sharp visual system with bundled Geist/IBM Plex Mono, and the overlay restyle. Note the transcript remains on the video pending Phase 2.

- [ ] **Step 5: Commit**
```bash
git add -A
git commit -m "refactor(extension): retire popup; relocate shared renderers to side panel; update FRONTEND.md"
```

---

## Task 12: Full verification gate

- [ ] **Step 1: Run the full suite + compile + both builds**

Run (from `app/extension/`):
```bash
npm test
npm run compile
npm run build
npm run build -- -b firefox
```
Expected: tests all pass; `tsc --noEmit` clean; both browser builds succeed.

- [ ] **Step 2: Manual acceptance on YouTube (Chrome)**

Load `.output/chrome-mv3/` unpacked and verify end-to-end:
- Toolbar icon opens the side panel; collapse closes it; reopening via the icon restores it; the page reflows beside it (never overlays).
- Default view matches state (signed-out → Account; ready track → Study); rail switches with mouse + keyboard.
- Generate flow works; progress shows; settings persist across reopen.
- Jobs grouped Videos/Shorts; rows link to the right YouTube URLs.
- On-video: sharp rail, stacked token + romanization, cue-romanization above translation, word card on token click showing all available fields with reading above translation.
- Fonts are Geist (panel + overlay), mono labels are IBM Plex Mono.

- [ ] **Step 3: Capture before/after screenshots** into `docs/design-assets/extension-sidepanel-redesign/` (panel views + overlay) for the PR.

- [ ] **Step 4: Final commit (screenshots/docs only)**
```bash
git add docs/design-assets/extension-sidepanel-redesign
git commit -m "docs(extension): Phase 1 redesign screenshots"
```

---

## Self-review notes (for the implementer)

- **Color** is intentionally inherited from the prototype tokens; the user tunes exact values after Phase 1. Do not invent a new palette.
- **Transcript** stays in the overlay this phase — do not touch its render/markup/tests. The rail has four icons.
- If `setupTabs` needs the active button pre-marked, ensure `index.html` gives the Generate `rbtn` `aria-selected="true"`; `selectDefaultView` then overrides on first state render.
- Confirm the `@fontsource` family name (`'Geist Sans'`) is what the imported CSS registers; if the package version differs, read its `*.css` to get the exact `font-family`.
