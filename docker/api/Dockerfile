# PokéPrices API image: php-fpm with the extensions the API and its gates need.
FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM mlocati/php-extension-installer:2@sha256:1afade3e29cfc97362cf5885e5ac333bf2faab1146cb28ebbb59b17e68f87e88 AS extinstaller

FROM php:8.5-fpm-alpine@sha256:fa01fb1645cd0fc566a5f146b099adace33b906571f972f71f2182a7c12d1cd7 AS base
COPY --from=extinstaller /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
RUN install-php-extensions pdo_pgsql redis pcov intl zip pcntl \
 && apk add --no-cache fcgi git unzip \
 && printf 'pcov.enabled=0\npcov.directory=/workspace/api/app\n' > /usr/local/etc/php/conf.d/zz-pcov.ini
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/api/zz-pokeprices.conf /usr/local/etc/php-fpm.d/zz-pokeprices.conf
WORKDIR /workspace/api

FROM base AS dev
# Dev runs as container root, which rootless Docker maps to the host user,
# so files written into the bind mount stay editable on the host.
CMD ["php-fpm", "-F", "-R"]

FROM base AS vendor
COPY api/ /workspace/api/
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader \
 && mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views

FROM base AS runtime
COPY --from=vendor --chown=www-data:www-data /workspace/api /workspace/api
USER www-data
CMD ["php-fpm", "-F"]
