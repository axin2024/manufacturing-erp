FROM php:8.4-apache

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && docker-php-ext-install pdo_mysql

WORKDIR /srv/erp
COPY deploy/apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/erp.ini
COPY bin/ bin/
COPY database/ database/
COPY public/ public/
COPY src/ src/
COPY templates/ templates/
COPY docs/dependencies.md docs/
COPY LICENSE NOTICE ./
