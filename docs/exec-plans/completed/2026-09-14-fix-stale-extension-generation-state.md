# Plan: Fix stale extension generation state

Status: completed
Created: 2026-09-14

## Goal

Resolve the reported missing background response when deleting a saved generation, and clear stale lyrics when a saved-generation refresh confirms deletion.

## Findings and decisions

- The active development output had a fresh panel and background file but a content script from before the validation change. Its content.getState request omitted the new flag, so cache checks were skipped. Default page-entry validation to true for compatibility with already-injected content scripts. Normal local snapshots can explicitly opt out.
- The undefined delete response is consistent with a browser background worker predating panel.deleteGeneration: its message validator ignores the request. Source and generated worker both support deletion. The undefined-response message now gives precise extension-reload and page-refresh steps instead of asking the user to repeat an unrecognized request.
- A successful saved-generation list was only updating the dropdown. Reconcile missing selected generations in the background, forget their local cache, recover another saved generation if available, publish to the content script, and return the updated panel state with the list. A successful list is authoritative; it does not require fetching the missing job again.
- Keep stale-response, session, tab, and mutation guards. No deletion polling is added.
- Regenerated the stale WXT development content script and checked the output for the validation request. Background development output includes deletion, reconciliation and the default validation behavior.
- Chrome inspection via Computer Use was stopped by the tool because it could not verify the current browser URL. No Chrome input was performed; a manual extension reload remains necessary to verify the user's running worker.

## Acceptance criteria

- [x] Old content.getState requests validate deleted remembered generations.
- [x] Refresh saved generations clears the transcript and overlay when the last generation is gone, or loads another available generation.
- [x] A late list cannot overwrite a newer selected generation; valid selected generations are not fetched again.
- [x] Missing background responses provide actionable reload instructions.
- [x] Full harness and final review pass.

## Validation

Targeted extension tests: 54 passed; TypeScript compilation passed. Full scripts/agent/check.ps1 passed: 566 backend tests (4,498 assertions), 279 extension tests, contract checks, TypeScript compile, and Chrome build. Log: app/backend/storage/logs/generation-state-fix-check.log (ignored). Checked the actual development background and content outputs for new request handling and validation. git diff --check passed.

## Completion notes

No backend changes needed for this follow-up. Live Chrome deletion verification remains user-owned because Computer Use was stopped before inspection.
