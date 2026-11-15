FROM --platform=linux/amd64 alpine:3.22

RUN apk add --no-cache \
      apache2-utils \
      bash \
      coreutils \
      curl \
      jq \
      php83 \
      sed \
      util-linux \
    && ln -sf /usr/bin/php83 /usr/local/bin/php

ARG VERSION=dev
ENV SETUP_VERSION=${VERSION}

COPY setup/entrypoint.sh /usr/local/bin/unraid-mobile-setup
COPY compose-api/api.php \
     compose-api/plugin-worker.php \
     compose-api/update-status.php \
     compose-api/update-file-index.sh \
     compose-api/register-index-cron.sh \
     compose-api/install-compose-api.sh \
     /opt/unraid-mobile/compose-api/

RUN chmod 0755 /usr/local/bin/unraid-mobile-setup \
    && chmod 0755 /opt/unraid-mobile/compose-api/*.sh

ENTRYPOINT ["/usr/local/bin/unraid-mobile-setup"]
