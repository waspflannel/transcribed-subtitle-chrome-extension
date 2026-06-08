# Plan: Frontend Architecture Refactor

Status: completed
Owner: agent
Created: 2026-06-08
Last updated: 2026-06-08

## Goal

Refactor the Laravel website/account frontend and WXT extension UI into a cleaner, scalable, maintainable architecture without changing the product runtime boundary. The target is not a new frontend framework. The target is a disciplined Blade/static CSS website, a componentized browser-extension popup/overlay, clearer styling ownership, and testable view-model/rendering seams.

This plan is based on the current repository code. The main architectural debt is not that the app uses Blade or plain DOM code. Those choices are appropriate for the current product. The debt is that UI concerns are grouped by file type instead of by responsibility: one large website CSS file, one popup controller, one popup stylesheet, and one overlay module that contains lifecycle, interaction state, rendering, and CSS.

## Scope

- In scope:
  - Laravel website/account Blade views, layouts, CSS, marketing JS, and image asset usage.
  - WXT popup HTML, popup TypeScript, popup CSS, content script UI coordination, background/popup state flow, API boundary usage, and overlay rendering/styling.
  - Folder structure, component strategy, CSS structure, accessibility, responsiveness, performance, routing, and validation roadmap.
- Out of scope:
  - Backend subtitle generation, billing, auth, queues, AI providers, migrations, and API contract semantics except where frontend API usage touches them.
  - Adding React, Vue, Tailwind, or a new asset pipeline before the current no-build/static CSS approach has been exhausted.
  - Visual redesign direction decisions. The plan calls out current design-system conflicts, but does not pick new brand copy or art direction.

## Acceptance Criteria

- [x] Website CSS is split by responsibility and scoped by surface.
- [x] Blade pages use reusable anonymous components or partials for repeated UI structures.
- [x] Landing page content is data-driven instead of six hand-copied feature rows.
- [x] Website layouts do not load landing-only decorative assets on every route.
- [x] Popup rendering and event wiring are split into feature modules with typed DOM handles and pure render helpers.
- [x] Popup setting changes do not trigger backend account/history synchronization on every toggle or range input.
- [x] Overlay lifecycle, renderer, and styles are split into separate modules; deeper transcript/token renderer files are deferred because the extracted renderer is now isolated and tested through the existing public render entrypoint.
- [x] Extension API responses are guarded or schema-validated at the fetch boundary.
- [x] Accessibility fixes cover skip links, tablist keyboard behavior, live regions, image sizing/alt quality, mobile actions, and transcript/large-list behavior.
- [x] Browser smoke evidence was captured for critical website routes; automated extension overlay/popup browser smoke remains tracked by TD-003 and TD-007.

## Current Frontend Inventory

### Laravel Website And Account

- Routes are simple named Laravel routes in `app/backend/routes/web.php`:
  - Public pages: `/`, `/desktop`, `/pricing`, `/languages`, `/how-it-works`, `/faq`, `/privacy`, `/terms`, `/support`.
  - Account pages: `/dashboard`, `/dashboard/jobs/{jobId}`.
  - Auth views use Fortify routes and `resources/views/layouts/account.blade.php`.
- Public/account pages mostly extend `resources/views/layouts/site.blade.php`.
- The active homepage is `resources/views/marketing/home.blade.php` and sets `body_class` to `hermes-landing-body marketing-body`.
- Shared website styling is entirely in `app/backend/public/css/site.css`, which is about 48 KB and over 2,300 CSS lines.
- Shared website JS is `app/backend/public/js/hermes-desktop.js`, but it is loaded by `layouts.site` on all pages.
- The site currently has two asset families:
  - `app/backend/public/img/desktop/*`
  - `app/backend/public/img/marketing/dark-academia/*`

### WXT Extension

- Popup:
  - `entrypoints/popup/index.html` is a 300-line static shell with Generate, Study, Account, and Jobs panels.
  - `entrypoints/popup/main.ts` is a 900-line controller for DOM lookup, event wiring, settings, popup requests, rendering, tabs, jobs, account, usage, and errors.
  - `entrypoints/popup/style.css` is an 1,100-line stylesheet for tokens, layout, controls, picker UI, jobs, account, responsiveness.
- Content script:
  - `entrypoints/content.ts` is about 610 lines and handles YouTube route cleanup, settings, WebVTT binding, overlay lifecycle, keyboard shortcuts, cue navigation, clipboard, and token enrichment requests.
- Background:
  - `entrypoints/background.ts` is about 676 lines and owns message routing, tab subtitle state, backend calls, job polling, account session sync, local state clearing, job history recovery, and token enrichment.
- Overlay:
  - `utils/overlay.ts` is about 1,800 lines.
  - Lines 43-136 define `OverlayShell` state/lifecycle.
  - Lines 180-207 re-render via `innerHTML`.
  - Lines 533-1368 embed a full Shadow DOM stylesheet string.
  - Lines 1378-1812 render rail, transcript, token popovers, helper formatters, and search.

## Review Findings

### P0 - Website CSS Is Append-Only And Cascade-Driven

Evidence:

