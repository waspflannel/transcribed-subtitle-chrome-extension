# Whole-project architecture and quality review — agent prompt

Prepared against the repository on 2026-09-09. Paths and implementation notes below are starting points; verify them against the checkout you review.

Copy the prompt below into an agent working at this repository's root, or ask it to read this file and carry out the review.

---

Review this entire project with fresh engineering judgment. Examine how its components are built, how they interact, what can fail, and how the product could become faster, more accurate, more reliable, and easier to maintain. Give particular depth to the subtitle generation pipeline and audio/transcription quality, but cover the rest of the system too.

This is an opportunity to reconsider decisions made during earlier development with the benefit of current model capabilities and current provider documentation. Do not assume earlier implementations are wrong, that newer technology is better, or that you must discover a particular number of issues. Challenge assumptions and demonstrate your reasoning from the code. Look beyond lint, style, and conventional refactoring advice for improvements that matter to users.

## Scope and working agreement

- Produce a review and recommendations, not an implementation. You may write the final report to `docs/whole-project-review-YYYY-MM-DD.md`, using the actual review date and a distinct suffix if a report already exists. Leave application code, configuration, dependencies, plans, and existing documents unchanged.
- Read `AGENTS.md` and applicable nested instructions. Record the Git commit, branch, dirty-tree state, review date, and relevant installed/locked dependency versions. Preserve existing changes and distinguish them from committed code when relevant.
- Follow calls through services, jobs, persistence, contracts, clients, and tests. A class name, comment, file length, passing test, or architecture document alone is insufficient evidence of runtime behavior.
- Use available local tests and sanitized diagnostic data. Do not start paid provider evaluations, real generation jobs, production mutations, or deployments as part of this review without existing authorization. If those are needed to settle a recommendation, specify the exact experiment and estimated resource requirements, then continue the review with available evidence.
- Cover every major component. Inspect first, form hypotheses second, and actively look for code or tests that disprove each proposed finding before publishing it.

## 1. Establish intent and reconstruct the actual architecture

Start with:

- `ARCHITECTURE.md` and `docs/product-specs/`, including `lyrics-editing.md` and `release-readiness.md`.
- `docs/DESIGN.md`, `docs/FRONTEND.md`, `docs/RELIABILITY.md`, `docs/SECURITY.md`, `docs/OBSERVABILITY.md`, and `docs/REVIEW.md`.
- `docs/quality/golden-principles.md`, `docs/references/project-guardrails.md`, and `docs/references/boost-skill-routing.md`.
- `docs/operations/production-hosting-and-ops.md`, `docs/QUALITY_SCORE.md`, and `docs/exec-plans/tech-debt-tracker.md`.
- Relevant active execution plans, especially CJK tokenization, lyrics replacement, single-word editing, smoothening, and release hardening. Distinguish implemented behavior from planned learning features and SaaS roadmap work.

Do an independent code inspection before consulting prior review conclusions. Then reconcile your findings against `docs/architecture-review-report-*.md`, `docs/subtitle-pipeline-review-*.md`, `docs/smoothening*review*.md`, `docs/agent-prompts/*findings.md`, and completed remediation plans. Mark findings as new, recurring, already tracked, or resolved. Do not present a closed historical issue as a fresh discovery without demonstrating its recurrence.

Documentation contains historical layers. For example, some passages describe WAV preparation, optional Audio Isolation, separate translation stages, or earlier infrastructure restrictions. Verify the active code and later decisions before accepting those descriptions. Separate intended behavior, implemented behavior, and outdated documentation; do not silently resolve a product-contract conflict by treating implementation as the intended design.

Build a component map showing entrypoints, responsibilities, data ownership, dependencies, trust boundaries, and external services. Verify the stack from manifests and lockfiles: the current project includes a WXT/TypeScript extension, Laravel backend and Blade web surfaces, Redis queues, Postgres persistence, shared JSON Schema/OpenAPI contracts, ElevenLabs transcription, OpenAI through Laravel AI, and Stripe billing.

Use these concrete starting points, following additional callers and files as needed:

