FROM php:8.2-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libicu-dev \
    && docker-php-ext-install bcmath \
    && rm -rf /var/lib/apt/lists/*

# Enable common Apache modules
RUN a2enmod headers rewrite

# Install Composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer manifests first for layer-cache efficiency
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Copy application code
COPY . .

# Create a writable runtime directory for generated files (pins.json, urls.json, geo_map.json)
RUN mkdir -p /var/www/html/runtime \
    && chown -R www-data:www-data /var/www/html/runtime

# Environment variables — override at runtime via ConfigMap / Secret
ENV PINS_FILE=/var/www/html/runtime/pins.json
ENV GOOGLE_APPLICATION_CREDENTIALS=/var/run/secrets/google/credentials.json

# Minimal health endpoint for Kubernetes probes
RUN echo "ok" > /var/www/html/healthz

EXPOSE 80