- `app/backend/public/css/site.css:1-27` defines global tokens.
- `app/backend/public/css/site.css:226-249` globally styles `.button, button`.
- `app/backend/public/css/site.css:1391-1895` adds a scoped `.hermes-landing-body` system.
- `app/backend/public/css/site.css:1902-2117` adds motion/polish rules.
- `app/backend/public/css/site.css:2119-2353` adds a later "CRIMSON RETHEME" that overrides earlier button, legal, nav, and feature behavior.

Why this matters:

- The file is now a chronological patch log, not a design system.
- Later rules change earlier behavior by selector weight and load order.
- Naming no longer matches intent. Example: `--brass` and `--brass-bright` are crimson reds in `site.css:13-14`.
- Broad selectors like `.marketing-body button` and `.hermes-landing-body button` in `site.css:2125-2130` make future form/control styling unpredictable.

Refactor direction:

- Split by layer and surface:
  - foundation tokens and reset
  - base typography/layout
  - shared UI primitives
  - marketing pages
  - landing page
  - account/dashboard
  - auth
  - motion
- Remove broad `button` styling from surface files. Style `.ui-button`, `.button`, or component-specific button classes only.
- Keep `site.css` as an import manifest initially so layout references do not need to change in the first pass.

### P0 - Popup Setting Changes Can Trigger Backend Sync On Every Toggle And Slider Input

Evidence:

- Popup timing range uses `input` events in `app/extension/entrypoints/popup/main.ts:173`.
- Every settings change calls `updateSettings()` at `main.ts:211-213`.
- `updateSettingsFromPopup()` in `app/extension/entrypoints/background.ts:112-124` updates storage, sends settings to the tab, then returns `getPopupState({ syncBackend: true })`.
- `getPopupState()` syncs account and job history when `syncBackend` is true at `background.ts:457-463`.

Why this matters:

- Dragging the timing slider can cause repeated extension storage writes, tab messages, account sync, and job-history requests.
- Simple display toggles should not fetch account/history state.
- This creates unnecessary backend pressure and makes popup responsiveness depend on network state.

Refactor direction:

- Settings updates should return a locally derived popup state with `syncBackend: false`.
- Keep backend account/history sync on popup open, manual refresh, sign-in/sign-out, generation start/completion, and periodic refresh.
- Debounce range updates or commit range on `change` while updating the local output immediately on `input`.

### P1 - Layouts Load Landing-Specific Assets Globally

Evidence:

- `app/backend/resources/views/layouts/site.blade.php:37-38` always loads `site.css` and `hermes-desktop.js`.
- `app/backend/resources/views/layouts/site.blade.php:41` always loads `img/desktop/filler-bg0.webp` as a fixed decorative texture.
- `app/backend/public/js/hermes-desktop.js` is generic reveal/nav behavior now, but the name ties it to one design pass.

Why this matters:

- Every public/account page pays for a 931 KB decorative image from `public/img/desktop/filler-bg0.webp`.
- The JS file name and docs make ownership unclear.
- The layout cannot cleanly answer which assets belong to landing, public content, auth, dashboard, or all pages.

Refactor direction:

- Rename `hermes-desktop.js` to `site-interactions.js` or `marketing-interactions.js`.
- Add `@stack('styles')` and `@stack('scripts')` to `layouts.site`.
- Make landing-only textures/images opt-in from the landing view or landing layout.
- Keep account/dashboard pages free of decorative landing assets.

### P1 - Website Componentization Is Almost Absent

Evidence:

- `resources/views/marketing/home.blade.php:35-107` hand-repeats six feature-row blocks.
- `resources/views/dashboard.blade.php:39-196` repeats panel, panel-heading, metric, billing, plan, extension, support, and form structures inline.
- Auth views repeat the same error list, status, field, action row, and button patterns in `resources/views/auth/*.blade.php`.
- `resources/views/account/job-show.blade.php` repeats panel/metric/status patterns used by the dashboard.

Why this matters:

- CSS classes become the only reusable abstraction.
- Markup/a11y improvements must be applied in many views by hand.
- Repeated copy/structure invites drift.

Refactor direction:

- Use anonymous Blade components for reusable UI primitives and page sections:
  - `x-ui.button`
  - `x-ui.panel`
  - `x-ui.page-hero`
  - `x-ui.status-pill`
  - `x-ui.metric-grid`
  - `x-form.field`
  - `x-form.error-list`
  - `x-marketing.feature-row`
  - `x-marketing.plan-card`
  - `x-account.job-table`
- Move homepage feature data to the controller or local Blade data array and render with a loop.

### P1 - Product/Design Direction Is Internally Inconsistent

Evidence:

- `MarketingPageController::metadata()` sets home and desktop titles to `Hermes Desktop | Nous Research` at `app/backend/app/Http/Controllers/MarketingPageController.php:95-103`.
- The active homepage renders `Transcribed Subtitle Extension` at `home.blade.php:15-19`.
- `docs/DESIGN.md:23` references `resources/views/layouts/hermes-desktop.blade.php`, but that file is not present.
- `docs/superpowers/specs/2026-06-06-marketing-hermes-revamp-design.md:125` says the old `layouts.hermes-desktop` path is retired/not present.
- `home.blade.php:72-101` claims Export, Offline PDF/text, and Local processing, while the active product docs do not clearly expose those website promises as implemented frontend workflows.

