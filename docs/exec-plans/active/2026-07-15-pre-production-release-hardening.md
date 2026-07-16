# Plan: Pre-Production Release Hardening

Status: active
Owner: agent
Created: 2026-07-15
Last updated: 2026-07-16

## Goal

Prepare the existing product for a controlled paid-beta deployment without adding a new product feature. Fix the concrete dependency, billing, lifecycle, security, release-content, and UI defects found in the 2026-07-14 release review, including the stale processing/cache versions left behind by already-landed prompt changes. This plan is a cleanup pass, not a quality-research program: the CJK tokenization experiments, Audio Isolation A/B, and related evidence gathering stay deferred in the Track B plan and the tech-debt tracker.

This plan produces a review-ready branch. It does not authorize merging, deploying, changing live Stripe data, contacting users, publishing the extension, or turning on production traffic. External staging and production operations remain gated on user approval after branch review.

## Execution Contract

- Work only on the branch provided by the user. Do not create, switch, merge, or push branches unless the user asks.
- Read `AGENTS.md` and the smallest relevant linked docs before editing. For Laravel work, follow `docs/references/boost-skill-routing.md`.
- Treat this file as the coordinating plan. Keep implementation decisions, progress, validation, and residual risk here while the work is active.
- The existing CJK plan (Track B) remains the home for tokenization-quality experiments, and that work is deferred — this plan does not run fixture reviews, prompt/model A/Bs, or Audio Isolation experiments. Only the processing/cache-version defect those landed changes left behind is fixed here.
- Preserve pre-existing worktree changes. At plan creation, these files were already modified and are user-owned:
  - `docs/exec-plans/active/2026-06-18-track-b-cjk-tokenization-quality.md`
  - `docs/exec-plans/active/saas-roadmap/06-production-hosting-and-ops.md`
- Make the narrowest change that satisfies each acceptance criterion. Do not use this plan as permission for a broad architecture rewrite.
- Add regression tests with behavior changes. A green existing suite is not evidence for the uncovered races and billing transitions in this plan.
- Do not fabricate legal approval, screenshots, Stripe events, production connectivity, or operational evidence. If required external input is unavailable, record the exact missing input and leave the relevant checkbox open.
- Do not expose secrets, raw provider payloads, prompts, full transcripts, generated learning content, raw audio paths, access tokens, install IDs, or private Stripe data in logs, fixtures, screenshots, commits, or plan evidence.
- Do not mark the plan complete until repository acceptance criteria are met, all required checks pass, the diff has been self-reviewed, and external-input items are either completed or explicitly moved to the existing production-ops plan with user approval.

## Scope

- In scope:
  - Runtime dependency remediation and repeatable release audit gates.
  - Stripe checkout/customer idempotency, subscription safety, webhook ordering, and real test-mode evidence when credentials are authorized.
  - Correct usage-ledger settlement across billing-period and subscription changes.
  - Entitlement protection for paid on-demand AI calls.
  - Extension-token invalidation after password reset.
  - Running-job deletion, workspace cleanup, and in-flight provider/artifact race hardening.
  - Whitelisting/normalizing stored Scribe chunk payloads.
  - Production connectivity/readiness checks and a verified security-header baseline.
  - Release placeholders, deferred-feature controls, signed-out account copy, mobile-header layout, extension versioning, and exact production host-permission checks.
  - Processing-version invalidation after material prompt/model changes.
  - Active-plan, reliability, security, operations, quality-score, and technical-debt cleanup caused by this work.
- Out of scope:
  - Sentence mining, saved vocabulary, Anki export, speaking/shadowing, AI coaching, teams/schools, subtitle editing, or other new learning features.
  - New transcription providers, payment providers, platforms, or a second frontend.
  - Marketing/growth Phase 08 and public-launch Phase 09 implementation.
  - Subtitle-quality evidence gathering: CJK/Thai gold-fixture expansion and native review, prompt/model A/B runs, token-cost capture, quality thresholds, Audio Isolation A/B, and the public-video quality matrix. This work stays deferred in the Track B plan and TD-007–TD-010/TD-014; do not run provider-backed experiments as part of this plan.
  - A deterministic CJK segmenter, morphology service, or NLP sidecar.
  - A broad split of `LaravelAiTranslationAnalysisProvider` or large entrypoint files unless a narrow extraction is required to make an accepted fix testable.
  - Live production deployment, Chrome Web Store submission, live customer communication, or irreversible external changes.

## Baseline Review Evidence

The implementing agent must reproduce the relevant baseline before changing behavior and record any differences in the Progress Log.

- `scripts/agent/check.ps1` passed on 2026-07-14:
  - Laravel: 294 tests and 2,374 assertions.
  - Extension: 26 test files and 146 tests.
  - Contract validation/type generation, TypeScript compile, and WXT Chrome production build passed.
- `composer audit --locked --no-dev` reported 13 advisories across eight runtime packages, including a high-severity Laravel advisory.
- `app/extension` reported 10 npm advisories: 3 critical, 4 high, 2 moderate, and 1 low, concentrated in development/build tooling.
- `packages/contracts` reported 2 npm advisories: 1 high and 1 moderate.
- Browser smoke loaded the unpacked extension on the documented Arabic YouTube video, found one `#tse-overlay-host`, and reported no page errors.
- Public-site browser smoke found:
  - The product name and `Sign in` overlap at 375 x 812.
  - `/support` exposes `support@example.test`.
  - `/terms` identifies itself as review copy.
- Static review found visible deferred “Save cue” controls, signed-out account feature rows that imply availability, extension version `0.0.0`, and a processing prompt change without a processing-version bump.

A second code review on 2026-07-15 reproduced every finding above statically. Confirmation anchors, so the implementing agent does not have to re-derive them:

