FROM nginx:1.28-bookworm@sha256:552e7481ca93ffccd046aa658dbbed22caefbc09c66fa7cd247cbb90b8a5c609
ARG RELEASE_SHA
LABEL org.opencontainers.image.revision=$RELEASE_SHA
COPY --chmod=0444 infrastructure/deployment/nginx.conf /etc/nginx/nginx.conf
COPY --chmod=0444 infrastructure/deployment/fastcgi.conf /etc/nginx/cts-fastcgi.conf
USER 10001:10001
ENTRYPOINT ["nginx", "-g", "daemon off;"]
