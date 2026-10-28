# syntax=docker/dockerfile:1
FROM node:24-bookworm-slim@sha256:55fb5f5a527ea2c5c4a95f963e48ef90aa38c7b346054fdc8ee9957a2b55d205 AS build
WORKDIR /workspace
ENV NEXT_TELEMETRY_DISABLED=1 CTS_DISPOSABLE_DEMO=true
COPY package.json package-lock.json ./
COPY apps/web/package.json apps/web/package.json
RUN --mount=type=secret,id=cloud_ca,required=false \
    if [ -s /run/secrets/cloud_ca ]; then NODE_EXTRA_CA_CERTS=/run/secrets/cloud_ca npm ci --ignore-scripts; else npm ci --ignore-scripts; fi
COPY apps/web/ apps/web/
RUN npm run build
FROM node:24-bookworm-slim@sha256:55fb5f5a527ea2c5c4a95f963e48ef90aa38c7b346054fdc8ee9957a2b55d205 AS runtime
ARG RELEASE_SHA
LABEL org.opencontainers.image.revision=$RELEASE_SHA org.clicktoship.profile=disposable-remote-demo
WORKDIR /app
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1 HOSTNAME=0.0.0.0 PORT=3000
COPY --from=build --chown=10001:10001 /workspace/apps/web/.next/standalone ./
COPY --from=build --chown=10001:10001 /workspace/apps/web/.next/static ./apps/web/.next/static
USER 10001:10001
EXPOSE 3000
CMD ["node", "apps/web/server.js"]
