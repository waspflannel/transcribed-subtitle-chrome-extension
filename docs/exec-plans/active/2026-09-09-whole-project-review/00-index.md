# Whole-project review follow-up

Status: documentation consolidated; prior code delivery awaiting user acceptance; remaining work not automatically selected.
Created: 2026-09-09
Last updated: 2026-09-09

## Cleanup branch preservation

This documentation-only branch, `codex/docs-cleanup`, starts from main at `75dbd046f8ef6b71226db85ee271919421dae60b`. It preserves the earlier cleanup, Brain/Worker workflow, full audit, and consolidated working documents from `codex/learning-queue-audio-05-06-08` at code revision `403a9867ad735ce096cf4e6948707f0f9017262c` plus its uncommitted documentation cleanup. It does not contain the feature branch's application changes.

Delivered/reviewed code and test counts below describe that separate feature branch. Before implementing or testing from this cleanup branch, recheck what is actually present; do not treat preserved delivery evidence as proof that main contains those fixes. The source checkout was left intact. Current-topic links are relative so the consolidated documents remain usable in another checkout. Verified 223 local links/anchors in this worktree and confirmed that the cleanup diff contains only documentation. The original checkout and its uncommitted cleanup remain intact.

## Choose an area

The former twelve-package backlog and its supporting notes are now **four topic documents, one delivery/testing record and this index**. The [original audit](../../../whole-project-review-2026-09-09.md) remains the dated evidence source. Product specs and operating runbooks remain the system of record for shipped behavior.

| Document | What belongs here |
| --- | --- |
| [Accounts and billing](accounts-and-billing.md) | Subscription access, Stripe events, checkout/account deletion and email-verification policy. |
| [Pipeline speed, quality and reliability](pipeline-speed-quality-and-reliability.md) | Audio/acquisition, transcription, source fidelity, AI, queues, metrics, caching, browser delivery, architecture and the current slowdown investigation. |
| [Learning and editing](learning-and-editing.md) | Degraded learning output, word cards, corrections and recovery semantics. |
| [Operations and release](operations-and-release.md) | Deployment, operational security, retention verification, backup/restore, release evidence and documentation alignment. |
| [Delivery and testing](delivery-and-testing.md) | Scope, decisions, commit/review evidence, manual smoke tests and deferred testing for the selected former 05/06/08 delivery. |

## Current delivery and open work

