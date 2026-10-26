#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p docs/benchmarks .runtime/logs
runtime="${1:-docker}"
case "$runtime" in native|docker) ;; *) printf '%s\n' 'Usage: bash scripts/benchmark.sh native|docker' >&2; exit 1 ;; esac
run_php() { if [[ "$runtime" == docker ]]; then docker compose run --rm app php "$@"; else php "$@"; fi; }
run_php artisan dataset:generate --patients=500000 --batch=2000 | tee .runtime/logs/dataset-generation.txt
run_php artisan dataset:verify --require-large
run_php artisan orders:explain --require-large
