FROM composer:2 AS composer_bin

FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libxml2-dev \
        libonig-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install pdo_mysql curl dom mbstring zip \
    && a2enmod headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer_bin /usr/bin/composer /usr/bin/composer

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf \
    && sed -ri "s!/var/www/!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

COPY composer.json ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

COPY . .

COPY docker/apache-security.conf /etc/apache2/conf-available/security-chat.conf

RUN a2enconf security-chat \
    && mkdir -p /var/www/storage/uploads \
    && chown -R www-data:www-data /var/www/storage

EXPOSE 80 8081