Why this matters:

- SEO metadata, docs, CSS names, image folders, and homepage copy disagree.
- Future contributors cannot tell whether the site is a Hermes override, a dark-academia product site, or a hybrid.
- Product claims can drift ahead of implementation.

Refactor direction:

- Decide whether `/` and `/desktop` are product pages or a temporary Hermes-inspired landing.
- Rename classes and files around the chosen product identity, not the inspiration source.
- Remove or quarantine unimplemented feature claims.
- Update `docs/DESIGN.md` after the code structure is corrected.

### P1 - Overlay Module Is A God Object

Evidence:

- `app/extension/utils/overlay.ts:43-136` owns shell state.
- `overlay.ts:209-308` binds token, study, and transcript events.
- `overlay.ts:533-1368` contains all overlay CSS as a template literal.
- `overlay.ts:1378-1812` contains render functions, transcript filtering, token popover rendering, controls, and formatters.

Why this matters:

- Styling changes require editing a TypeScript module.
- Render tests must import a module that also contains DOM lifecycle and CSS.
- Interaction state, transcript state, token state, and shell lifecycle are hard to reason about independently.

Refactor direction:

- Split overlay into:
  - `overlay/OverlayShell.ts`
  - `overlay/overlay-render.ts`
  - `overlay/transcript-render.ts`
  - `overlay/token-render.ts`
  - `overlay/overlay-state.ts`
  - `overlay/overlay-styles.ts` or `overlay.css` imported as raw/inline CSS after WXT build support is confirmed.
- Keep `utils/overlay.ts` as a temporary re-export during migration.

### P1 - Popup Main File Mixes DOM Registry, Controller, State Derivation, And Rendering

Evidence:

- `popup/main.ts:64-122` declares every DOM node at module scope.
- `popup/main.ts:130-184` wires all event listeners.
- `popup/main.ts:379-423` applies a full popup state.
- `popup/main.ts:451-533` renders language pickers.
- `popup/main.ts:535-645` renders job history.
- `popup/main.ts:661-688` renders account feature rows.

Why this matters:

- New popup features will keep expanding one file.
- A missing selector fails through non-null assertions instead of a typed setup check with a useful message.
- Rendering and state transformation cannot be tested independently except by pulling the whole entrypoint into a DOM-like environment.

Refactor direction:

- Split popup by feature:
  - `popup/dom.ts`: typed DOM handles and setup assertions.
  - `popup/controller.ts`: message senders and top-level orchestration.
  - `popup/view-model.ts`: derive display state from `PopupState`.
  - `popup/render/language-picker.ts`
  - `popup/render/job-history.ts`
  - `popup/render/account.ts`
  - `popup/render/usage.ts`
  - `popup/render/progress.ts`
  - `popup/tabs.ts`
  - `popup/timing-control.ts`
- Keep `main.ts` as bootstrapping only.

### P2 - API Boundary Is Typed But Not Runtime-Validated In The Extension

Evidence:

- `SubtitleApiClient.request()` returns `body as TResponse` at `app/extension/utils/api.ts:152-157`.
- The previous architecture report already flags this as a medium issue in `docs/architecture-review-report-2026-05-20.md:558`.

Why this matters:

- The extension is the untrusted-client boundary. Bad, stale, or malformed backend JSON can enter popup/content state and fail later in rendering.
- The repo has a contract-first posture, but the extension does not enforce response shape at runtime.

Refactor direction:

- Add narrow response guards generated from or checked against `packages/contracts`.
- Validate at the API client boundary, then trust typed values inside popup/content/background.
- Start with guards for `JobResponse`, `SubtitleJobHistoryResponse`, `TrackResponse`, `ExtensionAuthResponse`, `ExtensionAccountResponse`, and `LearningTokenResponse`.

### P2 - Accessibility Is Partially Implemented But Not Systemic

Evidence:

- Good:
  - Popup labels wrap most controls in `popup/index.html`.
  - Account feedback has `role="status"` and `aria-live="polite"` at `popup/index.html:232`.
  - Overlay transcript controls have explicit labels at `overlay.ts:1667-1678`.
  - Focus-visible styles exist in popup CSS at `popup/style.css:137-143`.
- Gaps:
  - `layouts/site.blade.php:42-66` has no skip link or `main id`.
  - Popup tab buttons use `role="tab"` in `popup/index.html:37-42`, but `showTab()` in `popup/main.ts:710-720` only handles click and does not implement arrow-key tablist behavior.
  - Popup status text at `popup/index.html:49` updates dynamically without `role="status"` or `aria-live`.
  - Overlay focus-visible rules use `outline: none` in `overlay.ts:663`, `overlay.ts:758`, `overlay.ts:984`, and `overlay.ts:1093`; they have box-shadow replacements, but this should be standardized rather than repeated ad hoc.
  - Homepage images in `home.blade.php:29`, `44`, `56`, `68`, `80`, `92`, `104`, and `120` lack explicit `width`/`height`; below-fold images also lack lazy loading.

Refactor direction:

- Add a shared skip link and `main id="main-content"`.
- Add tablist keyboard support or remove `role="tablist"` and use simpler segmented navigation semantics.
- Put all dynamic status areas behind reusable live-region helpers.
- Standardize focus-visible styles in CSS primitives.
- Add explicit dimensions, loading hints, and better alt text rules.

### P2 - Responsiveness Exists, But It Is Mostly Breakpoint Patching

Evidence:

- Site CSS uses breakpoints at `site.css:1193`, `1242`, `1609`, `1647`, `1856`, `1883`, and `2265`.
- At mobile width, `.site-actions` is hidden at `site.css:1249-1251`, removing Sign in/Download from the header without a mobile replacement.
- Landing hero uses `min-height: 100svh` at `site.css:1520`, which can prevent the next section hint required by the design guidelines.
- Popup has one mobile breakpoint at `popup/style.css:1112-1138`.
- Overlay mobile behavior relies on horizontal token scrolling at `overlay.ts:1312-1317`.

Why this matters:

- Responsive behavior is not documented as component behavior.
- Important navigation/actions can disappear on small screens.
- Overlay controls can fight YouTube controls and mobile safe areas.

Refactor direction:

- Define responsive rules per component, not as late global patches.
- Add a mobile nav/action pattern for Sign in/Download.
- Add safe-area variables for overlay positioning.
- Test 320, 360, 390, 768, 1024, and desktop widths for the primary website and popup surfaces.

### P2 - Performance Debt Is Mostly Asset And Render Churn

Evidence:

- `layouts/site.blade.php:41` loads a 931 KB decorative image on every site page.
- `public/img/desktop/feature-tasks.webp` is about 1 MB; several images are 500-900 KB.
- `public/img/marketing/dark-academia/*.png` are 1.4-3.2 MB and are not used by the current active homepage.
- Overlay transcript rendering maps every cue in `overlay.ts:1625-1635`.
- Overlay re-renders by replacing `innerHTML` at `overlay.ts:196-203`, then rebinds individual listeners.
- Popup job history and language lists also render HTML strings through `innerHTML` at `popup/main.ts:485-493`, `552-555`, and `673-688`.

Why this matters:

- Website pages may load decorative or unused heavy assets.
- Long generated tracks can produce large transcript DOM trees.
- Frequent cue changes can cause repeated DOM work in the overlay.

Refactor direction:

- Use local image dimensions, `fetchpriority="high"` only for true above-fold hero image, and `loading="lazy"` for below-fold assets.
- Compress or retire unused asset families after design direction is decided.
- For transcript lists over 50 cues, add windowing or at minimum `content-visibility: auto` and event delegation.
- Change overlay binding to delegate token/transcript clicks from the content root instead of adding listeners to every rendered button.

### P3 - Naming Collisions And Generic Classes Reduce Legibility

Evidence:

- Website uses generic classes like `.panel`, `.section`, `.feature-row`, `.button`, `.status`, `.surface`, `.workspace`.
- Popup separately uses `.panel`, `.surface`, `.feature-row`, `.status`, `.button-row`.
- Landing classes mix `.hermes-*`, `.da-*`, and `.feature-*`.

Why this matters:

- The popup CSS is isolated by bundle, so this is not a runtime collision today, but it is a human collision.
- The class names do not clearly tell contributors which surface owns a component.

Refactor direction:

- Adopt explicit namespaces:
  - `ui-*` for shared primitives.
  - `site-*` for global site shell.
  - `mkt-*` for non-home marketing pages.
  - `landing-*` for the landing page.
  - `dash-*` or `account-*` for authenticated account views.
  - `popup-*` for popup-specific classes.
  - `overlay-*` for Shadow DOM overlay classes.
- Keep domain names where they clarify behavior, such as `language-picker`, `job-history`, `stage-timeline`, and `token-card`.

## Target Architecture

### Website Folder Structure

```text
app/backend/
  resources/views/
    components/
      form/
        error-list.blade.php
        field.blade.php
      layout/
        footer.blade.php
        header.blade.php
        skip-link.blade.php
      marketing/
        feature-row.blade.php
        page-hero.blade.php
        plan-card.blade.php
      ui/
        button.blade.php
        metric-grid.blade.php
        panel.blade.php
        status-pill.blade.php
    layouts/
      site.blade.php
      account.blade.php
    marketing/
      home.blade.php
      pricing.blade.php
      languages.blade.php
      how-it-works.blade.php
      faq.blade.php
      privacy.blade.php
      terms.blade.php
      support.blade.php
    account/
      job-show.blade.php
    auth/
      login.blade.php
      register.blade.php
      forgot-password.blade.php
      reset-password.blade.php
      verify-email.blade.php
    dashboard.blade.php
  public/
    css/
      site.css
      site/
        00-tokens.css
        01-reset.css
        02-base.css
        03-layout.css
        04-ui.css
        05-marketing.css
        06-landing.css
        07-account.css
        08-auth.css
        09-motion.css
    js/
      site-interactions.js
    img/
      marketing/
        landing/
        dark-academia/
```

Implementation note:

