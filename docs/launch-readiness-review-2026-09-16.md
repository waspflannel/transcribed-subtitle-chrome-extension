# Launch readiness review — September 16, 2026

Reviewed checkout: `c4c1cbb` (`Merge localization and launch SEO basics`). Scope: this repository only. Application behavior was not changed. No deployment, real payments, provider requests, email sends, or runtime database mutations were performed.

Implementation follow-up: the user authorized fixes on `codex/launch-readiness-fixes`. R1–R4 now have code changes and regression coverage; extension metadata is `0.1.0`, release ZIP validation is stricter and CI is configured. See the [fix plan](exec-plans/completed/2026-09-16-fix-launch-readiness-findings.md) for final validation evidence. The review below records the original findings; hosted acceptance and undecided hosting/domain/support values remain open.

## Verdict

**Ready to deploy to private staging. Not ready to open an unrestricted paid beta today.**

The core product is implemented and has substantial regression coverage. The remaining work is a focused release pass: fix checkout recovery and the reset/login race, complete real release configuration, and demonstrate the full journey on the intended host. The extension polling issue is a small worthwhile fix before testers arrive. The queue timeout risk needs a fix or evidence that the chosen beta capacity keeps it safely out of reach.

After that, start with a small invited group, for example 5–10 people, and observe support requests, generation failures, completion time and actual provider spend before expanding. Another major feature phase is not needed to learn from a beta.

## Findings

### R1 — P1: failed checkout creation can strand a customer indefinitely

Source: [StripeClient.php](../app/backend/app/Services/Billing/StripeClient.php), lines 209–227, 235 and 263–266; creation sends the saved expiry at line 81.

An unresolved checkout intent is reused whenever its session ID is null, even after its expiry. The original expiry is fixed at 31 minutes after the first attempt. If that attempt fails before reaching Stripe and the customer retries two minutes later, the new session request has only 29 minutes remaining. Stripe requires an expiry at least 30 minutes after creation. Subsequent retries keep the same invalid expiry. Changing plans and deleting the account also reject the unresolved intent, leaving the customer dependent on manual intervention.

