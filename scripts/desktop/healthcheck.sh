#!/bin/sh
set -eu
status=$(supervisorctl -c /tmp/supervisord.conf status)
printf '%s\n' "$status" | awk 'NF { count++; if ($2 != "RUNNING") failed = 1 } END { exit failed || !count }'
curl --fail --silent --max-time 5 http://127.0.0.1:8001/ > /dev/null
php artisan ops:production-check --json --no-ansi > /dev/null