| Component | Starting points |
| --- | --- |
| Public API and contracts | `app/backend/routes/`, `app/backend/app/Http/`, `packages/contracts/` |
| Generation orchestration | `app/backend/app/Services/Subtitles/SubtitleJobService.php`, `SubtitleGenerationPipeline.php`, `SubtitleBatchDispatcher.php`, `SubtitleJobAdmission.php`, and `app/backend/app/Jobs/` |
| Audio acquisition/preparation | `app/backend/app/Services/Audio/YouTubeAudioSource.php`, `ElevenLabsScribeAudioPreparer.php`, `ScribeAudioChunker.php`, `SubtitleAudioWorkspace.php` |
| Transcription and reuse | `app/backend/app/Services/Transcription/`, including `ElevenLabsScribeTranscriptionService.php`, `ScribeChunkPayloadMerger.php`, `ScribeTranscriptNormalizer.php`, and `VideoTranscriptCache.php` |
| Language analysis and AI output | `app/backend/app/Services/TranslationAnalysis/`, `app/backend/app/Ai/Agents/`, `app/backend/app/Services/Text/`, `app/backend/app/Services/Languages/`, `packages/contracts/languages.json` |
| Tracks, partial delivery, and edits | `app/backend/app/Services/Subtitles/SubtitlePartialTrackAssembler.php`, `TimestampedSubtitleTrackGenerator.php`, `LyricsCorrectionService.php`, `app/backend/app/Models/`, and editing controllers/jobs |
| Browser integration and state | `app/extension/entrypoints/background.ts`, `content.ts`, `sidepanel/`, `app/extension/utils/api.ts`, `messages.ts`, `active-tracks.ts`, `account-session.ts`, and related state helpers |
| Subtitle display and study controls | `app/extension/utils/webvtt-track.ts`, `overlay.ts`, `overlay/`, `cue-navigation.ts`, `cue-hold.ts`, `panel/transcript.ts`, and side-panel transcript/timing controls |
| Accounts, billing, and web UI | `app/backend/app/Services/Auth/`, `Services/Billing/`, auth/billing controllers, middleware, `app/backend/resources/`, and migrations |
| Operations and verification | `app/backend/config/`, `app/backend/app/Console/Commands/`, `scripts/runtime/`, `scripts/ops/`, `scripts/agent/`, backend/extension tests, contract checks, and any CI configuration |

## 2. Deep review of subtitle generation

Trace the real execution graph from user action and admission through acquisition, preparation, transcription, merging, cue analysis, optional learning data, final publication, and browser display. Include cache hits, retries, partial results, failures, resets, cancellation/deletion, and correction flows. Identify each queue, external request, artifact, transaction, completion barrier, and cleanup owner.

The inspected baseline already includes per-video transcript caching, FLAC preparation at 16 kHz mono, overlapping transcription chunks, midpoint-based chunk merging, combined tokenization/translation for translated cues, optional romanization with some deterministic transliteration, bounded AI batch chains, on-demand word cards, and partial tracks. Verify their current behavior. Evaluate their shortcomings and interactions instead of recommending these capabilities as though they were absent. In particular, trace actual worker occupancy during synchronous external requests; queued stages alone do not demonstrate nonblocking I/O.

### Audio and speech recognition quality

- Examine how yt-dlp chooses and downloads the source, how duration is determined, and whether acquisition or conversion discards useful information or duplicates work.
- Evaluate the current channel mixing, resampling, encoding, file sizes, CPU cost, and upload cost. Compare the existing preparation with direct supported-source upload and alternative preparation only where current provider documentation supports the options. Do not claim that converting lossy source audio to a lossless container restores lost information, or that a higher sample rate automatically improves recognition.
- Investigate whether normalization, denoising, isolation, channel selection, or silence handling would help representative inputs. Consider speech, music/lyrics, noise, overlapping speakers, quiet speech, and stereo content. Separate improved listening quality from improved transcription accuracy. Identify possible artifacts and timestamp shifts; treat preprocessing benefits as hypotheses until evaluated.
- Inspect chunk thresholds, length, overlap, splitting cost, actual parallelism, provider account limits, duplicate billed audio, and retry amplification. Trace whether midpoint merging can omit, duplicate, reorder, or truncate words when neighboring transcripts disagree. Examine silence boundaries, repeated lyrics, chunk language disagreement, and audio offsets.
- Review explicit language selection, automatic detection, mixed-language speech, non-speech events, hallucinations on silence/music, punctuation, and timestamp validation. Identify where the current adapter discards provider fields that might be useful, but recommend retaining them only for a concrete purpose.

### Latency, efficiency, cost, and capacity

