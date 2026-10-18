FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite

COPY . /var/www/html/

# Grant permissions, set DirectoryIndex, and pass environment variables to Apache
RUN echo "<Directory /var/www/html/>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
    DirectoryIndex phase7.php index.php index.html\n\
</Directory>\n\
PassEnv APP_URL APP_ENV DB_HOST DB_NAME DB_USER DB_PASS DB_PORT\n" >> /etc/apache2/apache2.conf

CMD sh -c "sed -i 's/80/'\"\$PORT\"'/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && apache2-foreground"
