#!/usr/bin/env bash
set -euo pipefail

# Start charon-systemd in the background; the VICI plugin is enabled by
# default and creates /var/run/charon.vici once the daemon is ready.
/usr/lib/ipsec/starter &
CHARON_PID=$!

# Wait up to ~5s for the VICI socket to appear before handing control over.
for _ in $(seq 1 50); do
    if [ -S /var/run/charon.vici ]; then
        break
    fi
    sleep 0.1
done

if [ ! -S /var/run/charon.vici ]; then
    echo "charon-systemd failed to create /var/run/charon.vici" >&2
    kill "${CHARON_PID}" 2>/dev/null || true
    exit 1
fi

# Terminate charon when this shell exits so `docker compose run --rm`
# leaves no stragglers behind.
trap 'kill "${CHARON_PID}" 2>/dev/null || true' EXIT

exec "$@"