- Distinguish submission/admission delay, queue wait, active processing, external request time, upload/download time, time to first source cue, time to translated/romanized cues, and time to a complete usable track. Also distinguish single-job latency from system throughput and fairness under load.
- Reconstruct the critical path. Do not add durations of concurrent stages and call that total latency. Identify unnecessary serialization, barriers, repeated encoding, duplicate provider calls, artifact reads/writes, JSON serialization, polling payloads, and database contention.
- Evaluate batch size against prompt/output tokens, context duplication, quality, provider latency, split retries, and long-tail completion. Verify whether enabled features create avoidable dependencies or head-of-line blocking. Preserve context and token-boundary guarantees when proposing overlap or fusion of work.
- Inspect generation/batch queue separation, tier scheduling, per-user versus per-job concurrency, transcription fan-out, worker counts, provider-wide capacity, timeouts, retries/backoff, and crash recovery. Establish which limit actually constrains throughput before proposing more parallelism.
- Review transcript, generated-track, and word-card cache keys, invalidation, processing/model versions, retention, stampedes, and reuse across language/options/account boundaries. Distinguish safe sharing of public source transcripts from private account edits and derived state.
- Consider larger architectural improvements if evidence supports them. Compare them with a narrower change and retaining the current design. Explain migration cost, operational burden, expected benefit, and what evidence would justify the larger option. Do not rule out a strong solution merely because it is substantial.

### Linguistic quality, timing, and learning data

- Review cue segmentation, word alignment, punctuation, reading speed, cue duration, silence gaps, and boundaries for spaced scripts, CJK, Thai/Lao/Khmer, combining marks, RTL, and mixed scripts.
- Trace prompts, schemas, validators, fallback behavior, and retries. Check that structural validity is not being mistaken for linguistic quality. Examine context loss, prompt injection through source text, token drift, misleading successful fallback output, translation omissions, and inconsistent romanization.
- Verify the contracts governing token identity/order/text, cue translation, romanization, word cards, same-language requests, and partial/final tracks. Follow these invariants through every mutation and the browser cache.
- Review quick token fixes and full pasted-lyrics replacement: matching/timing, completeness and partial acceptance, revisions, cancellation, concurrent edits, stale worker results, rebuilding derived learning data, and atomic publication.
- Assess perceived quality in the extension: native track timing, seeking, playback-rate changes, cue holding, hovering/pausing, line wrapping, translation/romanization updates, and stale results. Distinguish timestamp errors, segmentation errors, and display/layout errors with evidence.

## 3. Review the rest of the system with equal rigor

For every component, assess its responsibilities, cohesion, coupling, state ownership, error handling, testability, and use of native framework/platform capabilities. Explain when an abstraction reduces complexity and when it merely moves it around. Look for missing boundaries as well as excessive layers, duplicate state, dead paths, oversized responsibilities, repeated validation after trusted boundaries, and unnecessarily fragile custom infrastructure. Avoid cosmetic findings unless they materially affect maintenance.

Specifically inspect:

- Cross-boundary contract/schema/type drift, unvalidated persisted/provider data, response sizes, compatible partial/final states, and error propagation.
- Extension service-worker suspension/restart, multi-tab isolation, YouTube SPA navigation and Shorts, message validation, request ordering, polling lifecycle, storage ownership, stale authentication, logout/account switching, cleanup, and browser permissions.
- Overlay and web accessibility, keyboard/focus behavior, RTL/i18n, responsive layout, DOM churn, listeners/observers, and memory growth during a long viewing session. Inspect the actual UI implementation rather than assuming a particular frontend framework.
- Account/job ownership and token scope; web/API authentication and authorization; input and process boundaries; SSRF/path/command risks where applicable; abuse controls; sensitive data in logs, caches, artifacts, and browser storage.
- Subscription entitlement, queue admission, minute reservation/debit/release, cache reuse billing, webhook ordering/idempotency, deletion, and account-level races. Trace transaction/lock boundaries and partial-failure scenarios before alleging double charges or unauthorized access.
- Persistence/query/index design, lock duration, artifact/event growth, expiry, stuck jobs, queue retry semantics, idempotency, and cleanup after hard worker termination. Check whether local audio paths and deployed worker topology actually fit together.
- Production scripts, worker supervision, scheduler/pruning, health signals, backups/restoration, rollback, secret management, dependency compatibility, and gaps between local tests and production behavior.
- Test quality: meaningful invariants versus implementation-mirroring mocks, integration gaps, concurrency coverage, real browser behavior, and Postgres/Redis semantics hidden by SQLite or queue fakes. Passing tests do not establish provider quality or production readiness.

## 4. Check current documentation for concrete opportunities

Research current official documentation and release notes during the review where they could change a recommendation. Prioritize ElevenLabs Scribe and Audio Isolation, OpenAI models/structured output, Laravel AI and queues, FFmpeg/yt-dlp, and WXT/browser extension APIs relevant to observed code.

Follow the repository's Context7 instructions: resolve the library ID first, then query with the full question and relevant version. If Context7 is unavailable or insufficient, say so and use official documentation directly. For OpenAI, follow applicable local OpenAI documentation guidance. Avoid relying on blog summaries or model memory for current API capabilities.

