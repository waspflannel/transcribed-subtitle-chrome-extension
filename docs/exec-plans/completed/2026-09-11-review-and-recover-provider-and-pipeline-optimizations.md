# Plan: Review and recover provider and pipeline optimizations

Status: completed
Owner: agent
Work mode: standard
Created: 2026-09-11
Last updated: 2026-09-11

## Goal

Recover worthwhile work from both deleted branches and the saved uncommitted tree onto `codex/reviewed-pipeline-optimizations`, based on main `9703b2b`. Preserve the relaxed model-trusting validator in baseline commit `a67691a`. Restore one global Luna/Cerebras provider choice, default Luna, and retain proven speed work without hybrid routing or layered language-repair workarounds.

## Scope

- In scope: review committed and stashed changes; provider configuration and account model label; queue/polling/audio/batching improvements; simpler combined analysis; regression coverage; review decisions and grouped commits.
- Out of scope: hybrid task routing, model-owned cue timing/grouping, speculative early analysis during incomplete transcription, unrelated feature changes, new paid generation experiments, deployment to production.

## Acceptance Criteria

- [x] Source inventory and keep/drop rationale cover committed and stashed work.
- [x] Luna or Cerebras handles every text AI task through one global switch; Luna is the default.
- [x] Model token wording is trusted; only shape/identity/usability errors trigger validation.
- [x] Useful speed and pipeline changes retain timing, cache, concurrency, run isolation and publication guards.
- [x] Full harness passes; changes are in reviewable commits on the new branch.

## Relevant Context

- Product docs: `docs/product-specs/index.md`
- Architecture docs: `ARCHITECTURE.md`, `docs/RELIABILITY.md`, `docs/OBSERVABILITY.md`
- Quality rules: `docs/quality/golden-principles.md`
- Related plans: the deleted tips and stash listed below are the recovery sources.
- Known risks: the stash mixes a useful pipeline simplification with hybrid provider routing, removed transport handling, and partially restored main-branch recovery. Its docs do not consistently describe its final code. Do not apply it wholesale.

## Implementation Steps

- [x] Inspect current state.
- [x] Confirm or refine acceptance criteria.
- [x] Implement the smallest end-to-end slice.
- [x] Add or update validation.
- [x] Check the implementation against `docs/quality/golden-principles.md`.
- [x] Update docs and quality score if needed.
- [x] Run validation and record evidence.
- [x] Complete review notes.

## Validation Plan

Commands:

```powershell
.\scripts\agent\check.ps1
```

Evidence to capture:

- Tests: full harness passed 500 backend tests / 3,939 assertions, 230 extension tests; contracts, TypeScript compile and production extension build passed. Pint applied.
- Screenshots or video: no new visual redesign or live generation test. Recovered account model label has backend/contract/extension coverage.
- Logs: `app/backend/storage/logs/recovery-full-check.log`, `recovery-pint.log`, `recovery-runtime-start.log` (ignored local evidence).
- Metrics or traces: all 102 cue outputs across six saved failing-video responses passed the integrated validator offline. Legacy captures omit cueId, so this replay supplied original stored IDs by index; model words, translations and readings were unchanged. This is compatibility evidence, not fresh model quality or speed evidence.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-09-11 | Preserve current validator changes first on the new branch. | This is the acceptance baseline; earlier source-matching recovery must not return. |
| 2026-09-11 | Default to Luna; restore a global provider switch. | Explicit user selection; no hybrid task routing. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-09-11 | Inspected both deleted tips and all tracked/untracked stash paths. | Provider tip `53a556d65e5838168af5192f2de092efca74d287`; speed tip `9b0fd260154bd42ba210610101c28ac6c2f195d2`; stash `565e0cafc53e2e23d7621a832a577003615487fb`, untracked parent `6937459c8bc8982523afcefc91740488a7ef14f1`. |
| 2026-09-11 | Prior validator baseline passed full harness. | 502 backend tests, 227 extension tests; 102 recorded cue outputs accepted without paid calls. |