- B: `BillingController::checkout` has no existing-subscription guard. `StripeClient::customerIdFor()` is check-then-act with no idempotency key on customer or checkout-session creation. `StripeWebhookService::handleCheckoutCompleted()` overwrites customer/subscription/plan without any identity check. `handleSubscriptionChanged()` orders events only by Stripe's second-resolution `created` timestamp. `handleInvoicePaymentFailed()` marks `past_due` without matching the invoice to the current subscription. Replay protection and retryable failure handling exist and work.
- C: `UsageLedger::adjustReservationToActualDuration()`, `debitCompletedJob()`, and `releaseReservation()` all settle against `periodForUser()` — the user's current period — not the original reservation's period or subscription. `reservedMinutesForJob()` sums every event for the job across periods and runs. Usage events already store `stripe_subscription_id` and period columns, so original-reservation settlement is implementable without schema changes.
- D: `LearningTokenEnrichmentService::enrich()` checks track ownership and expiry but never entitlement before the paid provider call (the route has throttling, but throttling is not entitlement). `ResetUserPassword::reset()` only rehashes the password — extension tokens and `remember_token` survive.
- E: `WebSubtitleJobController::destroy()`/`clearAll()` release the reservation and delete the row but never delete the run's audio workspace. `SubtitleGenerationPipeline::transcribeAudioChunk()` persists the chunk artifact without rechecking job existence/run/status after the provider call. `ElevenLabsScribeTranscriptionService::transcribeChunk()` stores the raw provider payload validated only as "is an array".
- F: `ops:production-check` reads config only — no live Postgres/Redis probe — and does not check the support email, extension URL, release version, host permissions, or mail. No security-header middleware exists anywhere in `app/`. Both layouts load Google Fonts from external origins and `site.blade.php` has the inline `has-js` script.
- G: extension version is `0.0.0`; `SUPPORT_EMAIL` defaults to `support@example.test`; `/terms` self-identifies as review copy; Save cue controls exist in four files; the signed-out Account tab labels cue translation, romanization, and Full word cards as "Available".
- H/I: `SubtitleJobService::VERSION_PREFIX` (`scribe-v2-tokenizer-v8-async-`) last changed in `2f75b0e`, before prompt changes `3a8f5b3` (merged tokenize+translate) and `4050b54` (grapheme-cluster fallback). CJK gold fixtures are a 12-cue-per-language starter set with no token-usage capture, matching the Track B plan's own residual-risk notes. `docs/RELIABILITY.md` says Audio Isolation is enabled by default while `config/subtitles.php` defaults it off. `00-phase-index.md` says `Status: completed` while sitting in `active/`, and its `saas-roadmap/04*` links point at plans that now live in `completed/`.

The same review added these findings that the plan had missed:

- No `MAIL_*` configuration exists anywhere (`.env.example` has no mail settings, `ops:production-check` has no mail check), while email verification gates every dashboard route (`verified` middleware) and every extension API route (`EnsureApiUserEmailIsVerified`) and password reset depends on outbound mail. Without a configured production mailer, no new user can verify and the product is unusable.
- No self-serve account deletion exists. The `users` cascade would delete local jobs and ledger rows, but nothing cancels the Stripe subscription, so a deleted account could keep billing. The approved Terms/Privacy copy must match whatever deletion path actually exists.
- No trusted-proxy configuration and no production session-cookie posture (`SESSION_SECURE_COOKIE` is unset everywhere). Fortify email-verification links are signed URLs; behind a TLS-terminating proxy they can generate or validate incorrectly without a trusted-proxy or hosting contract decision.
- `scripts/runtime/build-extension-release.ps1` already rejects a non-HTTPS API, a missing expected host permission, and the localhost host permission — but not version `0.0.0`. Extend that script rather than building a parallel gate.
- The webhook same-second guard compares with `<=`, so a same-second successor event (for example `customer.subscription.created` then `customer.subscription.updated` within one second) is silently dropped, and the guard never checks subscription identity.
- `ResetUserPassword` also never rotates `remember_token`, so remember-me sessions survive a password reset along with extension tokens.
- The Save cue button is rendered by `app/extension/utils/panel/transcript.ts` in addition to the three files listed below.
- Three independent version/cache surfaces exist and can drift: `SubtitleJobService::VERSION_PREFIX` (`scribe-v2-tokenizer-v8-async-`, last bumped in `2f75b0e`, before the merged tokenize+translate prompt change `3a8f5b3` and the grapheme-cluster fallback `4050b54`), the `VideoTranscriptCache` key (transcription model only — a normalizer change silently reuses cached transcripts), and the learning-token cache key version string (`v7-agent-tokenizer-boundaries` in `LearningTokenEnrichmentService`).
- `StripeWebhookEvent` rows are never pruned; `subtitles:prune-expired` covers tracks, jobs, and cached transcripts only.
- Existing protections confirmed working, so the fixes stay narrow: Stripe webhook signature verification, event-ID replay protection, API throttling and token abilities on all `/v1` routes, Sanctum token expiry (30 days), FK cascades from jobs to artifacts/tracks/events, and email-verification middleware.

Primary finding locations:

