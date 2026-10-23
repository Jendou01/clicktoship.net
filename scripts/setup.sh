#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Existing isolated checkout; do not create a worktree.
# Python generates a local secret without printing it. Existing config is preserved.
python3 - <<'SETUP_PY'
from pathlib import Path
import secrets
root = Path('.env')
if not root.exists():
    root.write_text('DB_PASSWORD=' + secrets.token_hex(32) + '\nHTTP_PORT=8080\n')
    root.chmod(0o600)
api = Path('apps/api/.env')
if not api.exists():
    api.write_text(Path('apps/api/.env.example').read_text())
    api.chmod(0o600)
SETUP_PY
# Optional managed network CA is supplied by the caller via Docker build secret.
# Build once before setup (see README), or allow Compose to build normally.
BUILDX_CONFIG="${BUILDX_CONFIG:-/tmp/clicktoship-buildx}" docker compose build api
composer_archive_cache="${COMPOSER_ARCHIVE_CACHE:-/tmp/clicktoship-composer-cache}"
mkdir -p "$composer_archive_cache"
composer_archive_cache="$(cd "$composer_archive_cache" && pwd)"
python3 scripts/prime-composer-cache.py "$composer_archive_cache"
docker compose run --rm --no-deps -v "$composer_archive_cache:/composer-cache" \
  -e COMPOSER_CACHE_DIR=/composer-cache api composer install --no-interaction --prefer-dist
npm ci --cache "${npm_config_cache:-/tmp/clicktoship-npm-cache}"
if ! rg -q '^APP_KEY=base64:.+' apps/api/.env; then
    docker compose run --rm --no-deps api php artisan key:generate --no-interaction
fi
docker compose up -d --wait postgres redis
# Also works with a retained volume created before the test database was introduced.
docker compose exec -T postgres sh /docker-entrypoint-initdb.d/10-test-db.sh
docker compose run --rm api php artisan migrate --no-interaction
