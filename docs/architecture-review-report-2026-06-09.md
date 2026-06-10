# Architecture, Maintainability, and Simplification Review

Created: 2026-06-09
Branch: `main` (clean at `496a3a3`)
Scope: full repository — `app/backend`, `app/extension`, `packages/contracts`, `scripts/`, `docs/`
Prior review: `docs/architecture-review-report-2026-05-20.md`

Review stance: optimize for clarity, directness, maintainability, and traceable execution flow. Prefer loud failures over fake defaults. Prefer one clear active path over fallback-heavy compatibility layers. Compatibility code must justify itself with a real active consumer.

---

# Executive Summary

The repo is a three-part monorepo: a Laravel 13 backend (`app/backend`) running an async Redis-queued subtitle pipeline (yt-dlp → FFmpeg → ElevenLabs Scribe → OpenAI cue batches → Postgres track), a WXT MV3 extension (`app/extension`) with background hub + content overlay + side panel, and a schema-first contracts package (`packages/contracts`). The architecture is genuinely good for its stage: contract-validated boundaries, loud AI-output validation, an append-only billing ledger, run-id staleness guards, and an unusually disciplined docs/plan harness.

The cleanup themes are the residue of two fast migrations that finished without sweeping up:

1. **SQLite → Postgres/Redis (2026-05-18)** left a dead compatibility chain: SQLite lock detection in the failure handler, a `sqlite-local` worker group, `'database'` queue-connection fallbacks, and a test that locks the legacy behavior in.
2. **Popup → side panel (2026-06-07/08)** left pervasive `popup.*` naming, a fabricated anonymous "Local beta" account state from the pre-auth era, and — most importantly — **a dropped message handler that breaks three shipped keyboard shortcuts at runtime**.