- Dependencies: `app/backend/composer.lock`, `app/extension/package-lock.json`, `packages/contracts/package-lock.json`.
- Checkout/customer creation: `app/backend/app/Http/Controllers/BillingController.php`, `app/backend/app/Services/Billing/StripeClient.php`.
- Webhooks: `app/backend/app/Services/Billing/StripeWebhookService.php`.
- Usage settlement: `app/backend/app/Services/Billing/UsageLedger.php`.
- Paid AI calls: `app/backend/app/Services/TranslationAnalysis/LearningTokenEnrichmentService.php`.
- Password reset: `app/backend/app/Actions/Fortify/ResetUserPassword.php`.
- Running-job deletion and artifacts: `app/backend/app/Http/Controllers/WebSubtitleJobController.php`, `app/backend/app/Services/Subtitles/SubtitleGenerationPipeline.php`, `app/backend/app/Services/Subtitles/SubtitleJobArtifactStore.php`, `app/backend/app/Services/Subtitles/SubtitleJobFailureHandler.php`.
- Scribe payloads: `app/backend/app/Services/Transcription/ElevenLabsScribeTranscriptionService.php`.
- Readiness/deploy: `app/backend/app/Console/Commands/CheckProductionReadiness.php`, `app/backend/app/Console/Commands/CheckSubtitleRuntime.php`, `scripts/runtime/deploy-managed-laravel.ps1`.
- Release UI/content: `app/backend/config/marketing.php`, `app/backend/resources/views/marketing/terms.blade.php`, `app/backend/resources/views/marketing/support.blade.php`, `app/extension/package.json`, `app/extension/entrypoints/sidepanel/transcript-view.ts`, `app/extension/entrypoints/content.ts`, `app/extension/utils/keyboard-shortcuts.ts`, `app/extension/utils/panel/transcript.ts`, `app/extension/entrypoints/sidepanel/render/account.ts`, `scripts/runtime/build-extension-release.ps1`.
- Mail/proxy/session posture: `app/backend/.env.example`, `app/backend/bootstrap/app.php`, `app/backend/app/Actions/Fortify/ResetUserPassword.php`, `app/backend/app/Console/Commands/CheckProductionReadiness.php`.
- Quality/versioning: `app/backend/app/Services/Subtitles/SubtitleJobService.php`, `docs/exec-plans/active/2026-06-18-track-b-cjk-tokenization-quality.md`, `docs/exec-plans/tech-debt-tracker.md`.

## Acceptance Criteria

### A. Dependency and release gates

- [x] Update Composer dependencies to supported patched versions without changing the intended Laravel/PHP architecture.
- [x] `composer audit --locked --no-dev` exits successfully with no known runtime advisories.
- [x] Apply non-breaking extension and contracts dependency updates. Do not use `npm audit fix --force`, accept an unrelated downgrade, or weaken the toolchain only to make the count zero.
- [x] `packages/contracts` has no unresolved high or critical advisory.
- [x] The built extension has no high or critical advisory in code shipped to browser users. Any residual development-only advisory is documented with its dependency chain, why it is not shipped, available upstream fix, owner, and follow-up status.
- [x] Add the Composer runtime audit and appropriate npm production/package audit to a deterministic release or deploy gate. Keep network-dependent audit work out of the fastest local loop if it would make normal offline checks unreliable.
- [x] Update `docs/QUALITY_SCORE.md` and `TD-002` so their advisory counts and risk descriptions match current evidence.

### B. Billing and Stripe correctness

- [x] Users with a live or otherwise non-terminal Stripe subscription cannot start another subscription checkout. The UI and controller enforce the same rule; plan changes and payment recovery direct users to the billing portal.
- [x] Define and test which local Stripe statuses are terminal versus portal-managed. Do not silently treat `past_due`, `unpaid`, `incomplete`, or cancel-at-period-end access as “no subscription.”
- [x] Concurrent first checkout attempts cannot create multiple Stripe customers for one local user.
- [x] Stripe customer and Checkout Session creation use bounded, stable idempotency keys for the same logical intent. Keys contain no secrets or personal data.
- [x] A repeated click/request either reuses the intended checkout or returns a safe deterministic outcome; it cannot create an orphaned subscription that the local account and portal do not track.
- [x] `checkout.session.completed`, subscription changes, and invoice failures cannot let an event for an older or different subscription replace a newer current subscription.
- [x] Webhook ordering does not rely only on Stripe’s second-resolution event timestamp. Resolve authoritative current state or use another documented ordering rule that handles equal-second events and out-of-order delivery.
- [x] Event-ID replay protection remains intact and failed local mutation remains retryable rather than permanently marked handled.
- [x] Add tests for duplicate checkout, concurrent first customer creation, active/trialing/past-due checkout attempts, old checkout after new subscription, old invoice failure after new subscription, same-second subscription events, replayed events, and plan change/cancel-at-period-end behavior.
- [ ] When authorized test-mode credentials are available, capture a sanitized Stripe proof covering checkout, portal, upgrade/downgrade or plan change, cancellation, failed payment, delayed/replayed webhook, and local state/ledger results. Never commit secrets or full webhook payloads.

### C. Usage-ledger correctness

- [x] Reservation adjustment, debit, and refund settle against the original reservation’s billing period, plan, and Stripe subscription identity rather than the user’s mutable current period.
- [x] `reservedMinutesForJob()` cannot combine unrelated periods/runs into a misleading balance.
- [x] A job reserved before renewal and completed after renewal leaves neither a stranded old-period reservation nor a negative/new-period reservation.
- [x] A job reserved before renewal and failed or deleted after renewal cannot increase the new period above its grant.
- [x] Subscription or plan changes during a running job have a documented and tested settlement rule.
- [x] Existing same-period behavior, idempotency, completed-track reuse, reservation release, and manual adjustment behavior remain correct.
- [x] Completion, failure, and deletion compete for one terminal settlement identity, so only one terminal minute event can win for a job run.

### D. Entitlement and account-session security

- [x] A canceled, unpaid, past-due, or otherwise ineligible account cannot trigger a new paid OpenAI on-demand token enrichment call.
- [x] Recommended behavior: an owner may receive already-cached metadata for an unexpired track without another provider call, but a cache miss requires active entitlement and an explicit bounded cost/rate allowance. Record any different product decision before implementation.
- [x] The learning-token response keeps stable public errors and does not reveal billing/provider internals.
- [x] Password reset revokes extension personal-access tokens and invalidates other durable login/remember state according to the project’s documented account-security posture.
- [x] Tests prove an extension bearer token works before reset and fails after reset, while the reset owner can authenticate again normally.

