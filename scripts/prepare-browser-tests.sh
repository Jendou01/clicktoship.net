#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Run setup.sh first. Never resets development data or seeds a normal runtime.
docker compose up -d --wait postgres redis mailpit
if ! docker compose exec -T postgres psql -U clicktoship -d postgres -Atc "SELECT 1 FROM pg_database WHERE datname='clicktoship_e2e'" | rg -q '^1$'; then
  docker compose exec -T postgres createdb -U clicktoship clicktoship_e2e
fi
compose_e2e=(docker compose -f docker-compose.yml -f infrastructure/docker/compose.e2e.yml)
# Explicit reset is restricted to the disposable browser database by both shell and Laravel.
if [[ "${1:-}" == "--rebuild" ]]; then
  "${compose_e2e[@]}" stop api worker scheduler
  "${compose_e2e[@]}" run --rm --no-deps api php artisan e2e:rebuild
elif [[ $# -gt 0 ]]; then
  echo 'Usage: prepare-browser-tests.sh [--rebuild]' >&2
  exit 2
fi
"${compose_e2e[@]}" run --rm --no-deps api php artisan migrate --no-interaction --force
"${compose_e2e[@]}" run --rm --no-deps api php artisan schema:verify
umask 077
fixture_file="${E2E_FIXTURE_FILE:-/tmp/clicktoship-e2e-credentials.json}"
"${compose_e2e[@]}" run --rm --no-deps -T api php tests/Fixtures/browser-accounts.php > "$fixture_file"
python3 - "$fixture_file" <<'PY'
import json,sys
from pathlib import Path
p=Path(sys.argv[1]);p.chmod(0o600)
accounts=json.loads(p.read_text())
assert set(accounts)=={'adminA','adminB','owner','member','financeOwner','adminC','adminD'}
PY
pricing_fixture="${E2E_PRICING_FIXTURE_FILE:-/tmp/clicktoship-pricing-e2e.json}"
"${compose_e2e[@]}" run --rm --no-deps -T api php tests/Fixtures/pricing-browser.php > "$pricing_fixture"
chmod 600 "$pricing_fixture"
"${compose_e2e[@]}" up -d --wait api worker scheduler web nginx
# Reload upstream DNS after replacing backend containers; no test triggers restarts.
"${compose_e2e[@]}" exec -T nginx nginx -s reload
python3 scripts/smoke.py
