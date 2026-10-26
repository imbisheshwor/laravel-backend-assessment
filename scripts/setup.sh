#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ ! -f .env ]]; then cp .env.example .env; fi
docker compose build
docker compose up -d db
docker compose run --rm app composer install --no-interaction
docker compose run --rm app php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
if (!config("app.key")) { exit($kernel->call("key:generate", ["--force" => true])); }
'
docker compose run --rm app php artisan migrate --seed --force
docker compose up -d app worker scheduler
printf '%s\n' 'API ready at http://localhost:8000/api'
