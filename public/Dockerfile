FROM php:8.2-apache

# Install database drivers and enable Apache modules
RUN docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite headers

# Copy project files into the image
COPY . /var/www/html/

# Verify PHP version and check the actual deployed analytics file
RUN php -v \
    && php -l /var/www/html/app/Services/AnalyticsRange.php

# Create session and storage directories with appropriate permissions
RUN mkdir -p \
        /tmp/sessions \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html \
    && chmod 777 /tmp/sessions

# Configure PHP session storage
RUN echo 'session.save_path = "/tmp/sessions"' \
    > /usr/local/etc/php/conf.d/sessions.ini

# Configure Apache to serve the public directory
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    && sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Configure access to the public directory and application environment
RUN printf '%s\n' \
    '<Directory /var/www/html/public>' \
    '    Options FollowSymLinks' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    'SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on' \
    'SetEnv APP_URL https://babi-speciality-clinic-survey.onrender.com' \
    'SetEnv APP_ENV production' \
    'PassEnv DB_HOST DB_NAME DB_USER DB_PASS DB_PORT' \
    >> /etc/apache2/apache2.conf

# Listen on the port assigned by Render
CMD ["sh", "-c", "sed -i \"s/^Listen 80$/Listen ${PORT:-80}/\" /etc/apache2/ports.conf && sed -i \"s/:80>/:${PORT:-80}>/g\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
