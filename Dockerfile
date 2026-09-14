FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl libzip-dev libpng-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring zip bcmath pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# در dev کد با volume mount می‌شود؛ composer روی image برای build اولیه
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist || true

COPY . .

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# تعداد workerهای php-fpm (concurrency)
RUN sed -i 's/pm.max_children = .*/pm.max_children = 20/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/pm.start_servers = .*/pm.start_servers = 4/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/pm.min_spare_servers = .*/pm.min_spare_servers = 2/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/pm.max_spare_servers = .*/pm.max_spare_servers = 8/' /usr/local/etc/php-fpm.d/www.conf

EXPOSE 9000
CMD ["php-fpm"]