- Keep `public/css/site.css` as the only linked CSS file at first and use `@import` to avoid a risky layout change. If browser-level `@import` waterfall is a concern after measurement, replace it with multiple `<link>` tags in `layouts.site`.

### Extension Folder Structure

```text
app/extension/
  entrypoints/
    background.ts
    content.ts
    popup/
      index.html
      main.ts
      dom.ts
      controller.ts
      tabs.ts
      timing-control.ts
      view-model.ts
      render/
        account.ts
        errors.ts
        job-history.ts
        language-picker.ts
        progress.ts
        settings-summary.ts
        shortcuts.ts
        usage.ts
      styles/
        tokens.css
        base.css
        layout.css
        controls.css
        language-picker.css
        account.css
        jobs.css
        responsive.css
      style.css
  utils/
    overlay/
      OverlayShell.ts
      overlay-render.ts
      overlay-state.ts
      overlay-styles.ts
      transcript-render.ts
      token-render.ts
      format.ts
    overlay.ts
    api.ts
    api-response-guards.ts
    messages.ts
    settings.ts
    settings-model.ts
    ...
```

Implementation note:

- Keep `utils/overlay.ts` as a compatibility re-export until all imports and tests are moved.
- If WXT/Vite raw CSS import support is used for Shadow DOM CSS, verify current WXT docs before implementation. Otherwise keep a separate `overlay-styles.ts` exporting the stylesheet string.

## Componentization Strategy

### Blade Components

Create components only where repetition is real:

- `x-layout.header` and `x-layout.footer`: site shell, nav, auth actions, active route state.
- `x-layout.skip-link`: skip to `main-content`.
- `x-ui.button`: anchor/button variants with consistent classes and disabled handling.
- `x-ui.panel`: repeated dashboard/job/auth/public card frame.
- `x-ui.metric-grid`: semantic `dl` metric display.
- `x-ui.status-pill`: `completed`, `running`, `queued`, `processing`, `failed`.
- `x-ui.page-hero`: public page intro pattern.
- `x-form.field`: label/input wrapper with error slot.
- `x-form.error-list`: consistent auth error rendering.
- `x-marketing.feature-row`: landing feature media/text row.
- `x-marketing.plan-card`: pricing plan card.
- `x-account.job-table`: dashboard recent jobs table.

Do not componentize one-off legal paragraphs or tiny copy-only blocks. The first pass should remove duplication, not introduce a component library for its own sake.

### Popup Components

Keep the popup as vanilla TypeScript/DOM, but isolate render sections:

- `language-picker.ts`: selected summary, option buttons, filter result.
- `job-history.ts`: grouping, item, actions, stage timeline.
- `account.ts`: account facts, login state, features.
- `usage.ts`: usage summary, meter width, reset copy.
- `progress.ts`: generation progress bar.
- `shortcuts.ts`: keyboard shortcut display.
- `tabs.ts`: tab state and keyboard behavior.

Each renderer should be pure enough to test with strings or simple DOM fixtures. `main.ts` should not know how a job card is built.

### Overlay Components

Split by actual UI behavior:

- Shell lifecycle:
  - mount/unmount
  - host dataset updates
  - focus restoration
  - transcript open state
  - delegated event handling
- Rail renderer:
  - unsupported/loading/error/no-track shell
  - active cue rail
  - study controls
- Token renderer:
  - token button
  - hover preview
  - pinned detail
  - loading/failed token states
- Transcript renderer:
  - panel shell
  - search
  - cue list
  - cue actions
- Styles:
  - Shadow DOM stylesheet in one isolated file/module.

## CSS And Styling Structure

### Website CSS Rules

- Token file owns raw colors, spacing, radii, typography names, z-indexes, and motion timings.
- Raw hex/rgba values should not appear outside token files except for one-off high-contrast browser-native fixes.
- No broad `button` rules outside base reset. Use `.ui-button`, `.text-button`, `.nav-link`, or component-specific classes.
- Avoid `transition: all`; list properties explicitly.
- Keep focus visible by default. No `outline: none` unless the same rule provides a clear replacement and the pattern is centralized.
- Avoid class names tied to inspiration sources. Use `landing-*`, not `hermes-*`, if the page is a product landing page.
- Keep page sections full-width and component frames local.
- CSS file target:
  - token/base files under 250 lines
  - surface files under 500 lines
  - no single UI stylesheet over 700 lines without an explicit reason.

### Extension CSS Rules

- Popup CSS should be split into imported component files under `entrypoints/popup/styles`.
- Prefix generic popup-only classes with `popup-` where they are not already domain-specific.
- Overlay styles should use an `overlay-*` or local Shadow DOM naming scheme consistently.
- Use CSS custom properties in overlay style for repeated colors and focus shadows rather than repeating `#f1f1f1`, `#e85d5d`, and `rgba(216, 59, 59, ...)` dozens of times.
- Add safe-area-aware overlay positioning:

```css
:host {
  bottom: calc(82px + env(safe-area-inset-bottom));
  left: max(16px, env(safe-area-inset-left));
  right: max(16px, env(safe-area-inset-right));
}
```

## Files To Split, Move, Rename, Or Delete

### Split

- `app/backend/public/css/site.css`
  - Split into `public/css/site/*.css`.
