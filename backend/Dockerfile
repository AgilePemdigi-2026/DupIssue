# Build compiled Vite/Tailwind assets with Node, without bringing Node into PHP runtime.
FROM node:22-alpine AS frontend
WORKDIR /build
COPY backend/package.json backend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY backend/resources ./resources
COPY backend/vite.config.js ./vite.config.js
COPY backend/public ./public
RUN npm run build

# Apache serves only /public; application code is never directly web-accessible.
FROM php:8.3-apache
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
WORKDIR /var/www/html
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev libonig-dev libpng-dev libjpeg62-turbo-dev \
    libfreetype6-dev libxml2-dev unzip git curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring intl zip gd bcmath pcntl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY deploy/apache/dupissue.conf /etc/apache2/conf-available/dupissue.conf
RUN a2enconf dupissue \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts
COPY backend/ ./
COPY --from=frontend /build/public/build ./public/build
RUN php artisan package:discover --ansi \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
COPY deploy/entrypoint.sh /usr/local/bin/dupissue-entrypoint
RUN chmod 755 /usr/local/bin/dupissue-entrypoint
EXPOSE 80
ENTRYPOINT ["dupissue-entrypoint"]
CMD ["apache2-foreground"]
