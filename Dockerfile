FROM php:8.2-apache

# zip: required for Excel (.xlsx) report exports
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo pdo_mysql mysqli zip

RUN a2enmod rewrite

# Hosting platforms tell the container which port to listen on through $PORT.
# Apache listens on it (default 80 when PORT is not set) and honours .htaccess files.
ENV PORT=80
RUN sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\nServerName localhost\n' > /etc/apache2/conf-available/app.conf \
    && a2enconf app

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
