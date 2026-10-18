FROM php:8.2-apache

# Install database drivers & enable mod_rewrite + headers
RUN docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite headers

# Copy project files
COPY . /var/www/html/

# Create system & app-level session/storage paths and grant full write access to www-data
RUN mkdir -p /tmp/sessions /var/www/html/storage/framework/sessions /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html \
    && chmod -R 777 /tmp/sessions

# Override PHP session save path globally
RUN echo "session.save_path = \"/tmp/sessions\"" > /usr/local/etc/php/conf.d/sessions.ini

# Point Apache to public subfolder
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Configure directory permissions and environment variables
RUN echo "<Directory /var/www/html/public>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n\
SetEnvIf X-Forwarded-Proto \"^https$\" HTTPS=on\n\
SetEnv APP_URL https://babi-speciality-clinic-survey.onrender.com\n\
SetEnv APP_ENV production\n\
PassEnv DB_HOST DB_NAME DB_USER DB_PASS DB_PORT\n" >> /etc/apache2/apache2.conf

# Bind Apache to Render PORT
CMD sh -c "sed -i 's/80/'\"\$PORT\"'/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && apache2-foreground"
