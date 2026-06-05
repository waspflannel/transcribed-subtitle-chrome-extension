# Plan: Learning Upgrade 01 - Keyboard Transcript Accessibility Foundation

Status: completed
Owner: agent
Created: 2026-06-03
Last updated: 2026-06-03

## Goal

Make generated subtitle tracks usable as a keyboard-first study surface, not only a hover-driven overlay. The learner should be able to navigate cues, replay, pause, reveal, search, and inspect the transcript without opening the popup or relying on pointer hover.

This phase establishes the accessibility and navigation foundation required by later saved-item, listening-practice, and AI-coach work.

## Scope

- In scope:
  - Global YouTube-page keyboard shortcuts for current cue replay, previous cue, next cue, toggle translation, toggle source blur, toggle auto-pause, open/close transcript, copy current cue, and save-current-cue placeholder wiring where needed for Phase 02.
  - Transcript/sidebar mode with all generated cues, active-cue highlight, search, cue jump, cue replay, cue copy, and cue metadata.
  - Keyboard parity for existing overlay token detail, reveal, replay, and copy interactions.
  - Caption display settings for overlay font size, density, and contrast theme.
  - Accessible transcript mode with clear focus order, visible focus states, ARIA labels, status copy, and no hover-only required actions.
  - Popup Study/Settings updates needed to configure shortcut/help and caption display preferences.
  - Browser visual QA for overlay, transcript/sidebar, compact layout, and keyboard focus.
- Out of scope:
  - Saved vocabulary, saved sentences, review queues, and export behavior beyond placeholders needed for future wiring.
  - New backend APIs or persistence.
  - AI coach, microphone recording, speech scoring, and content recommendations.
  - Non-YouTube platforms.

## Acceptance Criteria

- [ ] Learners can navigate to previous/current/next cues and replay the active cue with keyboard shortcuts on watch pages and Shorts.
- [ ] Shortcut handling avoids text inputs, YouTube search fields, login fields, and editable elements.
- [ ] Transcript/sidebar lists every generated cue with source text, optional translation, optional romanization, time range, active-cue highlight, search, jump, replay, and copy controls.
- [ ] Transcript/sidebar is keyboard operable from open to close and restores focus predictably.
- [ ] Existing blur/reveal behavior has keyboard parity; source words, romanization, and translation can be revealed without pointer hover.
- [ ] Overlay caption size, density, and contrast settings persist locally and update the active tab.
- [ ] All new controls have accessible names, visible focus states, and screen-reader-safe status updates.
- [ ] Existing generation, token enrichment, timing offset, overlay positions, Jobs, Usage, Account, and Settings behavior remains intact.

## Relevant Context

- Product docs: `docs/product-specs/index.md`, `docs/FRONTEND.md`
- Architecture docs: `ARCHITECTURE.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans:
  - `docs/exec-plans/completed/2026-06-01-subtitle-study-controls.md`
  - `docs/exec-plans/completed/2026-06-02-youtube-shorts-support.md`
  - `docs/exec-plans/active/00-learning-upgrade/00-roadmap-index.md`
- External references:
  - `https://www.w3.org/WAI/media/av/player/`
- Known risks:
  - YouTube already owns many keyboard shortcuts; defaults must avoid collisions or be configurable.
  - Transcript/sidebar can crowd the video on Shorts and narrow screens.
  - Focus and live-region behavior can become noisy if active cue changes update too much DOM.

## Implementation Steps

- [x] Inspect current overlay, content-script, popup, settings, and message boundaries.
- [x] Confirm the shortcut map and any YouTube shortcut conflicts before implementation.
- [x] Slice 1: extend local extension settings for shortcut enablement and caption display preferences.
- [x] Slice 2: add content-script keyboard handling for cue navigation, replay, copy, reveal toggles, translation toggle, transcript open/close, and save-current-cue placeholder status.
- [x] Slice 3: add transcript/sidebar render state, markup, active-cue sync, search, jump, replay, copy, close behavior, focus restoration, and accessible status.
- [x] Slice 4: update overlay and popup controls for keyboard parity, shortcut help, caption size, density, and contrast preferences.
- [x] Slice 5: add focused TypeScript tests for settings, shortcut dispatch, transcript filtering, active cue mapping, and accessible markup.
- [x] Capture browser screenshots or record the current browser-smoke limitation for overlay, transcript/sidebar, compact layout, and keyboard focus.
- [ ] Check the implementation against `docs/quality/golden-principles.md`.
- [ ] Update product/frontend docs and quality score if behavior changes materially.
- [ ] Run validation and record evidence.
- [ ] Complete review notes.

