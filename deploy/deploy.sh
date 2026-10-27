#!/usr/bin/env bash
# Update the server to the latest code and restart. Safe to run again. Usage (on the server): bash /opt/call-joe/deploy/deploy.sh [branch]
set -euo pipefail

cd "$(dirname "$0")/.."
BRANCH="${1:-main}"
COMPOSE="docker compose -f compose.prod.yaml --env-file .env"

[ -f .env ] || { echo "Missing .env: copy .env.production.example to .env and fill it in." >&2; exit 1; }

git fetch --quiet origin
git checkout --quiet "$BRANCH"
git pull --quiet --ff-only origin "$BRANCH"
echo "Deploying version $(cat VERSION) ($(git rev-parse --short HEAD))"

# A new app key on the very first run (the key protects sessions and encrypted data: never change it afterwards).
if ! grep -qE '^APP_KEY=base64:' .env; then
    KEY="base64:$(head -c 32 /dev/urandom | base64)"
    if grep -q '^APP_KEY=' .env; then sed -i "s|^APP_KEY=.*|APP_KEY=$KEY|" .env; else echo "APP_KEY=$KEY" >> .env; fi
    echo "Made an app key."
fi

$COMPOSE build
$COMPOSE up -d --remove-orphans

# Wait for the web container, then clear and rebuild the caches.
for _ in $(seq 1 30); do
    if $COMPOSE exec -T app php artisan about --only=environment >/dev/null 2>&1; then break; fi
    sleep 2
done
$COMPOSE exec -T app php artisan optimize
$COMPOSE exec -T queue php artisan queue:restart || true

$COMPOSE ps
echo "Done. Check: curl -I \$(grep ^APP_URL= .env | cut -d= -f2)"