### E. Job deletion, artifact, and privacy safety

- [x] Deleting one job or clearing all jobs deletes the per-run audio workspace even when the job is queued or running.
- [x] Every provider call that can outlive a state transition rechecks job existence, `run_id`, running status, and completion state after the call and before persistence or downstream dispatch.
- [x] Artifact persistence cannot recreate raw artifacts for a deleted, failed, completed, or stale run.
- [x] Cleanup remains idempotent when deletion, failure handling, stalled-job handling, and batch callbacks race.
- [x] Tests cover deletion during transcription, failure while a chunk is in flight, another chunk winning failure cleanup, clear-all with running jobs, stale-run callbacks, and no workspace/artifact residue.
- [x] Normalize and whitelist Scribe chunk data at the provider boundary. Persist only the language and word/timing fields required by the current merger, plus the existing safe chunk bounds.
- [x] Malformed or drifted provider payloads fail with the existing stable public transcription error and sanitized context.
- [x] Present Scribe spoken-word timings are finite, non-negative, and strictly increasing. Normalize documented zero-duration tokens to intentionally untimed tokens; both timing fields may also arrive absent.

### F. Production readiness and HTTP security

- [x] Keep `/up` a cheap liveness endpoint and add/use a separate readiness command or endpoint that actually proves Postgres, subtitle queue Redis, and concurrency Redis connectivity with bounded timeouts and no mutation beyond a safe ping.
- [x] The deploy workflow runs the connectivity readiness check before migrations/traffic cutover and reports which dependency failed without printing connection strings or credentials.
- [x] Production readiness rejects `support@example.test`, missing public support contact, missing Chrome extension URL, placeholder release version, unsafe debug/logging settings, and localhost extension host permissions.
- [x] Implement a security-header baseline in application middleware or document and test the exact reverse-proxy contract. Cover at least content sniffing, framing, referrer policy, permissions policy, HTTPS/HSTS behavior, and CSP.
- [x] Remove the inline `has-js` script or use a nonce/hash so CSP is deliberate. Account for the currently used Google Fonts origins or move the site to self-hosted fonts if that is the simpler product-safe choice.
- [x] Feature and command tests cover production versus local/testing behavior; local development must remain usable.
- [ ] Configure production transactional email for verification and password-reset mail: a real mailer choice with `MAIL_*` settings in `.env.example` and config, a safe local default (`log`), and a production readiness check that rejects the `log`/`array` mailers and a placeholder from-address. Tests cover that registration verification mail and password-reset mail are actually dispatched. The concrete provider/credentials are a user decision; leave the checkbox open if unavailable and record the missing input.
- [ ] Decide and implement the reverse-proxy/URL-generation contract: trusted proxies (or an equivalent documented hosting contract) so HTTPS detection and signed email-verification links are correct behind the chosen proxy, and a production session-cookie posture (secure, HttpOnly, SameSite) verified by tests alongside the security headers.
- [x] Give processed `StripeWebhookEvent` rows a documented retention/pruning rule so the table cannot grow unbounded; keep unprocessed/errored rows visible for retry.
- [ ] Before production traffic, provider and Stripe secrets in use are freshly issued rather than the keys that sat in local `.env` files (the Track B plan already flagged the local `OPENAI_API_KEY` for rotation). Record rotation as done without printing values; this is user-gated external work.

### G. Release surface and existing-feature polish

- [ ] Replace the support placeholder with a real operator-controlled address in production configuration and keep a safe explicit local value for development/tests.
- [ ] Replace review-draft Terms and Privacy content with user-approved production copy, including the operator identity and effective date required by that approved copy. The implementing agent must not invent legal terms or claim legal approval.
- [ ] Set a real extension release version and make release packaging fail for `0.0.0`, localhost host permissions, or a non-HTTPS production API. Extend the existing `scripts/runtime/build-extension-release.ps1`, which already enforces the HTTPS and host-permission rules, with the version check; do not build a parallel gate.
- [x] Remove all visible Save cue controls, shortcut labels, handlers, and “Soon/Phase 02” copy while sentence mining is deferred. Remove related dead tests/state rather than hiding the control with CSS.
- [x] Signed-out extension account UI says sign-in/plan confirmation is required; it does not claim cue translation, romanization, or Full word cards are available before entitlement is known.
- [x] The web header keeps the product name, sign-in, and signup actions readable with no overlap or horizontal overflow at 360 x 800 and 375 x 812, plus the project’s desktop viewport.
- [ ] Capture before/after screenshots for the mobile header, support, terms/privacy, signed-out Account tab, and core signed-in account state.
- [ ] Production extension smoke verifies the exact backend host permission and one overlay host on the public-video test page.
- [ ] Record an account-deletion decision that matches the approved Terms/Privacy copy: either a self-serve deletion flow, or an explicitly documented support-mediated runbook for the beta. Either path must cancel the Stripe subscription before removing local data and must state what happens to ledger history. The agent must not choose this product/legal posture alone; stop and ask if the user has not decided.

### H. Processing version and cache invalidation

- [x] Bump the processing/cache version for the already-landed material tokenization prompt change so pre-change tracks cannot be reused as if they contain current segmentation behavior.
- [x] Add a test or single documented mechanism that makes future material transcription, normalization, tokenization prompt, schema, or model changes consider processing-version invalidation. It must cover all three version surfaces, which drift independently today: `SubtitleJobService::VERSION_PREFIX` (job/track reuse), the `VideoTranscriptCache` key (currently keyed by transcription model only, so a normalizer change silently reuses stale cached transcripts), and the learning-token enrichment cache-key version string in `LearningTokenEnrichmentService`.
- [x] Leave `TD-007`, `TD-008`, `TD-009`, `TD-010`, and `TD-014` open. The fixture expansion, native review, prompt/model A/B, token-cost capture, Audio Isolation A/B, and public-video quality matrix are deferred with the Track B plan; do not claim evidence that was not produced.

