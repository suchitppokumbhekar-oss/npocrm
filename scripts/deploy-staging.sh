#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/home/u748336076/domains/propertypoint.online/public_html/staging-npocrm"
EXPECTED_ENV="staging"

cd "$APP_DIR"

echo "== NPO CRM staging deployment =="

# Safety: never run this script against production.
if [ ! -f .env ] || ! grep -q "^APP_ENV=${EXPECTED_ENV}$" .env; then
    echo "ERROR: This is not the staging environment."
    exit 1
fi

CURRENT_BRANCH="$(git branch --show-current)"

echo "Branch: $CURRENT_BRANCH"
echo "Fetching latest code..."

git fetch origin
git reset --hard "origin/$CURRENT_BRANCH"

echo "Installing PHP dependencies..."
composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

echo "Preparing Laravel runtime..."
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chmod -R 775 storage bootstrap/cache

# Hostinger disables PHP symlink()/exec(), so create it through shell.
if [ ! -e public/storage ]; then
    ln -s "$APP_DIR/storage/app/public" public/storage
fi

echo "Refreshing Laravel caches..."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Running staging health check..."

HEALTH_URL="https://staging-npocrm.propertypoint.online/login"
HTTP_CODE="$(curl -L -sS -o /dev/null -w "%{http_code}" --max-time 20 "$HEALTH_URL")"

if [ "$HTTP_CODE" != "200" ]; then
    echo "ERROR: Health check failed. HTTP status: $HTTP_CODE"
    exit 1
fi

echo "Health check passed: HTTP $HTTP_CODE"

echo
echo "Deployment complete."
echo "Commit: $(git rev-parse --short HEAD)"
echo "URL: https://staging-npocrm.propertypoint.online"
