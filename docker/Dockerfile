FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mysqli gd intl zip mbstring \
    && a2enmod rewrite \
    && apt-get purge -y --auto-remove libicu-dev libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libonig-dev \
    && rm -rf /var/lib/apt/lists/*
