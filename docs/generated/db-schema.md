# Generated Database Schema

Created: 2026-04-28

Phase 01 uses the stock Laravel SQLite baseline. Product tables are intentionally deferred until Phase 03.

## Local Database

- Engine: SQLite
- Local file: `app/backend/database/database.sqlite`
- Commit policy: the SQLite database file is local state and ignored by Git.

## Baseline Tables

The Laravel scaffold migrations currently define:

- `users`
- `password_reset_tokens`
- `sessions`
- `cache`
- `cache_locks`
- `jobs`
- `job_batches`
- `failed_jobs`

## Product Tables

Subtitle jobs, generated tracks, cues, and retention metadata will be added in Phase 03.
