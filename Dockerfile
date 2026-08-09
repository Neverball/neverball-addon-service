FROM dunglas/frankenphp:1.2-php8.3

# Install PHP extensions for ZIP processing and string handling
RUN install-php-extensions zip mbstring intl curl

# Configure PHP upload limits
RUN echo "upload_max_filesize = 25M" > $PHP_INI_DIR/conf.d/uploads.ini \
 && echo "post_max_size = 25M" >> $PHP_INI_DIR/conf.d/uploads.ini

# Copy composer binary from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP dependencies
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy Caddyfile
COPY Caddyfile /etc/caddy/Caddyfile

# Copy application source
COPY public/ public/
COPY src/ src/
COPY dist* public/dist/

# Create storage directories with correct ownership
RUN mkdir -p storage/uploads storage/tokens storage/hashes storage/logs \
    && chown -R www-data:www-data storage \
    && chmod -R 755 storage

ENTRYPOINT ["frankenphp"]
CMD ["run", "--config", "/etc/caddy/Caddyfile"]

