# How To Use screenshot evidence — 2026-09-16

Branch: `codex/how-to-use-website-cleanup`.

## Capture provenance

All captures use the agent-browser CLI. The manual installation image is the actual `chrome://extensions/` toolbar in isolated Chrome for Testing, with Developer mode enabled. No extension was installed and no user profile was attached.

Extension screenshots render the current `app/extension/entrypoints/sidepanel` HTML, TypeScript, CSS, and bundled fonts. The existing `docs/review-evidence/2026-09-15-remediation/extension-fixture.cjs` builds that entrypoint and serves it locally at port 8772 with fake extension transport. `sample-state.js` supplies an original example Spanish song, three short lines, and sample account data. The guide explicitly identifies this example content. No real transcript, account, or provider request is involved; corrections stop before submission.

The source PNGs are under `app/backend/public/img/guide/`. Annotations remain code-native SVG in `resources/views/components/guide-screenshot.blade.php`, with coordinates and corresponding captions defined beside the relevant instructions. Raw images are unchanged; full-size links open those captures.

## Reproducing captures

Start the local fixture from the repository root:

```powershell
node docs/review-evidence/2026-09-15-remediation/extension-fixture.cjs
```

In a second terminal, open a named agent-browser session at `http://127.0.0.1:8772/` (or `/?generation=1` for setup). Use `set viewport 460 760 2` for generation and `460 920 2` for the remaining extension views. After every navigation, apply sample state:

```powershell
$sampleScript = Get-Content -Raw docs/review-evidence/2026-09-16-guide-screenshots/sample-state.js
$sampleEncoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($sampleScript))
agent-browser --session guide-capture eval -b $sampleEncoded
```

Use `snapshot -i` and the current controls. In completed-track mode, click Refresh saved generations after applying sample state so the picker shows Spanish → English. Capture via `agent-browser --session guide-capture screenshot [selector] [absolute-path]`:

| Image | Capture and state |
| --- | --- |
| `manual-install.png` | Separate isolated session; Chrome Extensions; Developer mode on; viewport 640 × 114 at scale 2; whole viewport. |
| `generate-subtitles.png` | Generation setup; viewport 460 × 760 at scale 2; whole viewport. |
| `transcript.png` | Completed Watch track; `.watch-ready`. |
| `fix-a-word.png` | Edit first cue, select `una,`, type `luna,`; `.cue:first-child`. Do not save. |
| `replace-lyrics.png` | Cancel word draft, open Lyric correction, paste all three example lines with `luna,`, choose Continue; `[data-lyrics-edit-panel]`. Do not confirm replacement. |
| `study-display.png` | Study tab; `#panel-study > .card:first-child`. |
| `study-recall.png` | Study tab; `#panel-study > .card:nth-child(2)`. |

Measure target rectangles relative to each capture with `getBoundingClientRect()` through agent-browser eval. On UI changes, refresh the PNG, its declared dimensions, and its annotation coordinates together. Keep callout circles in empty space beside their controls, away from labels. The captions remain useful without seeing the image.

## Browser review

QA uses a separate `guide-site` agent-browser session on the local Laravel page `/how-to-use`. Source screenshots have been visually inspected, along with these integrated examples:

- `desktop-generation.png`: full desktop guide layout and numbered generation controls.
- `desktop-install.png`: manual installation toolbar and two matching captions.
- `desktop-transcript.png`: saved-generation, search, and line-action highlights.
- `desktop-lyrics.png`: full lyrics field and final replacement notice.
- `desktop-word-fix.png`: selected word, correction input, and save action.
- `desktop-study.png`: attachment, display, and timing highlights.
- `desktop-recall.png`: blur and hover-pause highlights.
- `mobile-install.png`, `mobile-generation.png`, `mobile-word-fix.png`: stacked images and captions at 390px.

Use viewport screenshots after scrolling the figure into view. The CLI's element crop produced blank output for the scrolled website document; viewport captures correctly show it. Element captures of the extension's inner scrolling panel work correctly.

## Validation

- Focused website suite: 20 passed, 293 assertions.
- Pint: passed.
- Required harness: 684 backend tests passed, 9 skipped, 5443 assertions; 342 extension tests passed across 32 files; contracts, TypeScript compile, and production build passed.
- All seven images load, have descriptive alt text, match their declared pixel dimensions, and link to their full-size source. Clicked the single-word screenshot and verified the expected image opens in a new tab.
- No browser page errors. No document or figure overflow at widths 320, 390, 768, 1024, and 1440px.
- `git diff --check`: passed. Self-review confirmed highlights avoid control labels and every numbered mark has a text explanation.
- Full check log remains in ignored local storage: `app/backend/storage/logs/guide-screenshots-check.log`.
