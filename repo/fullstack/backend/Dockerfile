FROM php:8.2-cli

RUN apt-get update && apt-get install -y libzip-dev unzip libicu-dev && \
    docker-php-ext-install pdo pdo_mysql zip intl bcmath && \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first for caching
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --optimize-autoloader --no-scripts || \
    composer install --no-interaction --no-scripts

# Copy full source
COPY . .

# Ensure bin/console is executable
RUN chmod +x bin/console 2>/dev/null || true

# Create storage directories
RUN mkdir -p /var/www/storage/pdfs /var/www/storage/exports /var/www/storage/backups \
    /var/www/storage/logs /var/www/storage/terminal_assets /var/www/storage/terminal_chunks

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
