FROM php:8.3-cli-bookworm

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libsqlite3-dev libzip-dev \
    && docker-php-ext-install pdo pdo_sqlite \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --optimize-autoloader --no-interaction --no-progress

RUN mkdir -p database storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && touch database/database.sqlite \
    && chown -R www-data:www-data /var/www/html

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