### I. Documentation and review readiness

- [x] Align `docs/RELIABILITY.md`, the production runbook, `.env.example`, `config/subtitles.php`, readiness checks, tests, and `docs/QUALITY_SCORE.md` on the actual Audio Isolation default (disabled). The rollout decision itself stays open in `TD-014`; the cleanup here is only that no doc claims a default or evidence that does not exist.
- [x] Update the project subtitle-pipeline skill so it accurately describes OpenAI’s current tokenization, translation, romanization, full-card, and on-demand roles.
- [x] Move `docs/exec-plans/active/00-phase-index.md` to completed or replace it with a current index, and repair stale SaaS roadmap links for completed billing and tiered-worker plans.
- [x] Keep learning plans 02–04, SaaS marketing Phase 08, public-launch Phase 09, and the Track B tokenization-quality work deferred; do not mark them complete as part of this branch.
- [x] Update security, reliability, observability, operations, product release-readiness, quality score, and technical debt only where behavior/evidence changed.
- [x] Final diff contains no unrelated generated output, build artifacts, provider payloads, credentials, or user-owned changes accidentally overwritten.
- [x] `scripts/agent/check.ps1`, `scripts/agent/verify-pr.ps1`, audits, focused tests, and browser smoke all pass with evidence recorded below.

## Implementation Sequence

Complete slices in this order. Do not start speculative quality architecture or production deployment while correctness gates remain open.

### Slice 0 — Baseline and decision lock

- [x] Read the relevant product, architecture, security, reliability, operations, review, guardrail, billing, and CJK plan docs.
- [x] Record branch name, HEAD, initial `git status --short`, tool/runtime versions, baseline checks, and audit counts in the Progress Log.
- [x] Confirm the current Stripe status model, billing-period data available on usage events, workspace path/run ownership, and extension build configuration.
- [x] Record decisions for entitlement-on-cache-hit, non-terminal Stripe statuses, legal-copy owner, release version, and authorized external test credentials.

### Slice 1 — Dependency remediation and audit gates

- [x] Update Composer lockfile and direct safe patch versions; inspect the full transitive diff.
- [x] Update contracts and extension packages without force/downgrade shortcuts.
- [x] Add release audit commands/tests and update dependency debt/docs.
- [x] Run the full repository check before proceeding so framework/tooling updates do not mix with later behavioral defects.

### Slice 2 — Billing and usage correctness

- [x] Implement checkout/customer concurrency and idempotency.
- [x] Remove/replace checkout actions for existing subscriptions in web/dashboard pricing surfaces.
- [x] Make every billing-state webhook subscription-specific and ordering-safe.
- [x] Settle job usage against its original reservation identity and period.
- [x] Add the billing and rollover regression matrix before running Stripe test mode.

### Slice 3 — Security, privacy, and lifecycle hardening

- [x] Gate new learning-token provider calls on entitlement and allowance.
- [x] Revoke durable extension/session access on password reset.
- [x] Make running-job deletion and provider-return persistence race-safe.
- [x] Whitelist/normalize Scribe chunk storage.
- [x] Add real connectivity readiness and the HTTP security-header baseline.
- [ ] Configure production transactional email, the trusted-proxy/session-cookie posture, and webhook-event retention.

### Slice 4 — Release surface cleanup

- [x] Remove deferred Save cue UI/shortcuts/state.
- [x] Correct signed-out account capability copy.
- [ ] Fix mobile header layout and test at both small viewports.
- [ ] Replace support/version placeholders and integrate user-approved legal copy.
- [ ] Record the account-deletion decision and align the legal copy and any runbook with it.
- [x] Tighten release packaging/readiness assertions.
- [ ] Capture browser screenshots and extension smoke evidence.

### Slice 5 — Processing version and cache invalidation

- [x] Bump processing version and lock versioning behavior with a test or durable rule covering all three version surfaces.
- [x] Update the Track B plan and TD entries to say the remaining evidence work is deferred, without claiming any new evidence.

### Slice 6 — Documentation, final validation, and handoff

- [x] Reconcile the active-plan index and stale roadmap links.
- [x] Update affected source-of-truth docs, quality score, and debt statuses.
- [x] Run all validation below.
- [x] Self-review the entire branch diff for correctness, simplicity, security, privacy, and unrelated changes.
- [x] Group commits by concern if the user requested commits; otherwise leave the review-ready working tree unchanged.
- [x] Fill Completion Notes with exact evidence and remaining external production gates.
- [ ] Move this plan to `docs/exec-plans/completed/` only when its completion contract is satisfied.

## Validation Plan

### Required repository commands

```powershell
.\scripts\agent\doctor.ps1
.\scripts\agent\check.ps1
.\scripts\agent\verify-pr.ps1
.\scripts\agent\doc-gardening.ps1

Push-Location .\app\backend
composer audit --locked --no-dev
php artisan test --compact
Pop-Location

Push-Location .\packages\contracts
npm audit
npm test
Pop-Location

Push-Location .\app\extension
npm audit
npm test
npm run compile
npm run build
Pop-Location
```

Use focused tests while implementing, then run the full commands above. If the package scripts differ, use the repository’s actual scripts and record the exact commands rather than silently skipping a check.

### Required focused behavior coverage