For each relevant discovery, record the official URL, date checked, release date if stated, capability/constraint, current code usage, installed-version compatibility, and concrete potential benefit. Explicitly distinguish documented support, provider marketing claims, and performance measured in this project.

Investigate options such as accepted audio formats, upload/size/duration limits, timestamp granularity, language/context controls, asynchronous submission/webhooks, current model choices, and rate/concurrency limits without assuming they are available or useful. Consider latency, quality, cost, retry/recovery semantics, privacy, and deployment requirements together. A newer model, streaming API, or additional service is an opportunity only if it improves this product's workflow.

## 5. Validate hypotheses and design decisive experiments

Read diagnostic command implementations before running them. Reuse local sanitized traces and the existing commands where available: `subtitles:runtime-check`, `subtitles:runtime`, `subtitles:trace`, `subtitles:slow`, and `subtitles:metrics` with their supported JSON options. Inspect `app/backend/app/Console/Commands/EvalTokenization.php` and its fixtures before using it; establish whether a mode calls a paid provider.

Run `scripts/agent/check.ps1` and relevant targeted existing checks when the environment supports them. Report exact commands, outcomes, and environmental blockers. Do not change dependencies or application settings to manufacture a green baseline. Distinguish test failures caused by the checkout from tool/environment limitations.

Where measurements are absent, provide a reproducible experiment instead of invented speedups or quality gains:

- Use short, medium, and near-limit public videos across representative scripts, speech/music/noisy audio, feature combinations, account tiers, and concurrent users. Separate cache-hit and cache-miss workloads.
- Record repeated-run sample counts and conditions; report p50/p95 only with sample-size caveats. Include queue wait, first usable cue, full completion, CPU/memory/disk/network load, provider requests/tokens, failure/retry rates, and estimated versus observed cost per source minute. Check for reset/run mixing and successful-jobs-only bias in existing metrics.
- Evaluate transcription with reference transcripts and language-appropriate WER/CER; evaluate timing and chunk-boundary omissions/duplicates separately. Use segmentation fixtures and bilingual human judgments for tokenization, translation, and romanization. Do not treat another model's preference as ground truth.
- For each proposed optimization, state the baseline, isolated change, expected mechanism, acceptance criteria, quality/reliability guardrails, and conditions under which you would reject it. Where improvements interact, test the combination after isolating their individual effects.

## Required report

Write plainly, lead with the most consequential findings, and include:

1. **Overall assessment:** what is well designed, the largest problems/opportunities, and the practical condition of the system. Do not infer code authorship or quality from the age of the model that wrote it.
2. **Architecture and coverage:** a component table and execution diagram showing real dependencies, stage owners, queues, artifacts, external calls, parallel work, and barriers. Mark each component reviewed, partially reviewed, or unreviewed, with evidence and limits.
3. **Confirmed findings, ordered by severity:** use stable IDs. For each, include category, severity, confidence, exact current `file:line` references, trigger/preconditions, observed behavior, root cause, user/operational impact, suggested improvement, and validation/reproduction evidence. Include a short refactor sketch only when useful. Distinguish reproducible defects from code-supported risks and unverified hypotheses.
4. **Ranked improvement opportunities:** cover performance, audio/transcription quality, learning quality, architecture, reliability, and developer workflow. For each, give the code location, expected mechanism and benefit, evidence level, effort, tradeoffs, dependencies, preserved/changed contracts, and decisive validation. Keep speculative experiments separate from fixes. Include all substantive findings; avoid padding or duplicate symptoms of the same root cause.
5. **Pipeline optimization analysis:** current critical path and available measurements, the best audio/latency/quality experiments, and any justified alternative architecture compared against both a smaller change and the current design. State where evidence is insufficient to choose.
6. **Current-provider/technology opportunities:** sourced capability changes and their actual applicability, including researched options rejected and why. If no useful update exists, say so.
7. **Prior-review reconciliation and documentation drift:** new versus tracked versus resolved findings, with links to earlier work and conflicting documentation.
8. **Action sequence:** immediate fixes, improvements worth scheduling, experiments needed before deciding, and changes best left alone. Include success criteria and rollout/rollback considerations for material recommendations. End with validation results, remaining evidence gaps, and a clear readiness assessment appropriate to a whole-project audit.

Every factual claim about this implementation needs a current code/test/config reference. External API claims need official source links. Quantitative claims need measurements or explicitly labeled estimates and assumptions. Do not claim a root cause is confirmed merely because it sounds plausible. A thorough review may conclude that a component should remain as it is; explain the evidence for that judgment too.