## Completion Notes

- What changed: selected provider, queue, audio and unified analysis improvements recovered; main remains unchanged.
- Validation results: full harness and saved-response replay pass; local runtime restart recorded below.
- Simplicity/readability review: see recovery inventory and resolved findings. The Laravel skill reviewer checked queue/cache/run guards and disabled audio/batch experiments without finding an unresolved blocker.
- Residual risk: no fresh paid-generation or production latency/semantic-quality result is claimed. Enabling audio/balance switches still requires isolated measurements. Quick Fix reconstruction can lose punctuation present only in the original source, as documented in Reliability.
- Follow-up debt: none required for this branch; optional live measurements remain in the quality score.


## Recovery inventory and decisions

The two deleted branches contain 13 commits after main. Their objects are still reachable through `stash@{0}`; the stash is preserved, including its untracked parent. The reviewed branch recovers their useful behavior without restoring the old tips wholesale.

| Source | Decision | Reason |
| --- | --- | --- |
| `ad1698e`, `0bb3f8d`, `5627347`: provider support, switching notes, account model label | Keep and simplify | One global `AI_PROVIDER` and one model per provider route every text task. Luna is the selected default. Cerebras keeps its own credentials, endpoint, response identity and cost rates through the SDK transport. No task overrides. |
| `5754b53`: analysis output limit | Keep | Combined tokens, translation and readings need an adequate bounded output budget (16,000 tokens); truncated output still fails. |
| `20696ab`, `df66e82`: prompt/schema and lyrics prompt work | Keep useful portions | Shared short instructions and annotation-only card schemas remove repeated text. Full/partial lyrics prompts match their server contracts. Token wording is trusted; linguistic/source matching validators are removed. |
| `d1c4411`: held-out evaluation tools | Keep and adapt | Evaluates the active global model, reports first response validity, usage and latency, and keeps human semantic review separate. Fakes and former prompt examples are explicitly not live performance evidence. |
| `53a556d`, `9b0fd26`: invalid-cue repair and local recovery | Drop | They compensated for source-matching false failures and added repair requests, split paths and substitute token output. One identical retry remains only for malformed analysis in an active generation. |
| `586a2dd`, `4ed09fb`: queue locks, delayed jobs, polling | Keep | Bounded lock wait reduces avoidable releases. One-second Redis blocking revisits delayed work sooner. Adaptive status/preview polling retains busy/failure backoff. Existing caps and cancellation behavior remain. |
| `13e7619`: metadata reuse, direct chunking, balance estimates | Keep behind disabled switches | They retain input checks, output format, ordered coverage, cue/character caps and cleanup. Direct preparation applies only to long M4A audio; WebM/Opus codec delay keeps that format on whole-file normalization. Isolated end-to-end gains remain unproven. |
| `13e7619`: early analysis on incomplete transcription | Drop | It adds prefix sealing, provisional plans and reconciliation before fixed cue ownership is known. The recovered pipeline waits for the complete Scribe merge. |
| `6d63f65`: speed trial results | Preserve as historical evidence in Git | Queue pickup trial was 5.077s to 1.103s in that setup. Combined experiments changed several variables; they do not establish individual or production gains. |
| `stash@{0}` tracked and untracked files | Selectively keep | Unified analysis artifact, immediate fixed-cue previews, plan completeness, per-batch redelivery guard, shared agent configuration, prompt deduplication, held-out fixtures and regression coverage are useful. Retain main's transport/quota mapping and the relaxed-validator Quick Fix fixes. |
| `stash@{0}` hybrid mappings, ICU readings, restored fallback blocks and inconsistent draft docs | Drop or rewrite | Every task follows one global provider. A separate deterministic reading path saves no request after the combined call and can lose language distinctions. Draft documents described conflicting pipeline versions. |
| `stash@{1}` from August cleanup | Preserve, no additional recovery | Primarily billing/Stripe, deployment and retired agent temperature work. No additional relevant pipeline speed or global provider work. Those unrelated changes are outside this recovery. |
| `stash@{2}` from extension redesign | Preserve, no additional recovery | Architecture/debt edits and an untracked red hero image are unrelated to this request. |

