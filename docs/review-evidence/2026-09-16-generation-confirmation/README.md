# Generation confirmation browser evidence — September 16, 2026

Every Generate subtitles action, including retries and Generate again, opens a native modal before the generation request. The dialog shows the video, language pair, model and estimated full-video plan minutes, then says:

> You can cancel at any time, but the minutes for the entire video will still be used and won't be refunded. Would you like to proceed?

Go back receives initial focus. Start generation is explicit. Escape and Go back return focus to Generate without starting work. Cancellation feedback is neutral: “Generation cancelled.” No early-cancellation exception is disclosed.

The panel invalidates consent when the account, active tab/video, duration, languages, provider or generation output settings change. Settings writes block confirmation until they finish. Generation messages carry the confirmed context, which the real background checks against current details again immediately before creating a job. Missing transport replies are not automatically retried as another generation request.

Verification:

- Focused regression run: 130 tests across 7 files passed, including the panel entrypoint and background stale-response tests.
- Full extension suite: 333 tests across 32 files passed.
- `npm run compile`, `npm run build` (WXT 0.21.4, Vite 8.1.4, Chrome MV3), then `npm run compile` passed. No compiler settings changed.
- Native browser checks at 320px and 360px passed: modal state, no overflow, initial focus, Tab and Shift+Tab, Escape with focus restoration, decline without a request, one request while busy, a new prompt for retry and Generate again, and invalidation after account/video/settings/duration updates. See [measurements](browser-results.json).
- [320px screenshot](confirmation-320.png), [360px screenshot](confirmation-360.png), and [retry screenshot](confirmation-retry-360.png). Both width captures were visually inspected.

Reproduce from the repository root, keeping the server running in a separate terminal:

```powershell
node docs/review-evidence/2026-09-15-remediation/extension-fixture.cjs
./docs/review-evidence/2026-09-16-generation-confirmation/browser-check.ps1
```

The fixture now uses the already-installed Vite builder because WXT 0.21 removed esbuild from the installed dependency tree. It builds the actual panel entrypoint and CSS, binds localhost, and supplies invented data through a fake browser transport. All generation responses are fabricated; no provider or account calls occur. This verifies browser rendering and interaction, not a loaded Chrome extension on YouTube or its service-worker lifecycle. Background entrypoint tests establish the request checks. The owned fixture server and browser were stopped after verification.
