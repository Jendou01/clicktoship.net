# syntax=docker/dockerfile:1
FROM node:24-bookworm-slim
# Signed Debian packages provide Chromium where Playwright's CDN is restricted.
RUN apt-get update && apt-get install -y --no-install-recommends chromium ca-certificates \
    && rm -rf /var/lib/apt/lists/*
RUN --mount=type=secret,id=cloud_ca,required=false \
    if [ -s /run/secrets/cloud_ca ]; then cp /run/secrets/cloud_ca /usr/local/share/ca-certificates/cloud-proxy.crt; update-ca-certificates; fi
ENV PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/usr/bin/chromium
WORKDIR /workspace
USER node
CMD ["npm", "run", "test:e2e"]
