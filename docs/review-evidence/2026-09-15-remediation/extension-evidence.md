# Extension remediation evidence — September 15, 2026

R12, R13, R14 and PT03 were revalidated against current code and repaired. Existing uncommitted lyrics-validation work was preserved. Tests started only after the lead verified R01 isolation.

- **R12:** The real background message entrypoint now returns, remembers and delivers a corrected track for the same job on page entry and saved-generation refresh. The backend changes its public track ID on both Quick Fix and lyrics completion. An unchanged track ID preserves locally enriched word cards. Regressions cover delayed corrected responses after navigation, account/session change, local reset, tab removal and a newer generation selection, on both entrypoints.
- **R13:** Native automatic direction lays out source tokens in source reading order. Latin readings have explicit LTR direction; each token's text uses native paragraph isolation so `YouTube!` retains punctuation order in an RTL line. DOM order stays logical. Browser assertions cover Arabic and Hebrew at 320px and 360px, normal and editable token lines, a leading numeric token with a Latin reading, mixed Latin/punctuation, wrapping, overflow and Tab order.
- **R14:** A transcript rebuild restores the editor's focused state, selection direction/range and horizontal scroll only when that editor already had focus. The browser fixture injects another cue's word-card metadata through the real panel visibility-refresh path. Before and after: identical 82-character draft, focused input, backward selection 3–12, input scroll 120px and panel scroll 110px. A second update leaves focus in search after the user moves there. A unit regression also confirms Enter still saves the preserved draft.
- **PT03:** Repository caller search found only tests for `stageTimeline`; its builder, type and `GENERATION_STAGES` constant were removed with obsolete timeline assertions. The used progress renderer and timing-label test remain.

Verification performed:

```powershell
# In app/extension (Vitest 4.1.11): 105 tests passed across 5 files.
npm test -- --run tests/background-review.test.ts tests/transcript-view.test.ts tests/panel-transcript.test.ts tests/account-state.test.ts tests/panel-progress.test.ts
# In app/extension: passed.
npm run compile

# From the repository root, keep the fixture server running in one terminal:
node docs/review-evidence/2026-09-15-remediation/extension-fixture.cjs
# In another terminal: passed.
./docs/review-evidence/2026-09-15-remediation/extension-browser-check.ps1
```

After upgrading to WXT 0.21.4, its regenerated configuration enabled unchecked-index and override checks. Production array accesses now guard missing elements; tests declare fixed, nonempty fixtures or assert that expected listeners/calls exist. Compiler options and behavioral assertions were preserved. Final verification passed in order: `npm run compile`, all 310 extension tests across 31 files, `npm run build` (Chrome MV3, Vite 8.1.4), then `npm run compile` again against the regenerated configuration. The browser evidence above remains applicable: these follow-up changes only guard array accesses and clarify test types.

The fixture bundles the actual side-panel entrypoint and CSS with a fake `wxt/browser` transport. It binds only localhost and uses invented data; it makes no API/provider/account calls. The browser check emits [measurements](extension-browser-results.json) and screenshots. Representative captures: [Arabic editing at 320px](ara-320-edit.png), [Hebrew at 360px](heb-360-normal.png), [editor before](editor-before.png), [editor after](editor-after.png). These were visually inspected as well as checked geometrically.

**Limit:** This is real browser rendering with fake extension transport. It is not acceptance of a loaded Chrome extension on YouTube, its service-worker lifecycle, Shorts, fullscreen or cross-device network behavior. Background tests fake browser storage/API and establish the local publication guards.
