FROM php:8.3-fpm

# pdo_mysql: the only DBAL used (no ORM / query builder).
# pcntl:     used by the concurrency test to fork real parallel clients.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip default-mysql-client \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

# Install dependencies as their own layer so code changes do not bust the cache.
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-progress --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