- Billing checkout/customer concurrency and idempotency.
- Subscription status UI/controller parity.
- Out-of-order, same-second, cross-subscription, failed, and replayed Stripe webhooks.
- Billing-period rollover for adjustment, completion, failure, and deletion.
- Entitled versus canceled/past-due learning-token cache hit and cache miss.
- Password-reset token revocation.
- Running-job delete/clear-all and in-flight provider callback races.
- Scribe payload allowlist and malformed response behavior.
- Postgres/Redis readiness success, timeout, auth failure, and sanitized output.
- Security headers in local/testing versus HTTPS production behavior.
- Mail readiness rejects `log`/`array` mailers and placeholder from-addresses in production; verification and password-reset mail are dispatched.
- Session-cookie and signed verification-URL behavior under the chosen proxy contract.
- Processed webhook-event retention prunes old handled rows and keeps errored rows.
- Release packaging rejects placeholder version, localhost, non-HTTPS API, and incorrect host permission.
- Deferred Save cue controls are absent from render, shortcuts, and handlers.
- Signed-out account capability text and mobile header regression.
- Processing-version invalidation.

### Manual and external evidence

- Desktop and 360/375px web screenshots for home, pricing, support, terms, privacy, register, and dashboard.
- Extension screenshots for signed out, signed in, Generate, Jobs, Account, progress, completed track, and stable failure states.
- Public-video overlay smoke with one `#tse-overlay-host`, no duplicate styles/hosts, and no console/page errors caused by the extension.
- Sanitized Stripe test-mode event IDs and resulting local subscription/ledger summaries.
- Production-ops evidence remains in `docs/exec-plans/active/saas-roadmap/06-production-hosting-and-ops.md`; do not claim staging/production proof in this plan unless it was actually run with authorization.

## Suggested Commit Groups

If the user asks the implementing agent to commit, prefer reviewable groups in this order:

1. `chore: update dependencies and release audit gates`
2. `fix: make subscription and usage settlement idempotent`
3. `fix: close entitlement and job cleanup races`
4. `fix: harden production readiness and response headers`
5. `fix: remove release placeholders and deferred controls`
6. `fix: bump processing version and lock cache invalidation`
7. `docs: reconcile release plans and readiness evidence`

Do not force this grouping when a test and its implementation would become separated or when the actual diff has a clearer logical boundary.

## Stop and Ask Conditions

Stop the affected slice and request user direction when any of these applies:

- Approved Terms/Privacy/operator copy or the real support address is unavailable.
- No production mail provider/credentials decision exists when the mail readiness work starts.
- The account-deletion posture (self-serve versus support-mediated) is undecided.
- Stripe test-mode or provider-backed work would require credentials or external mutations not already authorized.
- The selected dependency fix requires a major framework/toolchain version or a breaking product change.
- The simplest webhook-ordering fix requires a new Stripe API/read pattern with a material cost or availability tradeoff.
- Production security headers conflict with a hosting/reverse-proxy decision the user has not made.
- An existing user-owned modification overlaps the same lines and intent cannot be safely inferred.

## Defered

These items require operator values, credentials, a product decision, or an irreversible external action. They are intentionally not attempted in this first pass. Each item blocks only its related acceptance criteria; the local implementation and automated coverage continue without it.

### Values only the user can supply

- [ ] Real support email address for production `SUPPORT_EMAIL` (criterion G; Slice 4).
- [ ] Approved Terms of Service and Privacy Policy copy, including the operator/legal identity and effective date (criterion G; Slice 4). The agent must not draft-and-approve its own legal terms.
- [ ] Production domain and `APP_URL` (criteria F and G; Slices 3–4). Also needed to build the extension against the real HTTPS API base URL.
- [ ] Transactional mail provider choice and its production credentials, plus the from-address/domain (criterion F; Slice 3). Sender-domain DNS (SPF/DKIM) is user-owned external work; the agent can prepare config and readiness checks without the live credentials.
- [ ] Extension release version number to replace `0.0.0` — e.g. `1.0.0` or `0.9.0` for beta (criterion G; Slice 4).
- [ ] Chrome Web Store extension URL for `CHROME_EXTENSION_URL` once the listing exists (criterion F; may stay open until after store submission, which is outside this plan).

### Authorizations and credentials

- [ ] Stripe test-mode credentials (secret key, webhook secret, price IDs) and explicit authorization to run test-mode checkout/portal/cancel/failed-payment flows for the B evidence capture (Slice 2). Without them the B code and tests still land; only the sanitized live evidence stays open.
- [ ] Freshly issued production secrets (OpenAI, ElevenLabs, Stripe live keys) at deploy time, replacing keys that sat in local `.env` files (criterion F; external, user-gated).

### Decisions (agent proposes, user confirms)

- [ ] Account-deletion posture: self-serve flow now, or documented support-mediated runbook for the beta (criterion G; Slice 4).
- [ ] Non-terminal Stripe status rule: which local statuses block a new checkout and route to the portal (criterion B; Slice 0 decision lock). The agent will propose a table; the user confirms.
- [ ] Entitlement-on-cache-hit behavior for learning tokens. The recommended default is already in the Decision Log; silence means the default stands (criterion D).
- [ ] Reverse-proxy/hosting contract: where TLS terminates and whether the app or the proxy owns HSTS/security headers (criterion F; Slice 3). If the Phase 06 hosting choice already answers this, confirming that is enough.

