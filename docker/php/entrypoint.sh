#!/bin/sh
set -e

# The bind mount in docker-compose.yml replaces the image's /var/www/html, so the
# autoloader that was generated at build time has to be regenerated for the
# mounted source tree. Cheap, and it keeps `docker compose up` a one-liner.
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --no-progress
fi

echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT} ..."
until php -r '
    $dsn = sprintf("mysql:host=%s;port=%s", getenv("DB_HOST"), getenv("DB_PORT"));
    try { new PDO($dsn, getenv("DB_USER"), getenv("DB_PASSWORD")); exit(0); }
    catch (Throwable $e) { exit(1); }
'; do
    sleep 1
done
echo "MySQL is up."

php bin/console migrate
if [ -n "${DB_TEST_NAME}" ]; then
    DB_NAME="${DB_TEST_NAME}" php bin/console migrate
fi

exec "$@"
