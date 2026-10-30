FROM php:8.3-cli-bookworm

# Plugins (symfony/runtime generates vendor/autoload_runtime.php) must be allowed to run as root during the build
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# gd, zip, xml*, simplexml, mbstring ... are what PhpSpreadsheet needs to read xlsx; pdo_mysql is for Doctrine
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# dependencies first: this layer is rebuilt only when composer.json changes
COPY composer.json ./
RUN composer install --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize \
    && sed -i 's/\r$//' docker/entrypoint.sh \
    && chmod +x docker/entrypoint.sh \
    && useradd --create-home --uid 1000 app \
    && mkdir -p var \
    && chown -R app:app var

USER app

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["php", "bin/console", "app:telegram:poll"]
