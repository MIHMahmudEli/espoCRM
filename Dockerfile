FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    nginx \
    supervisor \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    autoconf \
    g++ \
    make \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        gd \
        intl \
        mbstring \
        zip \
        opcache \
        bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/cache/apk/*

COPY php-custom.ini /usr/local/etc/php/conf.d/custom.ini

COPY nginx.conf /etc/nginx/http.d/default.conf

WORKDIR /var/www/html

COPY . .

RUN ln -s /var/www/html/client /var/www/html/public/client \
    && mkdir -p /var/www/html/data/cache \
    /var/www/html/data/logs \
    /var/www/html/data/upload \
    /var/www/html/data/tmp \
    /var/www/html/data/espocrm \
    /var/www/html/custom/Espo/Custom \
    && touch /var/www/html/data/.data \
    && chown -R nginx:nginx /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/data \
    && chmod -R 777 /var/www/html/custom \
    && mkdir -p /run/nginx /tmp \
    && chmod 1733 /tmp \
    && chown -R nginx:nginx /run/nginx \
    && chown -R nginx:nginx /var/lib/nginx

COPY supervisord.conf /etc/supervisord.conf

EXPOSE 8080

CMD ["supervisord", "-c", "/etc/supervisord.conf", "-n"]
