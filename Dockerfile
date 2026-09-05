FROM php:8.2-fpm-alpine

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

COPY nginx.conf /etc/nginx/http.d/default.conf

WORKDIR /var/www/html

COPY . .

RUN chown -R nginx:nginx /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/data \
    && chmod -R 777 /var/www/html/custom \
    && mkdir -p /run/nginx \
    && chown -R nginx:nginx /run/nginx \
    && chown -R nginx:nginx /var/lib/nginx

COPY supervisord.conf /etc/supervisord.conf

EXPOSE 8080

CMD ["supervisord", "-c", "/etc/supervisord.conf", "-n"]
