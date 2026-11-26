#!/bin/sh
set -e
php /var/www/html/docker/bootstrap-db.php || true
exec apache2-foreground