- `app/extension/entrypoints/popup/main.ts`
  - Split into DOM, controller, view-model, render modules, tabs, timing control.
- `app/extension/entrypoints/popup/style.css`
  - Split into `popup/styles/*.css`.
- `app/extension/utils/overlay.ts`
  - Split into overlay shell, renderers, styles, formatters, state.
- `app/extension/entrypoints/background.ts`
  - Later split only after popup/overlay work:
    - message router
    - popup state assembly
    - subtitle generation polling
    - token enrichment

### Move Or Rename

- Rename `app/backend/public/js/hermes-desktop.js` to `site-interactions.js` or `marketing-interactions.js`.
- Move active landing images from `public/img/desktop/` to `public/img/marketing/landing/` if they remain product assets.
- Rename `.hermes-landing-body`, `.hermes-container`, and `.da-*` classes to product-neutral `landing-*` names after design direction is settled.
- Rename `--brass` and `--brass-bright` tokens if they remain red/crimson.

### Delete After Verification

- Remove unreferenced `public/img/desktop/badge.webp`, `nous.webp`, and `platform-art-*.webp` if no future `/desktop` download/platform section is retained.
- Remove stale doc references to `resources/views/layouts/hermes-desktop.blade.php` or recreate that layout only if it becomes the actual landing layout.
- Remove old dark-academia CSS blocks that no active view uses after the split proves route parity.
- Remove unused controller data, such as `featuredLanguages` passed to the active home view if it remains unused.

## Coding Standards And Conventions

- Keep the existing Laravel + WXT architecture. Do not introduce a SPA framework for this refactor.
- Use named Laravel routes and Blade components for website navigation and repeated markup.
- Use one source of truth for product metadata. Website titles must match the product unless a deliberate temporary override is documented.
- Validate untrusted data at boundaries:
  - API responses in the extension client
  - runtime messages in `messages.ts`
  - external page/video state from YouTube helpers
- After validation, pass typed values through feature modules without repeated defensive checks.
- Prefer data arrays plus loops for repeated static page sections.
- Keep render helpers pure where practical.
- Keep direct DOM code for popup/content entrypoints, but make the entrypoint orchestrate modules rather than own all rendering.
- Avoid hidden compatibility layers. When a design era is retired, remove its classes/assets/docs together.
- Promote repeated CSS/accessibility feedback into tests or scripts:
  - no `transition: all`
  - no missing image dimensions in Blade homepage/media components
  - no stale `Hermes` metadata unless a plan explicitly allows it
  - no global `button` selector in surface CSS

## Performance Improvements

- Remove global decorative image loading from `layouts.site`.
- Add `width` and `height` for all website images.
- Add `loading="lazy"` for below-fold homepage feature/final images.
- Add `fetchpriority="high"` only to the true first-viewport hero image.
- Compress or retire large unused PNG/WebP assets after the design decision.
- Avoid backend sync on every popup setting update.
- Debounce popup timing slider persistence.
- Use event delegation for overlay token and transcript actions.
- Add transcript list virtualization or `content-visibility: auto` for long generated tracks.
- Keep popup language search cheap; if the language catalog grows materially, memoize normalized search text once.

## Accessibility Improvements

- Add skip link and `main id="main-content"` in `layouts.site` and `layouts.account`.
- Add visible focus styles as reusable CSS primitives.
- Add `role="status"` and `aria-live="polite"` to popup status/progress areas that change after backend messages.
- Implement tablist arrow-key navigation or simplify popup tabs to normal buttons without tablist roles.
- Add image dimensions and alt-text rules:
  - decorative images: `alt="" aria-hidden="true"`
  - product/feature images: specific alt text, not "Product Preview"
- Ensure the hidden mobile header actions have a replacement path.
- Use `Intl.DateTimeFormat` in extension display helpers rather than default `toLocale*` calls if deterministic locale behavior matters.
- For overlay transcript:
  - keep Escape close behavior
  - preserve focus return
  - consider `role="dialog"` if the transcript blocks interaction, or keep `complementary` if it remains a non-modal side panel.

## Routing And State Management Improvements

### Website Routing

- Keep named routes.
- Add active nav state in `x-layout.header`.
- Ensure `/` and `/desktop` canonical/title behavior matches the final product decision.
- Remove route data that the view no longer renders.

### Popup/Extension State

- Treat `background.ts` as the extension state authority, but split state assembly from message routing.
- Add a `PopupViewModel` derived from `PopupState`:
  - supported page state
  - button labels/enabled states
  - visible account state
  - usage meter state
  - progress display
  - language picker display
- Settings changes should update local state immediately and schedule backend refresh separately.
- Use narrower message handlers:
  - popup state
  - settings
  - generation
  - account
  - token enrichment

## Prioritized Refactor Roadmap

### Phase 0 - Lock Inventory And Add Guardrails

Goal:

- Prevent the current architecture from getting worse before splitting files.

Work:

- Add a simple CSS grep/lint script for:
  - `transition: all`
  - global surface `button` selectors
  - `outline: none`
  - stale `Hermes Desktop | Nous Research` metadata if not explicitly allowed