## Decision Log

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-07-15 | Use one coordinating pre-production plan instead of adding a new product phase. | The review found correctness, release, and evidence gaps rather than a need for another feature. |
| 2026-07-15 | Keep sentence mining, speaking practice, AI coach, growth, and public-launch feature work out of scope. | The release should improve the quality and safety of existing behavior without feature creep. |
| 2026-07-15 | Finish the evidence-driven part of Track B before adding deterministic segmentation. | The existing harness can distinguish prompt/model/transcription problems at lower complexity and cost. |
| 2026-07-15 | Treat the branch as review-ready output, not deployment authorization. | The user requested implementation on a branch followed by review before production. |
| 2026-07-15 | Allow cached on-demand learning metadata for an eligible owned track but require entitlement before any new provider call, unless the user chooses otherwise. | This preserves already-paid output while preventing canceled/past-due accounts from creating new provider cost. |
| 2026-07-15 | User decision: drop all subtitle-quality evidence work from this plan — fixture expansion, native review, prompt/model A/B, token-cost capture, quality thresholds, Audio Isolation A/B, and the public-video quality matrix. This supersedes the earlier "finish the evidence-driven part of Track B" decision for this branch. | The user scoped this plan to cleaning up leftover defects and release surface before production. Track B and TD-007–TD-010/TD-014 remain the deferred home for the evidence work. Only the processing/cache-version defect stays in scope because stale cache reuse is a live defect, not research. |
| 2026-07-15 | First pass runs on `codex/pre-production-release-hardening`; all operator credentials, legal copy, release identifiers, and external proof are recorded under Defered instead of being invented. | The user requested a reviewable branch and a separate pass for supplied inputs. |
| 2026-07-15 | Cached learning-token metadata remains available to the owning user, but an un-cached enrichment must have an active or trialing subscription before its provider call. | It preserves already-paid track data while preventing inactive accounts from creating a new provider charge. |
| 2026-07-15 | Checkout defaults to the conservative status model: `active`, `trialing`, `past_due`, `unpaid`, and `incomplete` subscriptions use the billing portal; only `canceled` and `incomplete_expired` allow a new checkout. | This avoids duplicate subscriptions and does not discard an existing payment-recovery path. The user may revise this policy during the second pass. |
| 2026-07-16 | Persist one expiring checkout intent per user and plan, include its opaque ID in Stripe metadata, and rotate it after completion, expiry, or a different plan intent. | Stripe idempotency keys represent one logical operation and may be retained for at least 24 hours; a permanent user/plan key can reuse an obsolete Checkout Session. |
| 2026-07-16 | Refresh the authoritative Stripe subscription for equal-second, intended cross-subscription, checkout-completed, and invoice-failure events while holding the local user mutation lock. | Stripe does not guarantee webhook delivery order, and event timestamps have only second resolution. |
| 2026-07-16 | Use one `settlement:{job}:{run}` idempotency key under a subtitle-job row lock for debit or release. | Completion, deletion, and failure are competing terminal outcomes of the same reservation and must not append opposite terminal events. |
| 2026-07-16 | Validate artifact eligibility inside the same transaction that writes the artifact, and recheck long audio/provider stages under the subtitle-job row lock before dispatch. | A separate pre-write status check leaves a window where deletion or failure can win and a stale callback can recreate state afterward. |

## Progress Log

