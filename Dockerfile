# syntax=docker/dockerfile:1

FROM php:8.3-cli-alpine

# Runtime + build dependencies for the extensions the app relies on.
RUN apk add --no-cache \
        git \
        unzip \
        netcat-openbsd \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        curl-dev \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        mbstring \
        pdo_mysql \
        pcntl \
        curl \
        zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'date.timezone=UTC\n' > "$PHP_INI_DIR/conf.d/00-timezone.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies first so source changes do not bust the layer cache.
COPY composer.json composer.lock ./
RUN composer install \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts

COPY . .

# `.dockerignore` excludes writable/database/*.db and .env, so a container
# starts from an empty SQLite database. Create the schema in the image itself;
# otherwise every /api/* call dies with "no such table" (500) in production.
RUN mkdir -p writable/cache writable/database writable/debugbar writable/logs \
             writable/session writable/uploads \
    && php spark migrate --all \
    && chown -R www-data:www-data writable

# Structured logs go to stderr so `docker logs` shows one JSON object per line.
ENV CONFIGMON_LOG_TO_STDERR=true \
    COMPOSER_ALLOW_SUPERUSER=1

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "spark", "serve", "--host", "0.0.0.0", "--port", "8080"]
