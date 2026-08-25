FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        unzip \
        git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_sqlite gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/php-dev.ini /usr/local/etc/php/conf.d/zz-myco-dev.ini
COPY docker-entrypoint.sh /usr/local/bin/myco-backend-entrypoint
RUN chmod +x /usr/local/bin/myco-backend-entrypoint

EXPOSE 8765
ENTRYPOINT ["myco-backend-entrypoint"]
CMD ["php", "-S", "0.0.0.0:8765", "-t", "public"]
