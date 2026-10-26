#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ -f .tools/composer.phar ]]; then
    exec php .tools/composer.phar "$@"
fi
# Fall back to the PHAR if system Composer's intl dependency is unavailable.
if command -v composer >/dev/null && php -r 'exit(class_exists("Normalizer") ? 0 : 1);'; then
    exec composer "$@"
fi
mkdir -p .tools
curl -fsSL https://getcomposer.org/installer -o .tools/composer-setup.php
curl -fsSL https://composer.github.io/installer.sig -o .tools/composer-setup.sha384
php -r 'if (!hash_equals(trim(file_get_contents(".tools/composer-setup.sha384")), hash_file("sha384", ".tools/composer-setup.php"))) { fwrite(STDERR, "Composer installer checksum mismatch\n"); exit(1); }'
php .tools/composer-setup.php --version=2.10.3 --install-dir=.tools --filename=composer.phar
exec php .tools/composer.phar "$@"