| Date | Update | Evidence |
| --- | --- | --- |
| 2026-07-15 | Plan created from the 2026-07-14 whole-project release review. | Baseline findings and acceptance criteria are recorded above; no application implementation was changed by plan creation. |
| 2026-07-15 | Second code review verified every primary finding against the code and added missed items: production transactional email, account-deletion posture, trusted-proxy/session-cookie contract, webhook-event retention, secret rotation before production, the fourth Save cue render site, the existing release-build script's partial checks, and the three independent version/cache surfaces. | Reviewed `BillingController`, `StripeClient`, `StripeWebhookService`, `UsageLedger`, `BillingEntitlementService`, `LearningTokenEnrichmentService`, `ResetUserPassword`, `WebSubtitleJobController`, `SubtitleGenerationPipeline`, `SubtitleJobArtifactStore`, `SubtitleJobFailureHandler`, `ElevenLabsScribeTranscriptionService`, `VideoTranscriptCache`, `CheckProductionReadiness`, `PruneExpiredSubtitleTracks`, routes, `bootstrap/app.php`, `.env.example`, `wxt.config.ts`, `api-config.ts`, release scripts, the Track B plan, and the tech-debt tracker. |
| 2026-07-15 | Recorded the review's confirmation anchors in Baseline Review Evidence and added the Required User Inputs section so external inputs are tracked per criterion instead of discovered mid-slice. | Documentation-only update; no application code changed. |
| 2026-07-15 | Rescoped per user direction to a cleanup-only pass: removed the quality-evidence criteria, slice, validation items, reviewer/spend inputs, and related stop conditions. Section H now covers only processing/cache-version invalidation. | Documentation-only update; no application code changed. |
| 2026-07-15 | Completed Slice 0 review on `codex/pre-production-release-hardening` at `19a789d5095ba0242709c088307db62a0f050456`. Read the product, architecture, frontend, security, reliability, observability, operations, review, golden-principles, quality, active-plan, Stripe/usage, and CJK context. Loaded Laravel security, patterns, specialist, best-practice, AI SDK, and subtitle-pipeline guidance; Laravel 13 documentation confirmed trusted-proxy and transaction/locking patterns. | Initial status preserved the two user-owned plan edits plus this untracked plan. PHP 8.4.21, Composer 2.9.8, Node 22.20.0, npm 10.9.3. `scripts/agent/check.ps1` passed: contracts validation/type generation, 294 Laravel tests (2,374 assertions), 146 extension tests, TypeScript compile, and WXT production build. `composer audit --locked --no-dev` reported 13 advisories; contracts `npm audit` reported 1 high and 1 moderate; extension `npm audit` reported 3 critical, 4 high, 2 moderate, and 1 low. |
| 2026-07-15 | Confirmed the present Stripe status fields, usage-event period/subscription columns, run-owned audio workspace, in-flight artifact path, release build configuration, and three independent cache/version surfaces. | Static review of the plan’s named source files and existing PHPUnit/Vitest coverage. |
| 2026-07-15 | Completed the local implementation pass: dependency remediation, billing and usage safeguards, entitlement/lifecycle fixes, provider normalization, readiness/security headers, release-surface cleanup, and unified version invalidation. | New and updated PHPUnit/Vitest coverage covers the local behavior matrix. User-provided values, credentials, legal copy, proxy contract, and external proof remain listed under `Defered`. |
| 2026-07-15 | Re-ran the full repository and release validation after the final dashboard compatibility fix. | `scripts/agent/doctor.ps1`, `scripts/agent/check.ps1`, `scripts/agent/verify-pr.ps1`, and `scripts/agent/doc-gardening.ps1` exited 0. Final suites: Laravel 308 tests / 2,467 assertions; extension 26 files / 148 tests; contracts validation and type generation passed. |
| 2026-07-15 | Audited the locked production dependency graph. | Composer runtime audit, contracts audit, and extension production-only audit (`--omit=dev --audit-level=high`) found 0 vulnerabilities. Full extension audit reports 8 development-only advisories through WXT/web-ext tooling; TD-002 records the exact chain and upstream major-version limitation. The release script rejects the present `0.0.0` version before it can build a package. |
| 2026-07-16 | Reopened locally checked criteria after the branch review found nine correctness/evidence gaps. Loaded the phased implementation workflow plus Laravel security, patterns, specialist, best-practice, AI SDK, subtitle-pipeline, Eloquent, queue, and testing guidance. | The remediation scope is limited to checkout/webhook identity, terminal usage settlement, provider/artifact races, Scribe timing validation, password-reset atomicity, readiness probes/release settings, mobile header behavior, deterministic contracts audit, and the missing regression evidence. Existing operator-owned external gates remain open. |
| 2026-07-16 | Implemented the nine review remediations and ran the focused regression matrix. | Pint passed. Billing, auth, subtitle lifecycle/artifact, readiness, and Scribe timing suites passed: 125 tests / 834 assertions. Composer runtime, contracts, and extension production-only audits each reported 0 vulnerabilities. Browser QA at 360 x 800, 375 x 812, and 1440 x 900 showed the product name and account actions with no overlap or horizontal overflow; the browser console and page-error checks were clean. |
| 2026-07-16 | Completed the full harness and final scope review. | `scripts/agent/doctor.ps1`, `scripts/agent/check.ps1`, `scripts/agent/verify-pr.ps1`, and `scripts/agent/doc-gardening.ps1` exited 0. Laravel: 321 tests / 2,553 assertions. Extension: 26 files / 148 tests plus TypeScript compile and production build. Contracts validation/type generation and `git diff --check` passed. The staged scope excludes the two pre-existing user-owned plan edits and `how_to_build - Copy.txt`. |
| 2026-07-16 | Fixed the real-video Scribe regression reported for `TdWxS2ZBTCU`. | Sanitized traces showed repeated `invalid_word_timing` failures after a successful Scribe response. ElevenLabs documents zero-duration `spacing` tokens, so the provider boundary now strips equal timestamps and lets the existing untimed-token merger preserve word text. Negative, reversed, non-numeric, and non-finite timings still fail closed. Focused transcription tests passed: 32 tests / 96 assertions. The full harness passed: Laravel 322 tests / 2,555 assertions, extension 148 tests plus compile/build, and contracts validation/type generation. |

## Completion Notes

- What changed: Applied the local hardening items in Slices 1–6, including package locks and audit gates; expiring Stripe checkout intents, authoritative webhook ordering, one terminal usage settlement, atomic password-reset token revocation, provider/artifact race closure, strict Scribe timing validation, separate readiness probes, release metadata checks, and mobile header behavior.
- Validation results: `doctor`, `check`, `verify-pr`, and `doc-gardening` passed. Laravel: 322 tests / 2,555 assertions. Extension: 26 test files / 148 tests, compile, and production build. Contracts validation/type generation passed. `git diff --check` passed.
- Dependency audit results: Composer runtime, contracts, and extension production-only audits report 0 vulnerabilities. The full extension development graph still has 8 WXT/web-ext tooling advisories, documented in TD-002; the shipped dependency graph is clean.
- Billing/Stripe evidence: Local tests cover expiring checkout intent reuse, concurrent customer identity, portal routing for all non-terminal statuses, authoritative same-second state, cross-subscription rejection, replay safety, and competing terminal settlements. Stripe test-mode events are deferred pending user credentials and authorization.
- Security/lifecycle evidence: Local tests cover entitlement before cache misses, atomic password/remember-token/token revocation rollback, workspace cleanup, provider rechecks, artifact write eligibility, strict Scribe data normalization, separate production readiness probes, security headers, and processed-webhook pruning.
- Browser and extension evidence: Automated extension test, compile, and build checks passed. Local screenshots at 360 x 800, 375 x 812, and 1440 x 900 verify the repaired marketing header without overlap or horizontal overflow. Public-video overlay smoke and Chrome Web Store host-permission evidence remain deferred until the production host/version are supplied.
- Processing-version and cache-invalidation evidence: `SubtitleProcessingVersion` centrally versions jobs/tracks, transcript cache keys, and learning-token cache keys; regression tests cover changed job and transcript cache identity.
- Simplicity/readability review: Reviewed the final diff for narrow scope, deterministic state transitions, no user-owned file overwrite, no artifacts, and no sensitive values. The active plan remains active because external gates are intentionally open.
- External production gates still open: All unchecked items under `Defered`: support address, approved legal copy, production domain and mail/provider values, extension version and Store URL, Stripe test-mode proof, production secret rotation, deletion posture, and proxy/TLS contract.
- Residual risk: The extension development toolchain has the documented WXT/web-ext advisory chain; browser/Stripe/production proof has not been fabricated.
- Follow-up debt: TD-002 and TD-014 remain open, together with the existing Track B evidence work and user-gated production-ops plan.
