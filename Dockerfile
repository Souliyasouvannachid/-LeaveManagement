FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install mbstring pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache/attachments.conf /etc/apache2/conf-available/attachments.conf
RUN a2enconf attachments

RUN printf "upload_max_filesize = 5M\npost_max_size = 6M\n" > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html