- Add route screenshot checklist for homepage, pricing, login, dashboard, job detail.
- Decide and document whether the current landing is product-branded or a temporary Hermes override.

Validation:

- `.\scripts\agent\check.ps1`
- Targeted website feature tests.

### Phase 1 - Website CSS And Asset Quick Wins

Goal:

- Make website styling navigable without changing visual output.

Work:

- Split `site.css` into imported partials.
- Rename `hermes-desktop.js` to `site-interactions.js`.
- Restrict the global texture image to landing pages or remove it.
- Add image dimensions/loading/fetch priority to homepage images.
- Remove unused image assets after reference check.
- Fix `MarketingPageController` metadata for product consistency or document the deliberate override.
- Fix `docs/DESIGN.md` stale `layouts/hermes-desktop.blade.php` reference.

Validation:

- `php artisan test --compact tests/Feature/SaasWebsiteAndSeoTest.php tests/Feature/WebAuthTest.php`
- Browser screenshots for `/`, `/pricing`, `/login`, `/dashboard`, job detail at desktop/mobile widths.

### Phase 2 - Blade Componentization

Goal:

- Replace duplicated markup with reusable components while keeping routes and controllers stable.

Work:

- Create `x-ui.panel`, `x-ui.button`, `x-ui.metric-grid`, `x-ui.status-pill`, `x-ui.page-hero`, `x-form.field`, `x-form.error-list`.
- Convert auth views first because the forms are small and repeated.
- Convert dashboard/job detail panels and metrics.
- Convert pricing cards and homepage feature rows.
- Move homepage feature rows to data-driven rendering.

Validation:

- Existing feature tests plus added assertions for skip link, page hero, form labels/errors, and status pill rendering.

### Phase 3 - Popup Architecture Refactor

Goal:

- Reduce `popup/main.ts` to bootstrapping and orchestration.

Work:

- Create typed DOM registry.
- Extract pure render modules.
- Extract popup view model.
- Fix settings update path to avoid backend sync on every toggle.
- Debounce/persist timing slider sanely.
- Add tablist keyboard support and live-region status.
- Split popup CSS into component files.

Validation:

- Existing Vitest suite.
- New tests for popup view model and render modules.
- Manual or automated popup screenshot at 320 px and 400 px.

### Phase 4 - Overlay Refactor

Goal:

- Separate overlay shell, renderer, transcript, token UI, and styles.

Work:

- Move Shadow DOM CSS out of `OverlayShell`.
- Extract render functions into focused modules.
- Replace per-render per-button listener binding with delegated event handling.
- Add transcript large-list strategy.
- Add safe-area-aware positioning.
- Standardize focus-visible replacement.

Validation:

- Existing `overlay.test.ts` moved to renderer-specific tests.
- Add tests for transcript large-list behavior and delegated action dispatch.
- Browser smoke screenshot of overlay on a deterministic video fixture or YouTube-compatible test page.

### Phase 5 - API Boundary And Browser Smoke

Goal:

- Make frontend runtime contracts and user-visible behavior safer.

Work:

- Add extension API response guards.
- Add a built-extension smoke harness tracked by existing TD-003/TD-007.
- Add website responsive/a11y smoke assertions:
  - no horizontal overflow
  - skip link exists
  - images have dimensions
  - key routes render without console errors
- Add extension popup/background/content smoke:
  - popup opens
  - content script injects overlay host
  - ready track renders
  - transcript opens/closes
  - keyboard shortcuts do not fire in editable targets

Validation:

- `.\scripts\agent\check.ps1`
- `npm test`
- `npm run compile`
- `npm run build`
- Browser smoke artifacts in the relevant execution plan or PR summary.

## Quick Wins

- Rename `hermes-desktop.js` and update references.
- Fix product metadata in `MarketingPageController::metadata()`.
- Remove or scope the global `site-texture` image.
- Add skip link and `main id`.
- Add dimensions and lazy loading to homepage images.
- Change popup settings update to avoid `syncBackend: true`.
- Debounce timing slider persistence.
- Replace `transition: all` at `site.css:1691`.
- Add arrow-key behavior for popup tabs.
- Move overlay CSS string into its own module.

## Larger Changes