The user selected former 05/06/08 code as one Brain/Worker delivery on `codex/learning-queue-audio-05-06-08`. Code was reviewed at `c180b2b9ccd1337671ad2933f79ea46a43b4263f`; the authorized push was recorded through `753d0a9`. User acceptance, merge and deployment remain pending. The [current slowdown investigation](pipeline-speed-quality-and-reliability.md#current-slowdown-investigation) records a fresh-worker reproduction and diagnostic work after that review. Earlier test counts do not certify later changes.

Source-validation and overload-handling prerequisites were partially delivered with former 05/06. Broader source quality, Scribe null/retry handling, measurement fixes, acoustic/model/scheduling comparisons and operational acceptance remain open unless current evidence explicitly says otherwise. No experiment or improvement is completed just because its document was merged.

For the generation goal, use the pipeline document's proposed improvement path and choose a bounded slice. Keep engineering work and quality/performance comparisons distinct, but do not lose the optimization goal by selecting only correctness fixes. Record future decisions and results in the owning topic; use the delivery record for integrated acceptance instead of spawning more overlapping plans.

## Old-number lookup

These are historical audit IDs, not twelve separate current plans. Links from current docs now resolve to the owning consolidated section.

| Former document | Current owner |
| --- | --- |
| 01 — billing and checkout | [Billing and checkout](accounts-and-billing.md#billing-and-checkout). |
| 02 — source text and token fidelity | [Source text and token fidelity](pipeline-speed-quality-and-reliability.md#source-text-and-token-fidelity). |
| 03 — provider compatibility and retries | [Provider compatibility and retries](pipeline-speed-quality-and-reliability.md#provider-compatibility-and-retries). |
| 04 — metrics cost and baselines | [Measurement and cost](pipeline-speed-quality-and-reliability.md#measurement-and-cost). |
| 05 — learning quality and editing | [Learning quality and editing](learning-and-editing.md#learning-quality-and-editing). |
| 06 — queue capacity and scheduling | [Queue capacity and scheduling](pipeline-speed-quality-and-reliability.md#queue-capacity-and-scheduling). |
| 07 — ai latency and quality | [AI latency and linguistic quality](pipeline-speed-quality-and-reliability.md#ai-latency-and-linguistic-quality). |
| 08 — audio and transcription quality | [Audio preparation and transcription](pipeline-speed-quality-and-reliability.md#audio-preparation-and-transcription). |
| 09 — cache retention and data efficiency | [Caching retention and data efficiency](pipeline-speed-quality-and-reliability.md#caching-retention-and-data-efficiency). |
| 10 — extension delivery and browser quality | [Browser delivery and lifecycle](pipeline-speed-quality-and-reliability.md#browser-delivery-and-lifecycle). |
| 11 — architecture simplification and scaling | [Architecture and scaling](pipeline-speed-quality-and-reliability.md#architecture-and-scaling). |
| 12 — operations security release and docs | [Operations security and release](operations-and-release.md#operations-security-and-release); account-verification policy moved to Accounts and billing. |

Former review, testing handoff, smoke checklist and the separate learning/queue/audio delivery plan are sections of Delivery and testing. The three tokenizing investigation notes are sections of the pipeline document. Old worker packets already removed by the earlier cleanup remain in Git history; they are not recreated.

## Shared execution rules

- Work only the selected topic or bounded section. Re-read the current checkout, root/nested AGENTS.md, relevant product docs and the source evidence before editing; the review baseline is commit 75dbd046f8ef6b71226db85ee271919421dae60b.
- Treat the source report as dated evidence. Preserve the distinction between reproduced defects, code-supported risks and hypotheses. Reproduce each relevant issue and look for intervening fixes.
- Keep these plans as the work log: update status, scope decisions, exact commands/results, changed contracts and residual gaps. Do not rely on chat history as the only record.
- Use the normal applicable skills and current official documentation when implementation requires them. This consolidation does not prescribe a model, framework migration or dependency upgrade.
- Preserve owner/account/tab/video/run/track/cue identity, short publication transactions, ledger settlement, native timing and existing recovery guards unless the selected change explicitly demonstrates why a contract must change.
- Make confirmed fixes and speculative experiments separate slices. Record baseline, one changed variable, quality floor, cost cap and rejection criteria before provider-backed evaluation.
- Paid provider evaluations, real generation, external writes, deployments and destructive data cleanup need scope-specific authorization unless already authorized in the session. Read-only/local work can continue when external access is unavailable; record the exact remaining gate. Do not ask again for authorization already provided.
- For implementation, run focused meaningful checks followed by `.\scripts\agent\check.ps1`; record browser/Postgres/Redis/provider proof separately. For document-only updates, use `.\scripts\agent\check.ps1 -SkipAppChecks`, doc gardening and link/whitespace checks.
- Update affected current docs as behavior changes. Preserve historical review reports and reconcile existing active plans instead of silently superseding their acceptance criteria.
- Finish the selected work with its results, rollback/invalidation decisions and remaining evidence. Update this index and stop at that scope boundary unless the user asks to continue.

Shared document conventions: section statuses and completed delivery notes override initial audit planning assumptions. Record scope, decisions, exact validation, residual risks and rollback/invalidation in the owning section. Code checks, actual runtime evidence, user acceptance and provider-backed comparisons remain separate. Use current official documentation where the selected implementation requires it. Historical findings must be rechecked against intervening fixes.

## Finding coverage

| Findings | Primary owner | Remaining distinction |
| --- | --- | --- |
| F01, F02, F03 | Accounts and billing | Stripe periods, monotonic events and pending checkout lifecycle. |
| F04, F05 | Pipeline / source fidelity | Narrow source checks delivered; Korean normalization and broader language acceptance remain open. |
| F06 | Pipeline / audio | Narrow boundary correction delivered; real-audio quality and tuning unverified. |
| F07 | Learning and editing | Degraded behavior delivered; user/bilingual acceptance open. |
| F08, F09, F13 | Pipeline / provider compatibility | Overload prerequisite delivered; nullable Scribe timestamps and full retry work remain open. |
| F10 | Pipeline / queue capacity | Limits/budgets delivered; runtime fairness and scheduling adoption unverified. |
| F11, F12 | Pipeline / measurement | Run-pure timings and accurate queue counts remain planned. |

## Opportunity and experiment coverage

| Original opportunities | Owner |
| --- | --- |
| 1 — Billing and source correctness | Accounts and billing; Pipeline / source and provider boundaries. |
| 2 — Run/request latency and cost | Pipeline / measurement. |
| 3 — AI tuning | Pipeline / AI latency. |
| 4 — Retries and provider demand | Pipeline / provider compatibility and queue capacity. |
| 5 — Chunk boundaries and sizing | Pipeline / audio. |
| 6 — Scheduling delays | Pipeline / queue capacity and AI latency. |
| 7, 8 — Audio preparation and selective preprocessing | Pipeline / audio. |
| 9 — Partial delivery efficiency | Pipeline / browser delivery. |
| 10 — Cache identity and reuse | Pipeline / caching. |
| 11 — Cards and correction behavior | Learning and editing; pipeline validates browser recovery. |
| 12 — Reads and architecture | Pipeline / caching, provider boundaries and architecture. |
| 13 — Deployment, retention and browser evidence | Operations and release; Pipeline owns retention design, delivery and capacity proof. |

| Experiments | Evidence owner |
| --- | --- |
| E01 — Baseline | Pipeline / measurement. |
| E02 — AI settings and batching | Pipeline / AI and scheduling. |
| E03, E04 — Audio and chunk boundaries | Pipeline / audio. |
| E05 — Learning fallback | Learning and editing. |
| E06 — Capacity and failure recovery | Pipeline / queues and provider compatibility; operations receives release evidence. |
| E07 — Browser lifecycle and perceived quality | Pipeline / browser delivery. |
| E08 — Billing and deployment | Accounts and billing; Operations and release. |

All six larger alternatives remain in Pipeline / architecture: STT callbacks, early stable prefixes, provider URL acquisition, dynamic scheduling, multiple worker hosts/shared audio and browser transport. None is automatically selected. Source/translation/romanization/reading quality, acoustic alignment limits, edit recovery, language disagreement, metadata, prompt safety, retention, query/memory costs and documentation drift retain their topic owners.

## Historical requirements and evidence

The older plans were removed during the user-requested documentation cleanup on 2026-09-09. Links below retain their historical evidence; they are not active work instructions. Outstanding requirements remain in the product specs and technical debt tracker, with audit package ownership below.

- [CJK quality plan (historical)](https://github.com/waspflannel/transcribed-subtitle-chrome-extension/blob/5f3a92b7347072471b59bb2b956e23559ada1e6f/docs/exec-plans/active/2026-06-18-track-b-cjk-tokenization-quality.md): 02/07/08.
- [Release hardening (historical)](https://github.com/waspflannel/transcribed-subtitle-chrome-extension/blob/5f3a92b7347072471b59bb2b956e23559ada1e6f/docs/exec-plans/active/2026-07-15-pre-production-release-hardening.md): 12.
- [Pasted lyrics (historical)](https://github.com/waspflannel/transcribed-subtitle-chrome-extension/blob/5f3a92b7347072471b59bb2b956e23559ada1e6f/docs/exec-plans/active/2026-08-13-pasted-lyrics-transcript-correction.md) and [single-word editing (historical)](https://github.com/waspflannel/transcribed-subtitle-chrome-extension/blob/5f3a92b7347072471b59bb2b956e23559ada1e6f/docs/exec-plans/active/2026-09-06-refresh-learning-data-after-a-single-word-edit.md): 05/10.
- [G1/G5/R7 smoke follow-up (historical)](https://github.com/waspflannel/transcribed-subtitle-chrome-extension/blob/5f3a92b7347072471b59bb2b956e23559ada1e6f/docs/exec-plans/active/2026-09-08-fix-smoothening-smoke-failures-g1-g5-r7.md): 01/10/12.
- [Technical debt tracker](../../tech-debt-tracker.md): 09/12; do not duplicate resolved historical issues as new work.

## Consolidation work log

- 2026-09-09: User requested grouping related documents. Consolidated 20 local planning/supporting files into six: four topics, a shared delivery/testing record and this index. Removed superseded originals after preserving their substantive content and updating current local references.
- Kept all F01–F13, opportunities 1–13, E01–E08, architecture alternatives, worker/review evidence and open acceptance gates. Moved account verification from operations into accounts. Added the discussed end-to-end pipeline candidates as proposals, not implementation claims.
- Preserved the original audit and review prompt; did not alter application code or run provider experiments. Existing unrelated code modifications and prior document deletions remain untouched.
- Validation: `scripts/agent/check.ps1 -SkipAppChecks` and `git diff --check` passed. Verified 221 local links/anchors, all 124 original topic checklist items by count, all 56 original implementation-source references, and finding/experiment coverage. Audit, prompt and application-file hashes matched the pre-consolidation snapshot. Doc gardening reported two existing historical/placeholder signals and a retained investigation sentence stating that no causal defect is established; no unresolved consolidation placeholder remains.
