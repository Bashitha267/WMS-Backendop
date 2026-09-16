#!/bin/sh
set -e

# Render injects PORT dynamically (defaults to 10000)
PORT=${PORT:-10000}
sed -i "s/RENDER_PORT_PLACEHOLDER/$PORT/g" /etc/nginx/nginx.conf

# Ensure framework storage directories exist with proper permissions
mkdir -p /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/logs
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Run Laravel production optimizations (drastically reduces CPU & bootstrap memory)
echo "Caching Laravel configuration, routes, and views..."
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true
php artisan event:cache || true

# Run database migrations if configured
if [ "$RUN_MIGRATIONS" = "true" ] || [ "$RUN_MIGRATIONS" = "1" ]; then
    echo "Running database migrations..."
    php artisan migrate --force || echo "Migration encountered an issue or database is still connecting."
fi

# Start PHP-FPM as daemon
echo "Starting PHP-FPM..."
php-fpm -D

# Start Nginx in foreground
echo "Starting Nginx on port $PORT..."
exec nginx -g "daemon off;"
