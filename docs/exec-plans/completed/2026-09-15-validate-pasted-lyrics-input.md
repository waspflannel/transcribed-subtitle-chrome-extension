# Plan: Validate pasted lyrics input

Status: complete
Owner: agent
Work mode: standard
Created: 2026-09-15
Last updated: 2026-09-15

## Goal

Reject obvious junk before paid lyric replacement work and constrain untrusted alignment output. Keep multilingual lyrics, repetition, and normal punctuation usable.

## Scope

- In scope: backend input validation, matching panel feedback, alignment reference/bounds checks, account-based POST throttling, regression tests, and current docs.
- Out of scope: gibberish/song-match classification, completeness judgments against the song, extra AI calls, derived-learning quality validation, provider changes, deployment.

## Acceptance Criteria

- [x] Invalid types, oversized raw text, control characters, text without letters, and HTTP(S)/www link-only input fail before queueing.
- [x] All writing systems, joining characters, combining marks, punctuation, mixed scripts, and repeated lines remain allowed.
- [x] The panel shows an accessible reason and prevents invalid submission.
- [x] Alignment accepts only ordered known slots and increasing in-range pasted endpoints consuming every supplied part. Invalid output preserves the current track and skips analysis.
- [x] Replacement POST allows five requests per account per minute across devices/IPs, alongside existing throttles. Status and cancellation remain available.
- [x] Required repository checks pass.

## Relevant Context

- Product docs: `docs/product-specs/lyrics-editing.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/RELIABILITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: `docs/exec-plans/completed/2026-09-13-speed-up-lyrics-replacement.md`
- Known risks: deterministic checks cannot identify gibberish or wrong-song lyrics; prompt instructions cannot guarantee injection resistance. Structural rejection can expose provider mistakes previously hidden by clamping/skipping.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Implement the smallest end-to-end slice.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update affected docs.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
```

Evidence to capture:

- Focused tests: 103 backend tests (640 assertions), 48 extension tests passed before final review.
- UI evidence: jsdom panel test exercises input feedback, disabled submission, and recovery with valid Punjabi lyrics. Live browser acceptance is not claimed.
- Full check results: `scripts/agent/check.ps1` passed: contracts/OpenAPI, 604 backend tests (4809 assertions), 296 extension tests, TypeScript compilation, extension build, and documentation lint. Pint and `git diff --check` passed. Log: `app/backend/storage/logs/lyrics-input-validation-check.log`.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-15 | Extend existing FormRequest and panel helper; no new dependencies or classifier. | Small deterministic gate at each user-facing boundary. |
| 2026-09-15 | Validate raw lyrics before framework trimming; allow tab/LF/CR and Unicode joiners. | Prevent hidden boundary controls without damaging Indic or Arabic text. |
| 2026-09-15 | Five replacement POST requests per account per minute. | Bounds repeated work across devices while leaving cancellation/status available. |
| 2026-09-15 | Reject invalid alignment references and incomplete consumption of pasted parts. | Existing atomic failure handling preserves the published track; no semantic song-completeness gate. |
| 2026-09-15 | Applied Ponytail, Laravel best practices/security, AI SDK, and subtitle-pipeline skills. | Current user approval supersedes the earlier no-validation rule; read-only rule-reader subagent reviewed Unicode, middleware, and allocation risks. Context7 supplied Laravel 13 validation/rate-limit docs. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-15 | Created branch from clean main and implemented the slice. | `codex/lyrics-input-validation`; focused checks passed. |
| 2026-09-15 | Review corrected BOM-prefixed URL parity; tests added on both sides. | Backend and extension now both reject the same link-only paste. |
| 2026-09-15 | Full check exposed an existing recovery test with an unfaked synchronous queue. Reproduced with the original HEAD service, then faked the queue and asserted recovery dispatch. | Production recovery behavior unchanged; final full check passed. |

## Completion Notes

- What changed: input checks and inline feedback, explicit untrusted lyric prompt instructions, bounded ordered alignment reconstruction, and account-based POST throttling on `codex/lyrics-input-validation`.
- Validation results: all required checks passed; 604 backend and 296 extension tests. Live-provider and installed-extension browser acceptance were not performed.
- Simplicity/readability review: existing request, helper, provider, and lifecycle paths; no new abstraction, dependency, infrastructure, or AI request.
- Residual risk: language-quality and live-provider accuracy remain outside this change.
- Follow-up debt: classify gibberish or wrong-song lyrics only with separate product acceptance criteria.
