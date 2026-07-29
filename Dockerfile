# syntax=docker/dockerfile:1
#
# Laravel web app image: PHP-FPM + nginx + supervisor in one self-contained container.
# The same image runs the web server (default CMD) and the queue worker (command override
# in docker-compose.yml). Composer deps and frontend assets are built in earlier stages.

# ---- Stage 1: Composer dependencies ----
# The frontend build needs vendor present too (app.css imports vendor/livewire/flux/dist/flux.css
# and Tailwind scans the Flux stubs), so resolve vendor first and share it with the next stages.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

# ---- Stage 2: build frontend assets (Vite → public/build) ----
FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY --from=vendor /app/vendor ./vendor
COPY vite.config.* ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- Stage 3: PHP runtime ----
FROM php:8.4-fpm-bookworm AS app

# System packages + PHP extensions (install-php-extensions handles the build deps for us).
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_pgsql pgsql intl zip bcmath pcntl opcache gd exif \
    && apt-get update \
    && apt-get install -y --no-install-recommends nginx supervisor \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# Application code + resolved vendor + built assets.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build

# Generate the optimized autoloader (scripts run at container start, see entrypoint).
RUN composer dump-autoload --optimize --no-dev --no-scripts

# Container configuration.
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