Secondary themes: ~15 MB of dead marketing images (some carrying another product's feature names) in the public web root, 2,100 lines of self-declared "historical" design docs at repo root, duplicated micro-helpers (`hasReadyTrack` ×4, `effectiveSourceLanguage` ×4), four copy-pasted batch job classes (already flagged in the 2026-05-20 review, still standing), and a 450-line in-app process supervisor sitting in the production HTTP request path.

---

# Highest Priority Findings

## 1. Three keyboard shortcuts are broken: `content.updateSettings` has no handler

- **Severity:** Critical
- **Category:** correctness
- **Evidence:** `app/extension/entrypoints/content.ts:547` sends `content.updateSettings`; the switch in `app/extension/entrypoints/background.ts:76-125` has no case for it; the side panel listener (`app/extension/entrypoints/sidepanel/main.ts:192-200`) ignores it. Git history: commit `e72a51a` (Jun 1) added the handler `updateSettingsFromContent`; `6fdbc68` (Jun 1) removed it; `24f8cc5` (Jun 5, keyboard shortcuts) re-introduced the sender without restoring the handler.
- **Problem:** `handleRuntimeMessage` falls through the switch and resolves `undefined`, so `sendResponse(undefined)` reaches the content script, `response?.settings` is undefined, and `content.ts:558` throws. Every press of **Alt+Shift+T** (toggle translation), **Alt+Shift+B** (blur source), **Alt+Shift+A** (hover pause) shows the error toast "Unable to update extension settings." The settings never change.
- **Recommended refactor:** Restore a `content.updateSettings` case in background (persist via `updateExtensionSettings`, reply `{ ok: true, settings }`, echo `background.settingsChanged` to the sender tab) — or collapse it into the existing `popup.updateSettings` handler since both now just patch shared settings.
- **Expected benefit:** Shipped feature works again.
- **Validation:** Add a background round-trip test (see Testing Gaps); manual check that the three shortcuts toggle settings and update the overlay.

## 2. The `RuntimeMessage` union mixes three message directions and the dispatcher can't fail loudly

- **Severity:** High
- **Category:** architecture / correctness-risk
- **Evidence:** `app/extension/utils/messages.ts:66-119` puts panel→background (`popup.*`), content→background (`content.*`), and background→content/panel (`background.*`) messages in one union. Consequences: the background switch must defensively throw on `background.getPageSnapshot` (`background.ts:101-102`); `handleRuntimeMessage` returns `Promise<unknown>` so missing cases (finding #1) compile cleanly and fail silently at runtime; `sidepanel/main.ts:33-55` re-declares a private `PopupRequest` subset of the same union by hand.
- **Problem:** The type system cannot tell you a handler is missing. That is exactly how finding #1 shipped.
- **Recommended refactor:** Split into three unions by destination (`BackgroundRequest`, `ContentNotice`, `PanelNotice`). Give the background dispatcher a return type per request and an exhaustiveness check (`default: assertNever(message)`), and delete the throw-case workaround and the hand-copied `PopupRequest`.
- **Expected benefit:** A missing handler becomes a compile error; message flow becomes traceable by type.
- **Validation:** `npm run compile` must fail if any request member lacks a case; existing messages tests keep passing.

## 3. Dead SQLite compatibility chain across all three packages

- **Severity:** High
- **Category:** dead code / fake defaults
- **Evidence:**
  - `app/backend/app/Services/Subtitles/SubtitleJobFailureHandler.php:103-112` detects the SQLite-only message `'database is locked'` and maps it to `queue_unavailable`.
  - `app/backend/app/Services/Subtitles/SubtitleQueueWorkerBootstrapper.php:365-372` has a `sqlite-local` worker-group branch.
  - `app/backend/config/subtitles.php:15` and `app/backend/app/Services/Subtitles/SubtitleQueue.php:17` both silently fall back to `'database'` queue connection when env is missing — the retired runtime — while `.env.example` and `ARCHITECTURE.md` say Redis.
  - `app/backend/tests/Feature/SubtitleJobApiTest.php:413` fakes a SQLite `PDOException` to lock the dead path in.
  - The extension still ships user copy for it: `queue_unavailable` → "The subtitle queue database is busy…" (`app/extension/utils/api.ts:236-237`).
- **Problem:** SQLite was retired for runtime on 2026-05-18 (plan `2026-05-18-retire-sqlite-runtime-for-postgres-redis.md`; PHPUnit-only in-memory remains). On Postgres this error string never occurs, so the detection, the branch, the test, and the user-facing copy are unreachable compatibility theater. Worse, the `'database'` fallback default means a missing env var silently runs the wrong queue runtime instead of failing loudly.
- **Recommended refactor:** Delete `isDatabaseLocked` + the `queue_unavailable` branch in the failure handler, the `sqlite-local` worker group, and the locking test. Change the queue-connection default to `'redis'` (matching the documented runtime) or make a missing `SUBTITLE_QUEUE_CONNECTION` a startup error via the existing `ops:production-check`. Decide whether `queue_unavailable` stays in the contract error enum; if removed, drop the extension copy and schema entry together.
- **Expected benefit:** One real runtime path; misconfiguration fails loudly instead of degrading silently.
- **Validation:** `php artisan test --compact`, contracts `npm run check`, extension tests; `ops:production-check` against a missing-env profile.

## 4. In-app process supervisor runs inside the HTTP request path

- **Severity:** High
- **Category:** architecture / hidden side effects
- **Evidence:** `app/backend/app/Services/Subtitles/SubtitleQueueWorkerBootstrapper.php` (450 lines) is invoked from `SubtitleJobService.php:183` on every generate request: it takes a cache lock, lists cached PIDs, probes each PID via `Get-CimInstance`/`ps` command-line sniffing, and spawns detached `queue:work` processes via PowerShell `Start-Process` or `nohup sh -c`. All failures are swallowed into a `Log::warning` (lines 47-54).
- **Problem:** A web request forking OS processes is a large hidden side effect, even gated by `SUBTITLE_AUTO_START_WORKERS` (default: enabled outside production, `config/subtitles.php:19`). Production uses Supervisor (`scripts/runtime/render-supervisor-config.ps1`), so this entire class is local-dev convenience living in, and dependency-injected into, the core generation service. It is also the single biggest file in `Services/Subtitles`.
- **Recommended refactor:** Extract auto-start into a dev-only artisan command (`subtitles:dev-workers`) invoked by `scripts/runtime/start-local-backend-workers.ps1` / `compose`, and delete the `ensureRunning()` call from `SubtitleJobService::generate`. If request-path auto-start must stay for the local demo flow, isolate it behind an interface bound to a no-op in production so the request path provably cannot fork.
- **Expected benefit:** Generation request path becomes pure (DB + queue dispatch); 450 lines leave the runtime core; PID/cache/process-sniffing complexity becomes a tool, not a service.
- **Validation:** Backend feature tests (auto-start is already disabled in tests); one manual local run proving workers still come up via the script path.

## 5. Signed-out panel shows a fabricated "Local beta / 60 minutes" account

- **Severity:** Medium-High
- **Category:** fake defaults / dead code
- **Evidence:** `app/extension/utils/popup-saas-state.ts:6-45` — `accountStateFromJobHistory` invents `planName: 'Local beta'`, a 60-minute limit, minutes "used" computed from job history, and a fabricated reset date. Wired in `background.ts:519` whenever there is no session (history is always `[]` then, so the meter reads "0 of 60 min"). The panel renders it in the Usage section (`sidepanel/main.ts:487-499`).
- **Problem:** Since accounts/billing landed (Phase 03/04), generation requires sign-in (`generateButton` disabled, backend rejects anonymous). The anonymous "account" is fiction from the pre-auth era — exactly the fake-default pattern the repo's own `how_to_build.txt` forbids. It also drags `LOCAL_BETA_MONTHLY_MINUTES`, `billableMinutes`, and `nextMonthlyReset` along as dead weight.
- **Recommended refactor:** Make `AccountState` honestly anonymous (`{ status: 'anonymous' }` with no usage numbers), render the Usage panel as "Sign in to see usage," and delete `accountStateFromJobHistory` plus its helpers. The `showError` reset path in the panel uses it too — give that an explicit empty state.
- **Expected benefit:** UI stops displaying invented quota numbers; ~60 lines deleted.
- **Validation:** Update `popup-saas-state.test.ts` and panel render expectations; manual signed-out panel check.

## 6. `popup.*` naming survived the popup's deletion

- **Severity:** Medium
- **Category:** naming / maintainability
- **Evidence:** Popup retired in `5c045c1` ("retire popup; relocate shared renderers to side panel"), but: message types `popup.getState|updateSettings|generateSubtitles|login|logout|clearLocalState|seekToCue` (`utils/messages.ts`); type `PopupState`; files `utils/popup-progress.ts`, `utils/popup-saas-state.ts`; background functions `getPopupState`, `updateSettingsFromPopup`, `generateSubtitlesFromPopup`, `cachedPopupJobHistory`; tests `popup-progress.test.ts`, `popup-saas-state.test.ts`.
- **Problem:** Every new reader must learn that "popup" means "side panel." The concept rename was done in the UI and docs (`docs/FRONTEND.md` is correct) but not in code.
- **Recommended refactor:** Mechanical rename: `popup.*` → `panel.*` messages, `PopupState` → `PanelState`, files → `panel-progress.ts` / `account-state.ts`. Do it as one isolated commit; message strings are internal (background and panel ship together), so there is no compat constraint.
- **Expected benefit:** Active concept and code vocabulary match again.
- **Validation:** `npm test`, `npm run compile`, `npm run build`; grep for `popup` afterward should hit only history/docs.

## 7. ~15 MB of dead or mislabeled marketing assets in the public web root

- **Severity:** Medium
- **Category:** dead code / performance (repo + deploy size)
- **Evidence:**
  - `app/backend/public/img/marketing/dark-academia/` — 14 MB, six PNGs, **zero references** anywhere in views/CSS/JS/config (superseded by the Hermes black theme, plan `2026-06-05-hermes-black-theme-landing-page.md`).
  - `app/backend/public/img/desktop/` — `badge.webp`, `nous.webp`, `platform-art-{linux,mac,windows}.webp` (~875 KB) unreferenced.
  - The six *used* images are named `feature-connect/memory/tasks/automation/browse/sandbox.webp` — feature names from a different (desktop-AI-app) reference design, while their alt text in `MarketingPageController.php:152-210` describes subtitle features.
- **Problem:** Dead bytes ship with every deploy; the borrowed filenames make the landing page content untraceable ("Word cards" renders `feature-tasks.webp`).
- **Recommended refactor:** Delete the dark-academia directory and the five unused desktop images; rename the six used images to product terms (`landing-study-any-video.webp`, …) and update `landingFeatures()`.
- **Expected benefit:** −15 MB repo/deploy weight; traceable asset names.
- **Validation:** Load `/` locally; `SaasWebsiteAndSeoTest` passes; grep for old names returns nothing.

## 8. `/desktop` route is a duplicate home page with a foreign name

- **Severity:** Medium
- **Category:** naming / SEO correctness
- **Evidence:** `routes/web.php:14-15` → `MarketingPageController::desktop` renders the **same** `marketing.home` view with different metadata ("Download the beta Chrome extension…"); sitemap lists it at priority 0.9 (`SitemapController.php:14`); funnel analytics records it as a distinct page.
- **Problem:** "Desktop" is the reference design's concept, not this product's; the page is duplicate content with a misleading canonical, and it pollutes funnel metrics.
- **Recommended refactor:** Either build a real `/extension` install page (it has none today — that's the product's most important CTA) or delete the route, controller method, metadata entry, and sitemap line.
- **Expected benefit:** Honest sitemap/analytics; one less pseudo-page.
- **Validation:** `SaasWebsiteAndSeoTest`; sitemap snapshot.

## 9. Job-state micro-logic duplicated across five+ sites

- **Severity:** Medium
- **Category:** maintainability
- **Evidence:** `hasReadyTrack()` copied in `SubtitleJobService.php:330`, `SubtitleGenerationPipeline.php:456`, `SubtitleCueBatchProcessor.php:193`, `Api/SubtitleJobController.php:150`, plus inline in `SubtitleJobResource.php:21`. `effectiveSourceLanguage()` in pipeline, batch processor, `LearningTokenEnrichmentService.php:185`, and inline as `languagePair` in `DashboardController.php:91`. `isUniqueConstraintViolation()` in SubtitleJobService and UsageLedger. The `requiredString/requiredBoolean/requiredEnrichmentMode` validators exist twice (`Api/SubtitleJobController.php:156-190` vs `SubtitleJobResource.php:81-106`).
- **Problem:** These are domain predicates pretending to be private helpers. A future change to "ready track" semantics (e.g. grace period) must find five copies.
- **Recommended refactor:** `SubtitleJob::hasReadyTrack()` and `SubtitleJob::effectiveSourceLanguage()` as model methods; a tiny `PostgresErrors::isUniqueViolation()` support class; fold `jobHistoryItem()` (90 hand-rolled lines) into a `SubtitleJobHistoryResource` next to the existing resource so there is one serialization idiom.
- **Expected benefit:** One definition per concept; controller shrinks by ~half.
- **Validation:** Existing feature tests + `ContractResponseValidationTest` already pin the wire shapes.

## 10. Four copy-pasted batch job classes (standing debt from the 2026-05-20 review)

- **Severity:** Medium
- **Category:** maintainability / dead code
- **Evidence:** `app/backend/app/Jobs/TokenizeSubtitleCueBatch.php`, `TranslateSubtitleCueBatch`, `RomanizeSubtitleCueBatch`, `EnrichSubtitleCueBatch` are byte-identical except processor method, stage string, and failure message (75 lines × 4). `PrepareSubtitleCuesAfterAnalysisBatches` / `MergeSubtitleCuesAfterRomanizationBatches` are a second near-identical pair. Additionally each constructor sets `onQueue(SubtitleQueue::batchName())` — the *default-tier* queue — which `SubtitleBatchDispatcher.php:93` immediately overrides with the job-tier queue, so the constructor queue assignment is misleading dead config.
- **Problem:** The 2026-05-20 architecture report called out "repetitive queue job wrappers"; two more stages have been added since by copying again.
- **Recommended refactor:** One `abstract SubtitleCueBatchJob` holding tries/timeout/middleware/`failed()`/`queuedAtMs`, with four 6-line subclasses declaring `stage()` and the processor call (distinct class names preserve queue-dashboard/failed-job legibility). Remove the constructor `onQueue` defaulting (dispatcher owns queue placement; `ProcessSubtitleJob`/`FinalizeSubtitleJob` get queue+connection from their dispatch sites already).
- **Expected benefit:** ~220 lines deleted; adding a stage stops meaning "copy a file."
- **Validation:** Backend feature tests cover dispatch/skip/failure for each stage.

## 11. Processing-version combinatorics

- **Severity:** Low-Medium
- **Category:** maintainability
- **Evidence:** Eight `PROCESSING_VERSION_*` constants + a 25-line if-ladder (`SubtitleJobService.php:17-42, 301-328`) encode `mode × romanization × translation`; `CURRENT_PROCESSING_VERSIONS` enumerates all eight.
- **Problem:** Three booleans are hand-expanded into eight named constants; bumping the tokenizer version means editing nine lines in lockstep.
- **Recommended refactor:** A single `processingVersion(): string` composing `'scribe-v2-tokenizer-v8-async-'.$mode.($rom ? '-romanized' : '').($tr ? '-translated' : '')` plus a generated `CURRENT_PROCESSING_VERSIONS` (cross product) with one `VERSION_PREFIX` constant. Values stay byte-identical, so no migration.
- **Validation:** Assert generated set equals today's literals in a unit test; cache-reuse feature tests.

## 12. Pipeline `refresh()` spam obscures data ownership

- **Severity:** Low
- **Category:** maintainability / performance
- **Evidence:** `SubtitleGenerationPipeline.php:62-92, 212-220` — a dozen `$job->refresh()` calls, several discarded mid-expression (`$this->logger->...($job->refresh(), ...)`), three in the final five lines.
- **Problem:** Each is an extra query and a tacit admission that nobody knows which fields are stale; concurrent-update semantics are implicit.
- **Recommended refactor:** Refresh once after each mutation that other services depend on; pass the same instance forward. Keep the deliberate re-loads (`loadRunningJob` recheck before artifact write) — those are guards, not noise.
- **Validation:** Tracing tests (`SubtitleRuntimeTracingTest`) pin event content; behavior should be unchanged.

---

# Deletion Candidates

| Item | Evidence | Confidence | Check before deleting |
|---|---|---|---|
| `public/img/marketing/dark-academia/` (14 MB) | zero references in views/css/js/config | **High** | grep for `dark-academia` outside `docs/` |
| `public/img/desktop/{badge,nous,platform-art-*}.webp` (~875 KB) | zero references | **High** | same grep |
| SQLite chain: `isDatabaseLocked`, `sqlite-local` worker group, `database is locked` test | finding #3 | **High** | confirm `queue_unavailable` contract handling decision |
| `accountStateFromJobHistory` + `LOCAL_BETA_MONTHLY_MINUTES` + `billableMinutes`/`nextMonthlyReset` (`utils/popup-saas-state.ts`) | finding #5 | **High** | panel error-state rendering needs an explicit empty AccountState |
| `/desktop` route + `desktop()` + metadata + sitemap entry | finding #8 | **Medium** | product decision: replace with a real install page? |
| Root `detailed-design-document.md` (1,300 lines) + `revamped-design-document.md` (825 lines) | both self-declare "historical pre-refactor note, not the active baseline" | **High** (move, not delete) | move to `docs/history/`; AGENTS.md doesn't link them, nothing else references them |
| Constructor `onQueue(...)` defaulting in the four batch jobs | overridden by dispatcher at `SubtitleBatchDispatcher.php:93` | **High** | confirm no direct `::dispatch()` of batch jobs outside the dispatcher |
| `patchActiveTrack()` one-line wrapper (`background.ts:635`) | aliases `trackWithLearningToken` | **High** | inline it |
| `renderOverlayContent`/type re-exports in `utils/overlay.ts:32-33` | only `tests/overlay.test.ts` consumes the re-export | **Medium** | point the test at `overlay/overlay-render` and drop the re-export |
| One of the two identical skill trees `app/backend/.agents/skills/laravel-best-practices` vs `.claude/skills/laravel-best-practices` (`diff -rq`: identical, ~24 files each) | tool-vendored duplication | **Low-Medium** | these are tool-managed (Boost/Codex/Claude conventions); verify which tools actually read which path before consolidating |
| `publicJobTelemetry` wrapper (`popup-saas-state.ts:66`) | thin aggregation used once in job-history render | **Low** | fine to keep; delete only if touching the file anyway |

---

# Abstractions To Collapse

1. **`SubtitleQueueWorkerBootstrapper` out of the request path** (finding #4). Ownership model: HTTP request → DB row + queue dispatch, nothing else. Worker lifecycle belongs to Supervisor (prod) and a dev script/command (local).
2. **Four batch job classes → one abstract base** (finding #10). Ownership: the dispatcher owns queue placement; the job owns only identity (stage + processor call).
3. **`SubtitleJobController::jobHistoryItem` → a Resource** (finding #9). Ownership: serialization lives in `Http/Resources`, validated once.
4. **Dual observability entry points.** `SubtitleGenerationPipeline` calls both `$this->logger->X()` *and* `$this->telemetry->Y()` at each stage, while `SubtitlePipelineTelemetry` itself wraps logger + tracer. Pick one rule: pipeline talks to telemetry only; telemetry owns the logger/tracer fan-out. This is a call-graph cleanup, not a rewrite — the trio (~1,000 lines for a ~500-line pipeline) is deliberate per `docs/OBSERVABILITY.md`, but the split entry points blur who records what. Also collapses the 11-dependency constructor by one.
5. **`LaravelAiTranslationAnalysisProvider` naming.** It tokenizes, translates, romanizes, enriches, and builds word cards; "TranslationAnalysis" and "Provider" are both pre-refactor vocabulary (the provider interface was removed in the May 14 simplification). Rename to something like `Ai\CueAgents` / `SubtitleAiService` when next touched; don't churn it standalone.
6. **`PopupRequest` union in the panel** (finding #2) — delete in favor of the shared (split) message types.

---

# Runtime Flow Simplification

**Current extension flow:** content/panel → one mega-union message bus → background switch (non-exhaustive, returns `Promise<unknown>`) → per-tab in-memory `Map` + storage-backed track memory + module-level history cache; panel polls `popup.getState` every 10 s; content relays cue changes through background re-broadcast. The flow itself is sound for MV3 (state recovers from `active-tracks` storage and backend history after service-worker restarts — well done). The problems are at the edges:

- **Direction-blind message bus** (findings #1–2): the simpler model is three typed channels with compile-time exhaustiveness.
- **Active-tab guessing:** `popup.seekToCue` and settings broadcasts resolve `getActiveTab()` at message time (`background.ts:118-124`). The panel's transcript targets *the video the track is bound to*, not "whatever tab is active now." Targeting the tab whose `tabSubtitleStates` entry matches the track's `youtubeVideoId` would remove a class of wrong-tab seeks when focus changes mid-click.
- **Sequential awaits that could be parallel:** `getPopupState` awaits `getActiveTab`, `getOrCreateInstallId`, `getExtensionSettings`, session, snapshot, account sync, then history strictly in sequence (`background.ts:477-507`); the first four and the account-sync/history pair are independent — `Promise.all` would roughly halve panel refresh latency.

**Current backend flow:** request → entitlement/reserve/create (transactional, good) → queue → pipeline stages with run-id guards → batch fan-out → continuation → finalize. This is a clean single path. The simplifications are: remove the request-path worker fork (#4), the SQLite branches (#3), the queue defaulting that contradicts the documented runtime (#3), and the refresh noise (#12). One small contract-drift risk to make explicit: trace-only stage names `assembling-analysis-results` / `merging-romanization-results` (`SubtitleGenerationPipeline.php:118,152`) are not part of the public stage enum the extension validates (`utils/messages.ts:203-213`) — fine today because they never reach `jobs.stage`, but worth a comment or constant set so nobody "fixes" a trace stage into the public column.

---

# Testing And Validation Gaps

1. **No message round-trip coverage.** `tests/keyboard-shortcuts.test.ts` tests key→action mapping; `tests/messages.test.ts` tests the guard. Nothing asserts "every `RuntimeMessage` the content/panel sends has a background handler" — which is why finding #1 shipped silently. Cheapest fix is the type-level exhaustiveness from finding #2; a small background-dispatch unit test (handler returns non-undefined for every request member) would also have caught it.
2. **A test locks in dead SQLite behavior** (`SubtitleJobApiTest.php:413`) — delete with the path (finding #3).
3. **`popup-saas-state.test.ts` pins the fabricated anonymous account** (fake 60-minute plan), entrenching finding #5; rewrite alongside.
4. **Browser smoke remains manual** — TD-003/TD-007 are honest about it; the transcript-panel work increased UI surface without narrowing the gap. Worth prioritizing before store submission since three broken shortcuts survived a week of green checks.
5. **Worker bootstrapper has no tests at all** (process spawning, PID parsing, PowerShell quoting) — fine *if* it is demoted to a dev tool per finding #4; alarming if it stays in the request path.

---

# Documentation Drift

- **`docs/DESIGN.md:16`** references `app/extension/entrypoints/popup/style.css` and `popup/styles/` — paths deleted with the popup; lines 14/42 still describe "the extension popup." `docs/FRONTEND.md` is correct; DESIGN.md was missed.
- **`docs/product-specs/index.md:48`** — "inspecting/searching the generated transcript sidebar without opening the popup": both nouns are dead (transcript is a panel view; popup removed). `docs/QUALITY_SCORE.md:9` repeats "transcript sidebar."
- **User-facing copy:** the shortcut help still says "Open or close the transcript sidebar" (`utils/keyboard-shortcuts.ts:94`) for an action that now focuses the panel.
- **`ARCHITECTURE.md:114`** — "raw credentials stay in popup-to-background login messages": concept right, vocabulary stale.
- **Root historical docs** (see Deletion Candidates): 2,125 lines of self-declared obsolete design at the repo root, above the fold for every agent session.
- **`config/subtitles.php` vs `ARCHITECTURE.md`**: docs say Redis runtime; code default says `database` (finding #3).
- Positive: the exec-plans/tech-debt tracker is current and honest; FRONTEND.md tracked the panel migration same-day. The drift is concentrated in DESIGN.md, product-specs, and in-code copy.

---

# Refactor Roadmap

**Phase 1 — Fix the regression + safe deletions** (risk: low; the one behavioral change is a bug fix)
- Restore `content.updateSettings` handling (#1).
- Delete: dark-academia dir, 5 dead desktop images, SQLite chain + its test, fabricated anonymous account state, root design docs → `docs/history/`, `patchActiveTrack`, batch-job constructor `onQueue` defaults.
- Files: `background.ts`, `popup-saas-state.ts` (+tests), `SubtitleJobFailureHandler.php`, `SubtitleQueueWorkerBootstrapper.php`, `SubtitleJobApiTest.php`, 4 job classes, `public/img/**`, root `.md` moves.
- Validate: `.\scripts\agent\check.ps1` (runs contracts check, `php artisan test --compact`, extension `npm test`/`compile`/`build`), plus a manual Alt+Shift+T/B/A check on a bound track.

**Phase 2 — Naming and docs alignment** (risk: low, mechanical)
- `popup.*` → `panel.*` across messages/types/files/tests (#6); fix DESIGN.md, product-specs, QUALITY_SCORE, shortcut copy; decide `/desktop` (#8); rename the six `feature-*.webp` (#7 remainder).
- Files: ~15 extension files, 4 docs, `MarketingPageController.php`, `routes/web.php`, `SitemapController.php`.
- Validate: same harness; grep `popup`/`desktop`/`sidebar` audit.

**Phase 3 — Abstraction collapse** (risk: medium)
- Split the message union + exhaustive dispatcher (#2); batch-job base class (#10); model methods for `hasReadyTrack`/`effectiveSourceLanguage` + history Resource (#9); processing-version composition (#11).
- Validate: full harness; `ContractResponseValidationTest` and cache-reuse tests are the safety net.

**Phase 4 — Runtime flow** (risk: medium-high; do behind its own plan doc per repo convention)
- Extract worker auto-start to a dev command (#4); queue-connection loud default (#3 tail); telemetry single entry point; parallelize `getPopupState` awaits; track-bound tab targeting for seeks.
- Validate: backend tests + one real local generation through the script-started workers; `ops:production-check`.

**Phase 5 — Test/doc hardening**
- Background dispatch round-trip test; assert generated processing-version set; delete legacy-locking tests; progress TD-003/TD-007 browser smoke before store submission.

---

# Non-Issues

Things that looked suspicious but should stay:

- **The observability volume** (tracer + logger + telemetry, ~1,000 lines; 9 console commands). It's the repo's stated strategy (`docs/OBSERVABILITY.md`), sanitization is tested, and tracer failures deliberately degrade to a warning rather than killing jobs. Only the dual entry-point inconsistency is worth touching.
- **Tokenization split-retry** (`LaravelAiTranslationAnalysisProvider.php:46-80`). Looks like a retry-loop smell, but it's bounded (halves until single cue, then fails loudly), reason-gated, and documented in ARCHITECTURE.md. It hides nothing.
- **Voice-isolation fail-open** (`ElevenLabsScribeAudioPreparer.php:44-55`) and the dual decode fallback — config-gated, loudly logged, and explicitly tracked as TD-014 pending staging evidence. That's a fallback with a paper trail, not a dodge.
- **`UsageLedger`** — the append-only event design with idempotency keys is more machinery than a counter column, but it's the right call for billing disputes and is cleanly built.
- **Run-id staleness re-checks before every artifact write** — duplicated-looking `loadRunningJob` calls are concurrency guards, not noise.
- **`processing_version` query filters repeated in four controllers** — verbose but each query genuinely differs (windowing, limits, expiry rules); a shared scope (`SubtitleJob::scopeCurrentVersion()`) is a nice-to-have, not debt.
- **`config/queue.php` `background`/`deferred`/`failover` connections** — Laravel 13 skeleton defaults, not project legacy.
- **`compose.yaml` + runtime scripts** — match the documented Postgres/Redis profile; no drift found.
- **Contracts package** (`packages/contracts`) — the committed `dist/index.d.ts` is regenerated and diff-checked by `npm run check` in the harness, so it can't silently drift; fixtures and backend `ContractResponseValidationTest` give real cross-package enforcement. This is the best part of the codebase — protect it in every refactor above.

One process note: the highest-value single change is Phase 1's first item — the shortcut regression is user-visible today, and the exhaustiveness fix in Phase 3 is what makes that class of bug structurally impossible rather than just patched.
