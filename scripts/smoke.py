"""Check actual same-origin API and bilingual HTML. Never calls a carrier."""
import json
import os
import time
import urllib.request

base = os.environ.get("SMOKE_BASE_URL", "http://127.0.0.1:8080")
for attempt in range(30):
    try:
        with urllib.request.urlopen(base + "/api/v1/health/ready", timeout=5) as response:
            body = json.load(response)
            assert body["data"]["status"] == "ready"
            assert all(body["data"]["checks"].values())
            assert response.headers["X-Request-ID"] == body["meta"]["request_id"]
        for locale, direction, heading in [("en", "ltr", "One place for every delivery."), ("ar", "rtl", "كل شحناتك في مكان واحد.")]:
            with urllib.request.urlopen(base + "/" + locale, timeout=15) as response:
                html = response.read().decode()
                assert f'lang="{locale}"' in html and f'dir="{direction}"' in html
                assert heading in html
        print("PASS: PostgreSQL/Redis/schema readiness and English/Arabic pages through Nginx")
        break
    except (OSError, AssertionError, KeyError, TypeError):
        if attempt == 29:
            raise
        time.sleep(2)
