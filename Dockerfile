FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libpq-dev \
    git \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json ./

RUN composer config policy.advisories.block false \
    && composer install --no-dev --no-scripts --no-interaction --prefer-dist

COPY . .

RUN composer dump-autoload --optimize

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs /var/covers \
    && chmod -R 777 storage bootstrap/cache /var/covers \
    && chmod +x docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