## Validation Plan

Commands:

```powershell
Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
.\scripts\agent\doc-gardening.ps1
```

Evidence to capture:

- Tests: focused extension tests, full extension tests, compile, build, full harness, PR verification.
- Screenshots or video: overlay shortcuts help, transcript/sidebar desktop, transcript/sidebar compact/mobile, focus states, high-contrast caption theme.
- Logs: no new generated-content payloads in logs.
- Metrics or traces: not required unless shortcut or transcript usage events are added.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-03 | Implement keyboard/transcript/accessibility before saved-item and practice features. | Later phases need cue navigation, stable focus, and transcript actions as reusable interaction surfaces. |
| 2026-06-03 | Use `Alt+Shift` shortcut chords and ignore editable targets. | YouTube owns many unmodified shortcuts, so modified chords avoid the default player controls while preserving the browser/page text-input experience. |
| 2026-06-03 | Keep transcript state in the existing content-script/Shadow DOM overlay boundary. | The generated track, active cue, video element, and replay/copy actions already live there; no backend or persisted track contract change is needed. |
| 2026-06-03 | Treat save-current-cue as a visible placeholder status only. | Saved vocabulary and sentence persistence belongs to Phase 02, but this phase can reserve the interaction safely. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-03 | Plan created. | `docs/exec-plans/active/00-learning-upgrade/01-keyboard-transcript-accessibility-foundation.md` |
| 2026-06-03 | Reviewed current extension boundaries and refined implementation slices. | `.\scripts\agent\doctor.ps1` passed; baseline `.\scripts\agent\check.ps1` passed; relevant product/frontend/architecture/review docs and prior study/Shorts plans inspected. |
| 2026-06-03 | Implemented keyboard shortcuts, transcript sidebar, caption display settings, popup controls/help, focused tests, and durable docs. | `Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location` passed with 18 test files / 79 tests. Browser screenshot automation remains blocked by existing `TD-003`/`TD-007` smoke-harness debt; Playwright was not available in the local REPL environment. |

## Completion Notes

- What changed: Added extension-local keyboard shortcut enablement plus caption size, density, and high-contrast settings; added YouTube-page shortcut handling for cue replay, previous/next cue navigation, translation visibility, source blur, hover pause, transcript open/close, cue copy, and a Phase 02 save-current-cue placeholder; added a searchable transcript sidebar with active-cue highlighting, cue metadata, jump/replay/copy/save controls, focus restoration, live status messages, and compact/high-contrast rendering; updated popup Study/Settings controls and durable docs.
- Validation results: `.\scripts\agent\doctor.ps1` passed; baseline and final `.\scripts\agent\check.ps1` passed; `Push-Location .\app\extension; npm test; npm run compile; npm run build; Pop-Location` passed with 18 test files / 79 tests; `.\scripts\agent\doc-gardening.ps1` reported no findings; `.\scripts\agent\verify-pr.ps1` passed; `git diff --check` reported no whitespace errors, only line-ending warnings.
- Simplicity/readability review: Kept behavior inside existing WXT settings, background-message, content-script, popup, and Shadow DOM overlay boundaries. Extracted shortcut dispatch and cue navigation into focused pure helpers for testability without adding backend APIs, persistence, or new dependencies.
- Residual risk: Real loaded-extension visual screenshots were not captured because the current workspace lacks browser smoke automation and Playwright was not available through the local REPL path. Existing `TD-003`/`TD-007` continue to track extension screenshot smoke coverage.
- Follow-up debt: No new debt added. The save-current-cue shortcut and transcript action intentionally remain visible placeholders for Phase 02 saved-item persistence.