- Website CSS split plus selector cleanup.
- Blade component migration.
- Popup render/view-model extraction.
- Overlay module split plus event delegation.
- Extension API response guards.
- Browser smoke automation.
- Asset family cleanup after final landing/design direction.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/references/project-guardrails.md`
- Frontend/design docs: `docs/FRONTEND.md`, `docs/DESIGN.md`
- Quality rules: `docs/quality/golden-principles.md`
- Previous architecture review: `docs/architecture-review-report-2026-05-20.md`
- Related completed plans:
  - `docs/exec-plans/completed/2026-06-05-dark-academia-stitch-website-refactor.md`
  - `docs/exec-plans/completed/2026-06-05-hermes-black-theme-landing-page.md`
- Web interface rules consulted: Vercel Web Interface Guidelines, fetched 2026-06-08.

## Known Risks

- Splitting CSS without a visual baseline can accidentally change cascade behavior.
- Renaming classes tied to design-era names can break tests/screenshots if done before the brand direction is settled.
- Blade componentization can become over-abstracted if tiny one-off blocks are extracted.
- Overlay refactoring can regress focus restoration and cue-change responsiveness.
- API response guards can add maintenance cost if not derived from or checked against the shared contracts.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-08 | Keep Laravel Blade and WXT/plain DOM as the target architecture. | The current stack is appropriate; the problem is organization and boundaries, not framework choice. |
| 2026-06-08 | Split frontend by surface and component responsibility. | Existing files are grouped by asset type, causing large CSS/TS files and weak ownership. |
| 2026-06-08 | Prioritize website CSS, asset loading, and popup settings sync before deeper module splits. | These are high-impact, low-risk fixes grounded in current code. |
| 2026-06-08 | Keep `site.css` and popup `style.css` as import manifests for the first refactor pass. | This preserves existing layout/build references while moving ownership into smaller files. |
| 2026-06-08 | Keep `utils/overlay.ts` as the public compatibility entrypoint. | Content script and tests can keep importing the shell/render API while implementation moves under `utils/overlay/`. |
| 2026-06-08 | Use manual browser smoke evidence now and track automated UI smoke as follow-up debt. | The repo already tracks extension smoke gaps in TD-003/TD-007; website guardrails are tracked in TD-015. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-08 | Architectural review completed and refactor plan created. | Inspected project docs, website views/CSS/JS, extension popup/content/background/overlay code, route/controller shape, tests, and current tech-debt docs. |
| 2026-06-08 | Website CSS/layout/component slice implemented. | `site.css` imports split files under `public/css/site/`; `site-interactions.js` replaces `hermes-desktop.js`; skip links/main targets added; landing texture scoped to homepage; homepage images have dimensions/loading hints; landing features render through `x-marketing.feature-row`; auth/dashboard/job views use shared Blade components. |
| 2026-06-08 | Popup/background slice implemented. | Popup CSS imports split files under `popup/styles/`; `main.ts` now uses typed DOM registry, render modules, tab module, timing control, and view-model helpers; tablist keyboard behavior and live regions added; background settings updates now return non-sync popup state and preserve cached job history. |
| 2026-06-08 | Overlay/API boundary slice implemented. | Overlay styles, renderer, and shared types moved under `utils/overlay/`; `utils/overlay.ts` remains the compatibility entrypoint; transcript cues use CSS containment; safe-area positioning added; API response guards added and covered by a malformed-response test. |
| 2026-06-08 | Validation completed. | Baseline and final `.\scripts\agent\check.ps1` passed; `.\scripts\agent\verify-pr.ps1` passed; `.\scripts\agent\doc-gardening.ps1` reported no findings; browser smoke checked `/`, `/pricing`, `/login`, and 360px `/` + `/pricing`. |

## Validation Plan

Commands used for this implementation:

```powershell
cd app\backend
php artisan test --compact tests\Feature\SaasWebsiteAndSeoTest.php tests\Feature\WebAuthTest.php
cd ..\extension
npm test
npm run compile
npm run build
cd ..\..
.\scripts\agent\check.ps1
```

Evidence to capture:

- Tests: targeted Laravel feature tests, Vitest renderer/view-model tests, full harness.
- Screenshots or video: homepage, pricing, auth, dashboard, job detail, popup at 320/400 px, overlay rail/transcript.
- Logs: browser console clean on key routes and extension smoke.
- Metrics or traces: optional asset-size comparison before/after removing global texture and unused images.

## Completion Notes

- What changed: Implemented the first architectural refactor across the Laravel website/account frontend and WXT extension UI. Styling is split by surface, repeated Blade structures have reusable components, landing content is data-driven, popup rendering/state/tabs/timing are modularized, settings updates avoid backend history/account sync, overlay shell/render/styles are split, and extension API responses are runtime-guarded.
- Validation results: `php artisan test --compact tests\Feature\SaasWebsiteAndSeoTest.php tests\Feature\WebAuthTest.php` passed; `npm test` passed with 81 tests; `npm run compile` passed; `npm run build` passed; final `.\scripts\agent\check.ps1` and `.\scripts\agent\verify-pr.ps1` passed; `.\scripts\agent\doc-gardening.ps1` reported no findings.
- Browser evidence: local Laravel server at `http://127.0.0.1:8126` rendered `/`, `/pricing`, and `/login` with no browser console errors; skip links and `main#main-content` were present; homepage main images had explicit dimensions; landing texture count was 1 on `/` and 0 on `/pricing` and `/login`; 360px checks for `/` and `/pricing` had no horizontal overflow and exposed mobile Sign in/Download nav actions.
- Simplicity/readability review: The refactor kept Laravel Blade and WXT/plain DOM, used import manifests to preserve existing asset references, kept `utils/overlay.ts` as a public compatibility entrypoint, and added narrow boundary guards instead of a broad validation framework.
- Residual risk: CSS visual parity still deserves screenshot review across more authenticated/account states; popup and overlay browser automation is still limited by the existing extension smoke gaps.
- Follow-up debt: TD-003 and TD-007 remain open for built-extension/YouTube smoke automation; TD-015 tracks website/front-end CSS guardrails and automated responsive smoke coverage.
