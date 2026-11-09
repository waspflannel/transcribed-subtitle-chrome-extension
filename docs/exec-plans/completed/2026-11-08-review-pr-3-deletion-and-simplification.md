# Plan: Review PR 3 deletion and simplification


## Review simplification

The extension background has separate generation, panel, corrections and state modules. The fixed backend instance ID no longer partitions local tracks or operations. Install-session and operation ownership checks still protect asynchronous work.

Provider cost estimates and their database column are removed. Metrics report timing, queue waits and first-cue availability. Stage events and errors remain; redundant queue lifecycle trace rows are removed. Trace inserts use a nested transaction/savepoint so a best-effort failure cannot abort an outer Postgres transaction.

The migration drops inert users, billing_usage_events, stripe_webhook_events, personal_access_tokens and password_reset_tokens. No application code references these tables. Back up before migration: restoring these records requires a database backup. Metadata prefetch and its default-off flag are removed. Acquisition fetches fresh metadata. Legacy queued job reads remain for upgrade recovery; correction queued status is live.

Applied Laravel best practices and subtitle pipeline rules. Keep settings refresh before queue processing, but remove duplicate queue lifecycle traces. Preserve stage timing, failures and delivery.first_cue_available. Full harness and fake-only tests required before push.

Complete. Full local harness passed. No paid provider calls or real generations.
