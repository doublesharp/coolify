# The workflow resolves UPSTREAM_IMAGE to an immutable digest before building.
# Dependencies and assets come from the matching official stable release.
ARG UPSTREAM_IMAGE
FROM ${UPSTREAM_IMAGE}

ARG FORK_REVISION
ARG UPSTREAM_IMAGE
LABEL org.opencontainers.image.source="https://github.com/doublesharp/coolify" \
      org.opencontainers.image.revision="${FORK_REVISION}" \
      com.doublepaas.upstream.image="${UPSTREAM_IMAGE}"

WORKDIR /var/www/html
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data database/migrations ./database/migrations
COPY --chown=www-data:www-data resources/views ./resources/views
COPY --chown=www-data:www-data openapi.yaml openapi.json ./
RUN composer dump-autoload --no-dev --no-interaction --no-scripts
