#!/bin/sh
set -eu
healthcheck="$(cd "$(dirname "$0")" && pwd)/healthcheck.sh"
for mode in failure empty stopped running; do
    result=0
    sh -c '
        supervisorctl() {
            case "$mode" in
                failure) return 1 ;;
                empty) return 0 ;;
                stopped) printf "%s\n" "backend RUNNING" "worker STOPPED" ;;
                running) printf "%s\n" "backend RUNNING" "worker RUNNING" ;;
            esac
        }
        curl() { return 0; }
        php() { return 0; }
        mode=$2
        . "$1"
    ' healthcheck-test "$healthcheck" "$mode" || result=$?
    if [ "$mode" = running ]; then
        [ "$result" -eq 0 ] || { echo 'Healthy workers were rejected.' >&2; exit 1; }
    else
        [ "$result" -ne 0 ] || { echo "Unhealthy Supervisor result accepted: $mode" >&2; exit 1; }
    fi
done
echo 'Supervisor healthcheck failure and readiness checks passed.'