## Review findings resolved

| Category / severity | Problem and why it mattered | Applied improvement |
| --- | --- | --- |
| Correctness / must fix | Source-substring validation rejected plausible model corrections, including mixed Hebrew/Arabic characters. | Validate usable structure and identities; accept model wording and segmentation. Preserve the explicit mixed-script regression. |
| Architecture / must fix | Stashed task routing could run cards/alignment on a different provider from analysis. | A shared provider/model accessor governs all five text agents, logging and card-cache identity. Default Luna. |
| Reliability / must fix | Applying the stash wholesale would lose current quota/transport handling and Quick Fix source-span safeguards. | Preserve typed error mapping, terminal quota/output limits, and full-sequence Quick Fix matching with corrected-token reconstruction. |
| Correctness / must fix | Partial batch sets must never publish complete tracks; stopped or replaced runs must not record late output. | Require all stored plan indexes; retain run/status checks, transactional analysis result/cost/telemetry, overlap lock and duplicate artifact guard. |
| Maintainability / should improve | Parallel artifact types, repair loops, alternate agents and deterministic readings made ownership unclear. | One analyzed result per batch; remove retired jobs, agents and fallback-only tests; retain current behavior coverage. |
| Performance / should improve | Repeated neighbor text and card source echoes expanded prompts and output. | Deduplicate surrounding context and return card annotations against existing identities. |
| UX / should improve | Preview availability could depend on completed contiguous analysis work. | Expose all fixed source cues immediately; overlay completed annotations in any order, with no partial interactive tokens. |
| Operations / must fix | New artifacts/prompts could mix with old serialized queue jobs or reuse old output. | Bump job version to analysis v12 and clicked-card cache to v9. Keep transcript cache v3. Document draining generations/corrections before deployment or provider switching. Local queues and active generation count were zero before restart. |
| Evidence / optional | Batch weights and audio shortcuts have incomplete isolated measurements. | Keep their defaults false. Retain evaluation tooling and record limitations instead of claiming measured production speedups. |

## Commit groups

1. `a67691a` — trust model wording in subtitle validation, including safe Quick Fix edits.
2. `2afd77c` — restore the global Luna/Cerebras provider and account label.
3. `f96ae8c` — reduce queue and polling delays.
4. `2e694e1` — retain guarded audio acquisition/preparation improvements and their tests.
5. `7b2816e` — combine analysis and simplify recovery, including shared global configuration, batch guards, preview behavior, prompt schemas and regression tests.
6. `ddd4ca5` — restore evaluation commands and held-out fixtures against the final provider contract.
7. Record review decisions, final behavior and operational/validation evidence.

## Review conclusion

The useful optimization work is worth keeping after removing the repair machinery and hybrid configuration. The main signs of rushed integration were contradictory docs, stale tests, duplicated prompt/config methods and multiple token recovery paths. The recovered code has one traceable analysis path, preserves fixed timing and publication guards, and is easier to maintain. All improvements found within this recovery are listed above; remaining audio/balance and semantic-quality measurements are optional follow-up work, with no unmeasured experiment enabled by default.

Verdict: good to merge after the normal deployment drain/restart procedure. This task prepares a separate reviewed branch; it does not merge or deploy production.

## Local runtime handoff

The local queue families and active generation count were zero before restart. The standard launcher restarted the backend and 31 workers (32 processes) with Luna selected. `subtitles:runtime-check --strict --json` passed with Postgres and Redis. Runtime configuration reports provider `openai`, model `gpt-5.6-luna`, Redis blocking 1 second, analysis version v12, and all three audio/balance experiment switches false. No paid generation was started.
