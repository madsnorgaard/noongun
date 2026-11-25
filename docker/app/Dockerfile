# syntax=docker/dockerfile:1

ARG PHP_IMAGE=dunglas/frankenphp:1.14-php8.5-alpine
ARG COMPOSER_IMAGE=composer:2.11

FROM ${COMPOSER_IMAGE} AS composer

FROM ${PHP_IMAGE} AS base

ARG UID=1000
ARG GID=1000

RUN install-php-extensions pdo_pgsql pgsql redis pcntl intl zip bcmath opcache \
    && apk add --no-cache nodejs npm \
    && (getent group "${GID}" > /dev/null || addgroup -g "${GID}" noongun) \
    && adduser -D -u "${UID}" -G "$(getent group "${GID}" | cut -d: -f1)" -h /home/noongun noongun \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R "${UID}:${GID}" /data /config /app

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/app/php.ini $PHP_INI_DIR/conf.d/zz-noongun.ini
COPY --chmod=755 docker/app/entrypoint.sh /usr/local/bin/noongun-entrypoint

ENV SERVER_NAME=:8080 \
    COMPOSER_HOME=/home/noongun/.composer

WORKDIR /app
EXPOSE 8080

ENTRYPOINT ["noongun-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

FROM base AS dev

USER noongun

FROM base AS build

ARG UID=1000
ARG GID=1000

USER noongun
COPY --chown=${UID}:${GID} composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY --chown=${UID}:${GID} package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --chown=${UID}:${GID} . .
RUN composer dump-autoload --optimize --no-dev \
    && npm run build \
    && rm -rf node_modules

FROM base AS prod

ARG UID=1000
ARG GID=1000

ENV APP_ENV=production \
    APP_DEBUG=false

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

USER noongun
COPY --from=build --chown=${UID}:${GID} /app /app
