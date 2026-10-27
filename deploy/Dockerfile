# The production image for the website, the queue worker, the scheduler and Reverb (same code, different command).

# 1. Install PHP packages without the dev tools (the front end build reads Flux's CSS from vendor/).
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# 2. Build the front end.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
COPY --from=vendor /app/vendor ./vendor
# The browser reads these at build time (resources/js/echo.js): where Reverb is reached from outside.
ARG VITE_APP_NAME="Call Joe"
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https
RUN npm run build

# 3. The app.
FROM serversideup/php:8.4-fpm-nginx-alpine
USER root
RUN install-php-extensions gd imagick intl pcntl pdo_pgsql pgsql redis sockets bcmath
USER www-data
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
ENV PHP_OPCACHE_ENABLE=1 \
    SSL_MODE=off
