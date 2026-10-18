FROM php:8.2-apache

# Install database drivers & enable mod_rewrite
RUN docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite

# Copy project files
COPY . /var/www/html/

# Grant permissions to /var/www/html
RUN echo "<Directory /var/www/html/>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>" >> /etc/apache2/apache2.conf

# Bind Apache to Render PORT
CMD sh -c "sed -i 's/80/'\"\$PORT\"'/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && apache2-foreground"
