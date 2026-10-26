#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
project_dir="$PWD"
pg_bin="${PG_BINDIR:-/usr/lib/postgresql/17/bin}"
pg_data="$project_dir/.runtime/postgresql-data"
pg_port="${HMIS_NATIVE_PORT:-55432}"
action="${1:-start}"
[[ -x "$pg_bin/pg_ctl" ]] || { printf '%s\n' 'PostgreSQL 17 is required. Set PG_BINDIR if installed elsewhere.' >&2; exit 1; }
case "$action" in
    status) exec "$pg_bin/pg_ctl" -D "$pg_data" status ;;
    stop) exec "$pg_bin/pg_ctl" -D "$pg_data" -m fast stop ;;
    start) ;;
    *) printf '%s\n' 'Usage: bash scripts/local-postgres.sh start|stop|status' >&2; exit 1 ;;
esac
if [[ -f "$pg_data/PG_VERSION" ]]; then
    configured_port="$("$pg_bin/postgres" -D "$pg_data" -C port)"
    if [[ -n "${HMIS_NATIVE_PORT:-}" && "$pg_port" != "$configured_port" ]]; then
        printf 'Existing project cluster uses port %s; HMIS_NATIVE_PORT=%s does not match.\n' "$configured_port" "$pg_port" >&2
        exit 1
    fi
    pg_port="$configured_port"
fi
mkdir -p "$project_dir/.runtime"
if [[ ! -f "$pg_data/PG_VERSION" ]]; then
    password_file="$(mktemp "$project_dir/.runtime/pg-password.XXXXXX")"
    trap 'rm -f "$password_file"' EXIT
    printf '%s\n' 'local-development-only' > "$password_file"
    "$pg_bin/initdb" -D "$pg_data" -U hmis --auth-local=trust --auth-host=scram-sha-256 \
        --pwfile="$password_file" --locale=C.UTF-8 --encoding=UTF8
    # Only this project-owned local cluster is configured; system PostgreSQL is untouched.
    PG_CLUSTER_CONFIG="$pg_data/postgresql.conf" PG_CLUSTER_PORT="$pg_port" python3 - <<'PY'
import os
from pathlib import Path
port = int(os.environ['PG_CLUSTER_PORT'])
if not 1024 <= port <= 65535:
    raise SystemExit('HMIS_NATIVE_PORT must be between 1024 and 65535')
with Path(os.environ['PG_CLUSTER_CONFIG']).open('a') as f:
    # Use TCP for local administration as well: deeply nested clone paths can
    # exceed PostgreSQL's 107-byte Unix-domain socket filename limit.
    f.write(f"\nlisten_addresses = '127.0.0.1'\nport = {port}\nunix_socket_directories = ''\n")
    f.write("shared_buffers = '256MB'\nwork_mem = '32MB'\nmaintenance_work_mem = '256MB'\nmax_wal_size = '2GB'\ncheckpoint_timeout = '15min'\njit = off\n")
PY
fi
if ! "$pg_bin/pg_ctl" -D "$pg_data" status >/dev/null 2>&1; then
    "$pg_bin/pg_ctl" -D "$pg_data" -l "$project_dir/.runtime/postgresql-server.log" start
fi
for database_name in hmis hmis_test; do
    exists="$(PGPASSWORD=local-development-only "$pg_bin/psql" -h 127.0.0.1 -p "$pg_port" -U hmis -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='$database_name'")"
    if [[ "$exists" != 1 ]]; then
        PGPASSWORD=local-development-only "$pg_bin/createdb" -h 127.0.0.1 -p "$pg_port" -U hmis "$database_name"
    fi
done
printf 'Project PostgreSQL ready on 127.0.0.1:%s (hmis and hmis_test).\n' "$pg_port"
