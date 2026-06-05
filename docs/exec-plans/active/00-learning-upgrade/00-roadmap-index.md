# Learning Upgrade Roadmap

Status: active
Owner: agent
Created: 2026-06-03
Last updated: 2026-06-03

## Summary

This roadmap turns the current YouTube AI subtitle workflow into a durable language-learning product. It prioritizes the missing YouTube-focused features selected after the competitor review: keyboard-first controls, transcript navigation, accessibility depth, vocabulary and sentence mining, export, listening and speaking practice, and current-line AI coaching.

This roadmap is intentionally placed before the existing SaaS roadmap in `docs/exec-plans/active/` because these learning workflows are the product features most likely to make learners see the extension as worth paying for. Content discovery and recommendations remain deferred.

## Product Position

The current product already generates AI subtitle tracks, token cards, romanization, translation, blur/reveal controls, hover pause, replay, copy, jobs, usage, and account/billing surfaces.

The next product promise is:

> Turn any supported public YouTube video or Short into an interactive language lesson with keyboard-native subtitle control, transcript navigation, saved vocabulary and sentences, Anki export, listening practice, speaking feedback, and AI explanations for the current line.

## Phase Order

| Phase | Document | Primary Outcome | Exit Gate |
| --- | --- | --- | --- |
| 01 | `../../completed/2026-06-03-keyboard-transcript-accessibility-foundation.md` | Keyboard-first subtitle controls, transcript/sidebar, cue navigation, and accessibility controls. | Completed 2026-06-03. |
| 02 | `02-vocabulary-sentence-mining-anki-export.md` | Account-scoped saved words/sentences, review state, CSV export, and local AnkiConnect export. | A learner can save from the overlay/transcript and export useful cards to Anki. |
| 03 | `03-listening-shadowing-speaking-practice.md` | Auto-pause, repeat, AB loop, listen-then-reveal, shadowing prompts, microphone recording, and backend speech scoring. | A learner can practice listening and speaking against the active subtitle cue. |
| 04 | `04-ai-current-line-coach.md` | Fixed-mode AI coach for current cue explanation, grammar, literal/natural translation, simplification, quiz, and pronunciation prompts. | A learner can ask focused questions about the active line without freeform chat. |

## Shared Assumptions

- YouTube watch pages and Shorts remain the only supported platforms.
- Content discovery, recommendations, creator/channel workflows, Netflix support, and non-YouTube platforms remain out of scope for this roadmap.
- Study data is account-scoped backend data, not local-only extension state.
- Extension code may call the backend and local AnkiConnect, but must not call OpenAI, speech AI providers, or other model providers directly.
- Saved study items must avoid storing raw provider payloads, prompts, audio blobs, or unnecessary transcript context.
- Microphone recording is opt-in per practice action and must show clear privacy copy before backend upload.
- AI coach v1 uses fixed mode buttons, not freeform chat.
- Anki export v1 uses direct local AnkiConnect, not backend-generated APKG packages.

## Shared Interfaces

- Study library APIs should be added under the authenticated extension API with account ownership, canonical JSON schemas, generated TypeScript types, Laravel request validation, and contract-response tests.
- Saved study item records should support word and sentence items, source/target languages, cue and video identity, source text, optional romanization/translation/gloss, review state, timestamps, and safe metadata needed for export and review.
- Anki export should be an extension-local adapter that talks to AnkiConnect on localhost for deck/model discovery, duplicate checks, and note creation. The backend should not proxy local AnkiConnect.
- Speaking practice should upload only short microphone clips to the backend, enforce strict duration and size limits, delete temporary audio after scoring, and expose sanitized scoring results.
- AI coach should cache fixed-mode cue responses by account, track, cue, source/target language, mode, and model/prompt version.

## Cross-Phase Validation

Every implementation phase should run:

```powershell
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
```

Docs-only updates should at minimum run:

```powershell
.\scripts\agent\lint-docs.ps1
.\scripts\agent\doc-gardening.ps1
git diff --check
```

UI phases must capture browser screenshots or video for the popup, overlay, transcript/sidebar, and relevant mobile/compact states.

## External References

- AnkiConnect: `https://github.com/FooSoft/anki-connect`
- Chrome extension permissions: `https://developer.chrome.com/docs/extensions/develop/concepts/declare-permissions`
- MDN MediaRecorder API: `https://developer.mozilla.org/en-US/docs/Web/API/MediaStream_Recording_API`
- MDN Web Speech API: `https://developer.mozilla.org/en-US/docs/Web/API/Web_Speech_API`
- W3C accessible media player guidance: `https://www.w3.org/WAI/media/av/player/`

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-06-03 | Prioritize a dedicated learning-upgrade roadmap before the SaaS roadmap. | These workflows are the highest-leverage product gaps identified in the competitor review. |
| 2026-06-03 | Treat vocabulary review as deferred from first release, not permanently out of scope. | The new roadmap explicitly accepts saved study items and review as post-first-release learning product work. |
| 2026-06-03 | Use direct local AnkiConnect for Anki export v1. | Power users get one-click export without adding backend APKG generation. |
| 2026-06-03 | Use backend scoring for microphone practice v1. | Server-side scoring keeps behavior consistent and lets privacy, rate limits, and logging be enforced centrally. |
| 2026-06-03 | Use fixed AI coach mode buttons instead of freeform chat. | Fixed modes are easier to validate, cache, constrain, and explain in a compact overlay. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-06-03 | Roadmap created from the selected missing-feature plan. | `docs/exec-plans/active/00-learning-upgrade/` |
| 2026-06-03 | Phase 01 completed and archived. | `docs/exec-plans/completed/2026-06-03-keyboard-transcript-accessibility-foundation.md` |
