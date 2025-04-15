FROM registry.gitlab.com/jeffam/php-fpm-caddy:8.4.5-build-001

# Install composer
COPY --from=docker.io/composer:2.8 /usr/bin/composer /usr/local/bin/composer

# Copy files
COPY --chown=www:www . .

# Run composer
RUN composer install --no-dev --no-cache
