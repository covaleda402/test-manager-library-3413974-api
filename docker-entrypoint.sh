#!/bin/sh
set -e

echo "Esperando conexión a PostgreSQL en ${DB_HOST}:${DB_PORT}..."
until php -r "
try {
    \$host = getenv('DB_HOST') ?: 'db';
    \$port = getenv('DB_PORT') ?: '5432';
    \$db   = getenv('DB_DATABASE') ?: 'library';
    \$user = getenv('DB_USERNAME') ?: 'library';
    \$pass = getenv('DB_PASSWORD') ?: 'library';
    new PDO(\"pgsql:host={\$host};port={\$port};dbname={\$db}\", \$user, \$pass);
    exit(0);
} catch (Exception \$e) {
    exit(1);
}
"; do
    echo "Base de datos aún no lista, reintentando en 2 segundos..."
    sleep 2
done

echo "Base de datos conectada correctamente."

# Asegurar existencia de archivo .env para compatibilidad con helpers de Laravel
[ -f .env ] || cp .env.example .env 2>/dev/null || touch .env

echo "Ejecutando migraciones de Laravel (Art. V & ADR-001)..."
php artisan migrate --force

echo "Provisionando administrador inicial desde variables de entorno (Art. IX & D-10)..."
php artisan db:seed --force

echo "Iniciando Manager Library API en http://0.0.0.0:8000..."
exec php artisan serve --host=0.0.0.0 --port=8000
