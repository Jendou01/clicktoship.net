#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose up -d --wait api worker scheduler web nginx
python3 scripts/smoke.py
