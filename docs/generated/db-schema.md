# Generated Database Schema

Created: 2026-04-28
Last updated: 2026-05-11

The backend uses the stock Laravel SQLite baseline plus product tables for subtitle jobs and generated tracks.

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

The product migrations currently define:

- `subtitle_jobs`
  - `public_id`
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
