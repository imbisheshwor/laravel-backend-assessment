FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libonig-dev libxml2-dev unzip git \
    && docker-php-ext-install pdo_pgsql mbstring dom xml xmlreader xmlwriter pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