Evidence: isolated in-memory database and fake HTTP, with the first request throwing a connection exception and the clock advanced two minutes. The retry kept the same expiry; observed `remaining_expiry_seconds: 1740`, `session_is_null: true`. No Stripe account was contacted. The current immediate-retry test in `BillingAndUsageTest.php` does not exercise elapsed time. The [Stripe Checkout documentation](https://docs.stripe.com/payments/checkout/how-checkout-works?payment-ui=embedded-page) supplies the external expiry requirement.

Fix before paid beta: provide safe reconciliation/recovery for unknown creation outcomes, then permit a fresh intent only after establishing that no payable session remains. Do not simply drop the existing intent and risk duplicate subscriptions. Add a delayed-retry regression and test the recovery against hosted Stripe test mode.

### R2 — P2: overlapping login can retain access after password reset

Source: [ExtensionAuthController.php](../app/backend/app/Http/Controllers/Api/ExtensionAuthController.php), lines 38–51; [ExtensionTokenIssuer.php](../app/backend/app/Services/Auth/ExtensionTokenIssuer.php), lines 15–23; [ResetUserPassword.php](../app/backend/app/Actions/Fortify/ResetUserPassword.php), lines 30–37.

Extension login validates a password using a loaded user, then issues a token without rechecking the password under a user-row lock. If reset completes between those steps, reset deletes existing tokens, but the stale login creates a new valid 30-day token afterward. This requires a narrow overlap with a login using the previously valid password; it is not a general password-reset bypass.

Evidence: a deterministic isolated HTTP probe completed reset at the injectable token-issuer boundary, then exercised the account route using the resulting token:

```json
{"login_http_status":200,"password_reset_completed_before_token_issuance":true,"new_token_http_account_status":200,"active_token_count":1}
```

Fix before inviting account holders: serialize password validation and token creation with reset on the user row, validating current credentials while the lock is held. Add a regression for this interleaving. Existing reset tests cover previously issued tokens.

### R3 — P2: completed tabs make later generations update more slowly

Source: [background.ts](../app/extension/entrypoints/background.ts), lines 667–669, 816–822 and 945–947. Compare recovered-job cleanup around line 2644.

Direct generation cleanup removes its in-flight marker and persisted operation but leaves the in-memory `tabOperations` entry. The minimum polling interval multiplies one second by the entire map size. Finish generations in nine tabs, keep those tabs open, then start a tenth: its status polling can be spaced about ten seconds apart although only one generation is active. Users see delayed previews and completion. Closing tabs or restarting the background worker clears the accumulated state.

Evidence: traced the normal completion, failure, recovered-monitor cleanup and polling paths. This audit did not reproduce the ten-tab scenario in a loaded browser. Existing tests cover single and simultaneous monitors, not completed-tab accumulation.

Fix: remove the completed in-memory operation with the same ownership guard used elsewhere, or count only actual active monitors. Add a regression for completed tabs followed by a new generation.

### R4 — P2: a queued finalizer can be mistaken for a dead worker

Source: [SubtitleGenerationPipeline.php](../app/backend/app/Services/Subtitles/SubtitleGenerationPipeline.php), lines 645–646; [SubtitleBatchDispatcher.php](../app/backend/app/Services/Subtitles/SubtitleBatchDispatcher.php), lines 83–87; [subtitles.php](../app/backend/config/subtitles.php), lines 200 and 220; [FailStalledSubtitleJobs.php](../app/backend/app/Console/Commands/FailStalledSubtitleJobs.php), lines 44–46 and 70–78.

The pipeline stamps `finalizing`/95% before enqueueing the finalizer on the generation queue. That queue also runs long audio/transcription work. The watchdog uses a default 300-second finalization allowance plus 120 seconds of slack and does not distinguish a waiting finalizer from one that started and stalled. A healthy backlog lasting beyond this allowance makes the next watchdog sweep fail the job even though analysis finished and a finalizer is waiting.

Evidence: code-path and default-configuration analysis. This audit did not create a production-load reproduction. The actual exposure depends on concurrency, worker allocation, queue wait and configured timeout overrides.

Before increasing beta traffic: prevent legitimate finalization queue wait from consuming an execution timeout, or ensure finalizers have bounded access to workers. Validate queued finalization under slow transcription and multi-user load; do not just disable stalled-job recovery.

## Release work still required

| Gate | Current evidence | Required before inviting users |
| --- | --- | --- |
| Production configuration | The local `ops:production-check --target=production` reports ten expected local/release failures. | Final HTTPS origin; production environment/debug/log settings; working transactional email and sender; real support address; extension URL/version/API permission. Run the command on the actual host. |
| Extension distribution | `app/extension/package.json` remains `0.0.0`. The current Chrome build targets `http://127.0.0.1:8001/*`. The release script rejects the placeholder version. | Pick a release version, build with the hosted HTTPS `/v1` origin using `scripts/ops/build-extension-release.ps1`, inspect the packaged manifest, and establish an install route for testers. |
| Hosted account and billing flow | Local tests pass, including real PostgreSQL locking tests with simulated Stripe responses. | Fresh signup, password-reset email delivery, extension sign-in, Stripe test checkout, webhook grants/replay, portal plan changes, cancellation and failed-payment behavior against the hosted application. |
| YouTube/browser acceptance | Prior evidence includes real local provider generation and UI rendering; the remediation browser fixture explicitly uses fake extension transport. | Load the actual release extension in a fresh Chrome profile. Exercise watch pages, Shorts, navigation, reload, worker restart, fullscreen, multiple tabs, offline/reconnect, saved tracks and corrections. |
| Hosted generation | Local provider runs establish that the pipeline has worked; they do not establish current hosted capability. | Real host acquisition with yt-dlp/FFmpeg and intended provider settings; short, medium and near-limit videos; mixed-language/non-Latin content; timing, cancellation/minutes, provider errors and concurrent accounts. |
| Recovery and operations | Deployment, worker, scheduler, backup and diagnostics tooling exists. No current-release hosted acceptance record was located in this review. | Show worker supervision and scheduler execution, an alert reaching the operator, a successful backup restore into scratch infrastructure, and a staging rollback. Confirm generated artifacts survive the chosen release-directory layout. |

The local production check failures are configuration work, not evidence that a deployed server is currently exposed. This audit did not inspect a deployed host. Local Postgres, queue Redis and concurrency Redis connectivity passed; credential-presence checks also passed, but presence does not prove validity or the intended test/live mode.

## Useful last touches

- Check the real install and support links from the public pages, registration flow and dashboard. A styled page is insufficient if a new visitor cannot install or recover their account.
- Give the nine interface-language catalogs native-speaker review. The quality record explicitly calls them AI drafts. English guide screenshots are an acceptable disclosed beta limitation.
- Keep beta support simple: the existing support email plus job/support IDs is enough initially. Complete the operator contact and owner review of the existing privacy/terms copy before publication.
- Compare actual provider bills and limits with your beta usage. Cost settings default to zero and the cost recorder estimates successful stages; it is not a complete accounting of failed/retried requests and interactive work. Do not infer profitability from its totals alone.
- Add one reliable loaded-extension smoke journey and a repeatable CI/release check. There is no checked-in `.github` workflow. The local harness is strong but must be run for the exact release commit.
- Keep vocabulary/Anki/extra platforms and broad redesigns out of the launch pass; none is required to test the current product promise.

## Validation performed

| Check | Result |
| --- | --- |
| `scripts/agent/check.ps1` | Passed: contracts, 691 backend tests / 38,562 assertions, 355 extension tests across 33 files, TypeScript, Chrome MV3 build. Nine service-dependent backend tests initially skipped as designed. |
| Dedicated integration run | All nine previously skipped tests passed / 92 assertions: PostgreSQL checkout and paid-cancellation concurrency; Redis provider/queue behavior. Used fresh project-labeled containers on random loopback ports with disposable data, then removed both containers. |
| Combined backend coverage | 700 tests passed across those two runs. Hosted provider/billing responses remain untested by these suites. |
| Composer locked runtime audit | Zero advisories and zero abandoned packages. |
| Extension full npm audit | Zero reported vulnerabilities, including development dependencies. |
| Contracts npm audit | Zero reported vulnerabilities. |
| Strict runtime profile | Passed: local PostgreSQL and Redis configuration. |
| Production readiness | Failed the ten local/release settings described above; other readiness checks passed locally. |
| Scheduler listing | Daily pruning and five-minute stalled-job recovery are registered. This does not prove a host scheduler runs them. |
| Documentation gardening | Four existing placeholder signals; no launch conclusion is based on those generic matches. |

Ignored local logs: `app/backend/storage/logs/launch-readiness-check.log` and `app/backend/storage/logs/launch-readiness-integration.log`. The findings above include the important sanitized results so the review does not depend on those local files.

## Evidence limits

This is a code and configuration review with local automated checks, not a completed hosting rollout, load test, native-language quality review or Chrome Store submission. Independent reviewers covered backend accounts/billing/security, the extension, and the generation pipeline. No claim of an exhaustive security audit is made.

Some older debt entries say provider/parallel proof has never been captured. That is stale: `docs/QUALITY_SCORE.md` records a real local mixed-language run completing 51 cues in about 23 seconds. The remaining requirement is representative, current-release, hosted and multi-user proof. The passed tests should not be mistaken for that proof, and the stale entries should not erase work already demonstrated.
