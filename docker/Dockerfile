FROM php:8.3-fpm-alpine

# System packages needed to build the PHP extensions below, plus Node/npm
# for the Vite/Inertia/React frontend build (Section 23 — no separate
# frontend container, the app image runs both artisan and npm)
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    oniguruma-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    icu-dev \
    nodejs \
    npm \
    $PHPIZE_DEPS

# PHP extensions required by the stack (Laravel 12, Spatie Media Library,
# MySQL driver, queue/session driver) — see PROJECT-SYSTEM-DOCUMENTATION.html
# Section 23 "Technical Stack & Packages"
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-enable opcache

# Composer (pinned to the official 2.x image, not a curl-to-shell install)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 777 storage bootstrap/cache 2>/dev/null || true

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
