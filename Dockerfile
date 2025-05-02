FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git \
    zip
RUN pecl install xdebug && docker-php-ext-enable xdebug
ENV XDEBUG_MODE=coverage
ENV PHP_MEMORY_LIMIT=1G

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app