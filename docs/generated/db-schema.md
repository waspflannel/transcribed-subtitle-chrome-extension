# Generated Database Schema

Created: 2026-04-28
Last updated: 2026-05-18

The backend uses Postgres for runtime persistence. Redis-backed workers still rely on database tables for subtitle jobs, generated tracks, artifacts, batches, failed jobs, and trace events. SQLite is limited to PHPUnit's isolated in-memory test profile.

## Local Database

- Runtime engine: Postgres
- Test-only engine: SQLite `:memory:` through `app/backend/phpunit.xml`
- Commit policy: runtime database state lives outside Git in Postgres Docker volumes or deployment storage.

## Framework Tables

- `cache`
- `cache_locks`
- `jobs`
- `job_batches`
- `failed_jobs`

## Product Tables

The product migrations currently define:

- `subtitle_jobs`
  - `public_id`
  - `run_id` (required queued-work fence)
  - `youtube_video_id`
  - `youtube_url`
  - `video_duration_seconds`
  - `source_language`
  - `detected_source_language`
  - `target_language`
  - `processing_version`
  - `status`
  - `stage`
  - `progress_percent`
  - `error_code`
  - `error_message`
  - `install_id`
  - `request_ip`
  - `expires_at`
  - timestamps
  - unique compatibility key: `install_id`, `youtube_video_id`, `source_language`, `target_language`, `processing_version`
- `subtitle_job_artifacts`
  - `subtitle_job_id`
  - `artifact_type`
  - `batch_index`
  - `payload`
  - timestamps
  - unique artifact key: `subtitle_job_id`, `artifact_type`, `batch_index`
- `subtitle_job_events`
  - `subtitle_job_id`
  - `public_job_id`
  - `run_id`
  - `event`
  - `stage`
  - `status`
  - `queue_connection`
  - `queue`
  - `laravel_job_uuid`
  - `laravel_batch_id`
  - `batch_index`
  - `worker_pid`
  - `attempt`
  - `duration_ms`
  - `wait_ms`
  - `error_code`
  - `exception`
  - `context`
  - timestamps
- `subtitle_tracks`
  - `public_id`
  - `subtitle_job_id`
  - `youtube_video_id`
  - `source_language`
  - `detected_source_language`
  - `target_language`
  - `source_dialect`
  - `processing_version`
  - `generated_at`
  - `expires_at`
  - `cues`
  - `web_vtt`
  - timestamps
  - each track belongs to one unique `subtitle_job_id`
