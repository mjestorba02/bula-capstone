FROM php:8.2-apache

# zip: required for Excel (.xlsx) report exports
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo pdo_mysql mysqli zip

RUN a2enmod rewrite

# Hosting platforms tell the container which port to listen on through $PORT.
# Apache listens on it (default 80 when PORT is not set) and honours .htaccess files.
# setup.php and docker/ are run by the entrypoint only, never over the web.
ENV PORT=80
RUN sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n<Directory /var/www/html/docker>\n    Require all denied\n</Directory>\n<Files "setup.php">\n    Require all denied\n</Files>\nServerName localhost\n' > /etc/apache2/conf-available/app.conf \
    && a2enconf app

COPY . /var/www/html/
RUN mkdir -p /var/www/html/backend/uploads/payment_proofs \
    && chown -R www-data:www-data /var/www/html \
    && sed -i 's/\r$//' /var/www/html/docker/entrypoint.sh \
    && chmod +x /var/www/html/docker/entrypoint.sh

EXPOSE 80
CMD ["/var/www/html/docker/entrypoint.sh"]
