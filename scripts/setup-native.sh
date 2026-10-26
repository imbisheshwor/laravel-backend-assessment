#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
pg_port="${HMIS_NATIVE_PORT:-55432}"
if [[ -z "${HMIS_NATIVE_PORT:-}" && -f .runtime/postgresql-data/PG_VERSION ]]; then
    pg_port="$("${PG_BINDIR:-/usr/lib/postgresql/17/bin}/postgres" -D "$PWD/.runtime/postgresql-data" -C port)"
fi
if [[ ! -f .env ]]; then
    cp .env.example .env
    HMIS_NATIVE_PORT="$pg_port" python3 - <<'PY'
import os
from pathlib import Path
p = Path('.env')
p.write_text(p.read_text().replace('DB_PORT=5432', 'DB_PORT='+str(int(os.environ['HMIS_NATIVE_PORT']))))
PY
fi
bash scripts/composer.sh install --no-interaction --prefer-dist
HMIS_NATIVE_PORT="$pg_port" bash scripts/local-postgres.sh start
HMIS_NATIVE_PORT="$pg_port" php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$db = config("database.connections.pgsql");
if ($db["host"] !== "127.0.0.1" || (int) $db["port"] !== (int) getenv("HMIS_NATIVE_PORT") || $db["database"] !== "hmis" || !empty($db["url"])) {
    fwrite(STDERR, "Native setup expects .env DB_HOST=127.0.0.1, DB_PORT=".getenv("HMIS_NATIVE_PORT").", DB_DATABASE=hmis and no DB_URL.\n"); exit(1);
}
if (!config("app.key")) { exit($kernel->call("key:generate", ["--force" => true])); }
'
php artisan migrate --seed --force --no-ansi
printf '%s\n' 'Native setup complete. Start the API with: php artisan serve --host=127.0.0.1 --port=8000'
