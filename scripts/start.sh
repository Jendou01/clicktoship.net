#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose up -d --wait api worker scheduler web nginx
docker compose exec -T nginx nginx -s reload
python3 scripts/smoke.py
