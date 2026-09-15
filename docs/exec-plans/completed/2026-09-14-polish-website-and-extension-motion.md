# Plan: Polish website and extension motion

Status: completed
Owner: agent
Created: 2026-09-14
Last updated: 2026-09-14

## Goal

Give the website smoother, more intentional motion and the extension a quiet, responsive feel. Preserve the existing Ink & Marker design and operational behavior.

## Scope

- Website CSS and existing reveal script: entrances, native scrolling, page/preview changes, navigation feedback, disclosures.
- Extension CSS: tab and Watch screen entrances, language and lyrics forms, switches, hover word previews, reduced motion.
- No changes to generation, billing, API contracts, caption timing, provider labels, or layout structure.

## Design Direction

- Visual thesis: the same bone paper, dark ink, and crimson marker, with crisp movement that settles softly.
- Content plan: retain the current hero, interactive preview, feature sections, pricing, and final CTA; preserve the extension's Watch, Study, History, and Account views.
- Interaction thesis: eased entrances and native scroll progress; quick scene changes and expanding disclosures; understated extension state and control feedback.

## Acceptance Criteria

- [x] Website anchors remain native, smooth, and clear of the fixed header.
- [x] Hero, preview, disclosures, and page navigation feel cohesive.
- [x] Extension screen changes and switches feel responsive without replaying animations on each progress or cue update.
- [x] Reduced motion removes movement and delays, including after changing the preference while a page is open.
- [x] Keyboard focus, long-page visibility, no-JS website content, and narrow widths remain usable.
- [x] Browser evidence and repository checks recorded.

## Relevant Context

- `docs/DESIGN.md`, `docs/FRONTEND.md`, `docs/REVIEW.md`, `docs/quality/golden-principles.md`.
- Branch: `codex/interface-motion`.
- Skills: frontend-skill, Ponytail, agent-browser. Followed backend skill routing and read its AGENTS.md; no Laravel PHP behavior changes, so no PHP implementation skill needed.
- Native CSS references: [details content](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Selectors/::details-content), [intrinsic-size interpolation](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Properties/interpolate-size), [view transitions](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/At-rules/@view-transition), [scroll timelines](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Properties/animation-timeline).

## Decisions

- Use CSS and the existing IntersectionObserver; no animation dependencies, custom scroll engine, navigation interception, or animation timers.
- Use a zero intersection threshold so tall reveal blocks still become visible on short screens. Focused reveal blocks must be visible immediately.
- Keep disclosures native. Intrinsic-size transitions and scroll/page effects are progressive enhancements; unsupported browsers retain normal navigation and disclosures.
- Animate persistent panel screen containers and hover previews, not cue rows or pinned word cards that can rebuild during enrichment.
- Broaden reduced-motion handling to remove delays and all transition/animation effects in both the panel and isolated overlay.

## Validation Plan

- `scripts/agent/check.ps1` for contracts, backend tests, extension tests/typecheck/build, docs.
- Browser website desktop and 390/320px: anchors, previews, disclosures, native page navigation/back, short viewport reveal, reduced motion and disabled JS.
- Browser extension using the real entrypoint and styles with local fixture browser messaging: tabs, language picker, switches, progress, transcript and lyrics form; verify focus and scroll remain stable during background cue updates.
- Save screenshots and inspect sampled animation frames under `docs/design-assets/interface-motion/`.

## Progress

- Inspected current website in browser and traced panel rendering, visibility, switch styles, and Shadow DOM overlay.
- Implemented CSS motion and the reveal threshold/delay refinement. Browser review and repository checks passed.

## Completion Notes

- Full `scripts/agent/check.ps1` passed: contracts checks, 568 backend tests / 4537 assertions, 277 extension tests across 31 files, TypeScript compile, Chrome production build, and docs lint. `git diff --check` passed.
- Website validated at 1440px, 390px, and 320px. Native anchor target offsets: 92px desktop, 120px mobile, clear of the fixed header. No horizontal overflow. An 830px FAQ block reveals in a 300px-tall viewport.
- FAQ expansion sampled across animation frames: 81px at 4ms, 197px at 71ms, 235px at 154ms, settling at 242px by 304ms. Native summary remains keyboard accessible.
- Native Privacy-to-Terms navigation reports an active view transition; Back returns to Privacy. Preview tab and word/lyrics toggle controls remain functional. Scroll progress uses the native scroll timeline.
- Reduced-motion preference changed live: zero running animations on the website, side panel, and Shadow DOM overlay; zero transition duration on disclosures, switches, and progress bars; native scroll behavior becomes auto. Every reveal block is visible without reloading.
- With site JS blocked: all three preview scenes and all reveal blocks remain readable. Privacy shows all 8 sections and Terms all 9 sections.
- Extension browser validation uses real panel/overlay entrypoints bundled with existing esbuild and local fixture browser messaging under ignored `app/backend/storage/app/motion-review/`. Checked Watch, Study, History, Account, language search, switches, generation progress, transcript search, lyrics form, and overlay hover preview. No browser JS errors.
- Updating generation progress to 64% causes zero screen animation restarts. A synthetic active-cue update across 24 fixture cues leaves scroll at 180px, keeps search input focus, and starts zero cue animations. The lyrics draft and focus remain intact at 320px.
- Screenshots are in `docs/design-assets/interface-motion/`. Short recordings from the automation tool did not retain the complete interaction timeline and were discarded; frame sampling and browser checks provide the motion evidence.
- Simplicity review: CSS-only extension changes, two existing website JS lines changed, no dependencies, new runtime state, navigation interception, or animation timers.
- Limits: intrinsic-size disclosure transitions and native page/scroll effects depend on browser support. Their fallback remains the existing native interaction. Fixture review does not exercise live provider generation; generation code is unchanged and its existing tests passed.
