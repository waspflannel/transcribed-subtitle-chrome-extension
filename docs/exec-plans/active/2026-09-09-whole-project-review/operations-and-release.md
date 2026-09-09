# Operations and release

Created: 2026-09-09
Last updated: 2026-09-09

Use the [index](00-index.md) for shared execution rules, ownership and the old-number map. Historical package numbers below identify audit evidence; they are sections of these consolidated documents, not separate work plans. This consolidation does not authorize new implementation or experiments.

This document owns deployment, operational security, recovery, dependency checks and release evidence. Account behavior belongs to [Accounts and billing](accounts-and-billing.md); generation architecture and retention design belong to [the pipeline](pipeline-speed-quality-and-reliability.md).

## Operations security and release

Former audit section 12.

Status: planned — not started
Owner: unassigned
Type: Tracked release gaps plus product/document reconciliation

### Goal

Make release claims match tested behavior and actual deployed capability, with truthful account/security contracts and demonstrated recovery.

### Source and evidence

[Review reconciliation/drift](../../../whole-project-review-2026-09-09.md:371) distinguishes resolved issues from outstanding acceptance. [Validation gaps](../../../whole-project-review-2026-09-09.md:465) include Stripe configuration, Linux concurrency, browser lifecycle, binary capability, alerts, restore and rollback. Current docs still describe WAV/isolation, sequential Scribe, loud fallbacks and verified-email-only access that differ from implementation.

Coverage: Opportunity 13; E08 (operations), E06/E07 acceptance handoff; report section 7 drift and remaining security/operations notes; TD-003/007/009/010/011/012/015 and release audit of TD-002/obsolete TD-004.

### Dependencies

Documentation inventory and staging setup can start independently. Final readiness depends on relevant acceptance from 01–10 and any adopted architecture from 11.

### Scope

Real deployment/readiness evidence, health and supervision contracts, current dependency audits, retention verification, provider-data retention and documentation alignment. Coordinate existing active plans instead of creating competing release ownership.

Out of scope: Changing unrelated product scope, editing historical reports to erase failures, declaring all active plans complete or inventing production success from local fakes.

### Relevant files and context

- [docs/operations/production-hosting-and-ops.md](../../../operations/production-hosting-and-ops.md)
- [scripts/ops](../../../../scripts/ops)
- [scripts/agent/check.ps1](../../../../scripts/agent/check.ps1)
- [app/backend/app/Console/Commands](../../../../app/backend/app/Console/Commands)
- [app/backend/app/Http/Controllers/Api/ExtensionAuthController.php](../../../../app/backend/app/Http/Controllers/Api/ExtensionAuthController.php)
- [app/backend/app/Services/Billing/BillingEntitlementService.php](../../../../app/backend/app/Services/Billing/BillingEntitlementService.php)
- [docs/QUALITY_SCORE.md](../../../QUALITY_SCORE.md)
- [docs/exec-plans/tech-debt-tracker.md](../../tech-debt-tracker.md)

### Implementation or investigation steps

- [ ] Map each outdated claim to its implementation/package owner: FLAC/chunks/no isolation, fallback behavior, on-demand UI versus backend Full capability, obsolete OpenAI STT debt and manual acceptance status.
- [ ] Make deploy health-check expectations explicit and verify actual deployed yt-dlp/FFmpeg/JS-runtime/mail capabilities, worker/scheduler processes and configuration consistency. Add scheduler overlap protection only if topology/runtime requires it.
- [ ] Run authorized staging deployment, worker restart, scratch backup restore, rollback and alert smoke. Verify the [caching retention and data efficiency](pipeline-speed-quality-and-reliability.md#caching-retention-and-data-efficiency) section retention policy and actual provider retention separately; keep secrets/sensitive source data out of artifacts.
- [ ] Coordinate E08 Stripe proof with 01 and loaded-extension G1/G5/R7/browser evidence with 10. Recheck locked dependency advisories in release tooling without unrelated forced upgrades.
- [ ] Update current architecture/security/reliability/quality/debt docs to verified behavior and preserve historical reports. Wire meaningful checks into the real release/CI path; archive existing plans only when their stated gates pass.

### Acceptance criteria

- [ ] Documentation accurately distinguishes implemented behavior, selected future work and unverified external acceptance.
- [ ] Staging evidence proves worker/scheduler operation, deployed binary/mail capability, restore, rollback and alert delivery beyond /up.
- [ ] Dependency/retention/provider configuration results are dated and tied to the release checkout.
- [ ] Billing/browser/performance acceptance has explicit pass/fail/limitation evidence; no paid-beta readiness claim relies solely on unit tests.

### Validation and rollout

Run the repository check plus relevant production-readiness/release commands after reading their behavior. Use staging/scratch resources and authorized Stripe test mode; no production mutations or real charges follow from this planning request. If external access is unavailable, complete local/docs work and leave the exact external gate open.

Account verification policy and its acceptance criteria now belong to [Accounts and billing](accounts-and-billing.md#account-verification-and-security-contract). This section owns deployment and documentation verification of that agreed contract.
