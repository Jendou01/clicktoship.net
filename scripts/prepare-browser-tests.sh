#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Run setup.sh first. Never resets development data or seeds a normal runtime.
docker compose up -d --wait postgres redis mailpit
if ! docker compose exec -T postgres psql -U clicktoship -d postgres -Atc "SELECT 1 FROM pg_database WHERE datname='clicktoship_e2e'" | rg -q '^1$'; then
  docker compose exec -T postgres createdb -U clicktoship clicktoship_e2e
fi
compose_e2e=(docker compose -f docker-compose.yml -f infrastructure/docker/compose.e2e.yml)
"${compose_e2e[@]}" run --rm --no-deps api php artisan migrate --no-interaction --force
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
"${compose_e2e[@]}" up -d --wait api worker scheduler web nginx
# Reload upstream DNS after replacing backend containers; no test triggers restarts.
"${compose_e2e[@]}" exec -T nginx nginx -s reload
python3 scripts/smoke.py
