#!/bin/sh
set -u
stopping=0
child=''
phase=sleep
stop() {
    stopping=1
    if [ "$phase" = sleep ] && [ -n "$child" ]; then kill "$child" 2>/dev/null || true; fi
}
trap stop TERM INT QUIT
while [ "$stopping" = 0 ]; do
    phase=sleep
    sleep "$((60 - $(date +%s) % 60))" &
    child=$!
    wait "$child" || true
    [ "$stopping" = 0 ] || break
    phase=run
    php artisan schedule:run &
    child=$!
    wait "$child" || true
    # A stop signal interrupts wait; let the active scheduled command finish.
    if kill -0 "$child" 2>/dev/null; then wait "$child" || true; fi
done
