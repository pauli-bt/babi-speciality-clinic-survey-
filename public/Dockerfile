FROM php:8.2-apache
RUN docker-php-ext-install pdo pdo_mysql mysqli
COPY . /var/www/html/
CMD sh -c "sed -i 's/80/'\"\$PORT\"'/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && apache2-foreground"
