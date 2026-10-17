FROM php:8.2-apache
RUN docker-php-ext-install pdo pdo_mysql mysqli
COPY . /var/www/html/
COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh
CMD ["entrypoint.sh"]
