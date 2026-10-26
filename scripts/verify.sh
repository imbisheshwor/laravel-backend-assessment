#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p .runtime/logs
runtime="${1:-docker}"
case "$runtime" in native|docker) ;; *) printf '%s\n' 'Usage: bash scripts/verify.sh native|docker' >&2; exit 1 ;; esac
run_php() { if [[ "$runtime" == docker ]]; then docker compose run --rm app php "$@"; else php "$@"; fi; }
run_composer() { if [[ "$runtime" == docker ]]; then docker compose run --rm app composer "$@"; else bash scripts/composer.sh "$@"; fi; }
run_composer validate --strict --no-check-publish
run_composer lint > .runtime/logs/php-lint.txt
run_composer analyse 2>&1 | tee .runtime/logs/static-analysis.txt
run_php artisan config:clear
# Expected exceptions in rollback tests are asserted as 500 responses, not test failures.
if [[ "$runtime" == docker ]]; then
    docker compose run --rm -e LOG_LEVEL=critical app php artisan test --no-ansi 2>&1 | tee .runtime/logs/tests.txt
else
    LOG_LEVEL=critical php artisan test --no-ansi 2>&1 | tee .runtime/logs/tests.txt
fi
run_php artisan route:list --path=api --no-ansi > .runtime/logs/routes.txt
