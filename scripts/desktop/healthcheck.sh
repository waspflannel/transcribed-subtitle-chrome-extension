#!/bin/sh
set -eu
supervisorctl -c /tmp/supervisord.conf status | awk '$2 != "RUNNING" { failed = 1 } END { exit failed }'
curl --fail --silent --max-time 5 http://127.0.0.1:8001/ > /dev/null
php artisan ops:production-check --json --no-ansi > /dev/null
