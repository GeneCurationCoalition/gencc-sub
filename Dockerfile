# Install PHP dependencies (needed for Ziggy's JavaScript component)
# TODO: Consider switching to ziggy-js npm package to simplify this
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --ignore-platform-reqs --prefer-dist


# Build frontend assets
FROM node:22-alpine AS frontend

# Build argument for app name (can be overridden via --build-arg)
ARG VITE_APP_NAME="GenCC Submission Portal"
ENV VITE_APP_NAME=${VITE_APP_NAME}

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci

COPY --from=vendor /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
COPY resources ./resources
COPY vite.config.js postcss.config.js tailwind.config.js ./
RUN npm run build


# Production image
FROM php:8.1-fpm

# Build argument for application version (set via --build-arg APP_VERSION=...)
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libonig-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    nginx \
    python3 \
    python3-pip \
    && rm -rf /var/lib/apt/lists/*
# libzip-dev: required to build PHP zip extension
# libonig-dev: required to build PHP mbstring extension
# libpng/libjpeg/libfreetype: required for PHP gd extension
# zip/unzip: required by Composer for extracting packages
# nginx: web server to convert FastCGI to HTTP
# python3/python3-pip: required for ClinGen sync pipeline

# Install Python dependencies for ClinGen sync
RUN pip3 install --break-system-packages openpyxl mysql-connector-python

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        zip \
        pcntl \
        gd \
        opcache
# pdo_mysql: MySQL database driver
# mbstring: multibyte string handling, required by Laravel
# zip: required by Maatwebsite Excel for xlsx files
# pcntl: required for queue worker signal handling
# gd: required by phpspreadsheet (Maatwebsite Excel dependency)
# opcache: bytecode caching for production performance

# Copy Node.js from official image (recommended by docker-node best practices)
COPY --from=node:22-slim /usr/local/bin/node /usr/local/bin/
COPY --from=node:22-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && npm install -g pm2

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application files (root-owned for security - app code should be read-only)
COPY . .

# Copy built frontend assets
COPY --from=frontend /app/public/build ./public/build

# Install PHP dependencies (root-owned - vendor should be read-only)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set permissions for runtime directories (only these need www-data write access)
# - storage/: Laravel logs, cache, sessions, views, uploads
# - storage/releases/: GenCC release notes files (local fallback when GCS not configured)
# - bootstrap/cache/: Laravel compiled services and routes
# - data/: cache files for UpdateGenes, UpdateDiseases, CachesFileHeaders
# - data/clingen/comparison/: Python ClinGen sync pipeline outputs
RUN mkdir -p data/clingen/comparison storage/app/temp storage/app/public/exports storage/releases \
    && chown -R www-data:www-data storage bootstrap/cache data \
    && chmod -R 775 storage bootstrap/cache data

# PHP configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php-custom.ini $PHP_INI_DIR/conf.d/custom.ini

# PHP-FPM configuration (log to files instead of /proc/self/fd/2 for PM2 compatibility)
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-custom.conf

# Nginx configuration
COPY docker/nginx.conf /etc/nginx/sites-available/default

EXPOSE 80

# Entrypoint script for startup initialization
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
CMD ["pm2-runtime", "ecosystem.prod.config.cjs"]
