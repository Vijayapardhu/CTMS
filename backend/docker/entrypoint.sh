#!/bin/bash
set -e

echo "Starting CTMS Backend..."

# Run migrations
echo "Running database migrations..."
php artisan migrate --force

# Cache configuration and routes
echo "Caching configuration and routes..."
php artisan config:cache
php artisan route:cache

echo "Backend startup complete."
