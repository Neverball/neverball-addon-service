# Stage 1: Build Vite frontend assets
FROM node:20-alpine AS frontend-builder
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci
COPY vite.config.js ./
COPY resources/ resources/
COPY src/ src/
COPY public/ public/
RUN npm run build

# Stage 2: Final FrankenPHP runtime image
FROM dunglas/frankenphp:1.2-php8.3

# Install PHP extensions and composer
RUN install-php-extensions zip mbstring intl curl @composer

# Configure PHP upload limits and error handling
RUN echo "upload_max_filesize = 25M" > $PHP_INI_DIR/conf.d/custom.ini \
 && echo "post_max_size = 25M" >> $PHP_INI_DIR/conf.d/custom.ini \
 && echo "display_errors = Off" >> $PHP_INI_DIR/conf.d/custom.ini \
 && echo "display_startup_errors = Off" >> $PHP_INI_DIR/conf.d/custom.ini \
 && echo "log_errors = On" >> $PHP_INI_DIR/conf.d/custom.ini

WORKDIR /app

# Install PHP dependencies
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy Caddyfile
COPY Caddyfile /etc/caddy/Caddyfile

# Copy application source
COPY public/ public/
COPY src/ src/
COPY notify ./

# Copy compiled Vite assets from frontend-builder stage
COPY --from=frontend-builder /app/public/dist public/dist

# Create storage directories with correct ownership
RUN mkdir -p storage/uploads storage/tokens storage/hashes storage/logs \
    && chown -R www-data:www-data storage \
    && chmod -R 755 storage

ENTRYPOINT ["frankenphp"]
CMD ["run", "--config", "/etc/caddy/Caddyfile"]

