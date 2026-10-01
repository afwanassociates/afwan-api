FROM php:8.5-fpm

# Tools Composer needs
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# MySQL driver (the other extensions Laravel needs are already in the image)
RUN docker-php-ext-install pdo_mysql

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# User for the application
RUN groupadd -g 1000 www && useradd -u 1000 -ms /bin/bash -g www www

WORKDIR /var/www

# Install dependencies first so Docker can cache this step
COPY --chown=www:www composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist

# Copy the application
COPY --chown=www:www . .
RUN composer dump-autoload --optimize \
    && chown -R www:www storage bootstrap/cache

USER www

EXPOSE 9000
CMD ["php-fpm"]