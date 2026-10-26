#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
runtime="${1:-auto}"
if [[ "$runtime" == auto ]]; then
    if [[ -x "${PG_BINDIR:-/usr/lib/postgresql/17/bin}/initdb" ]] && php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);'; then runtime=native; else runtime=docker; fi
fi
case "$runtime" in
    native) bash scripts/setup-native.sh ;;
    docker) command -v docker >/dev/null; docker compose version >/dev/null; bash scripts/setup.sh ;;
    *) printf '%s\n' 'Usage: bash scripts/full-check.sh native|docker' >&2; exit 1 ;;
esac
bash scripts/verify.sh "$runtime"
bash scripts/benchmark.sh "$runtime"
printf '%s\n' 'Checks completed.'
