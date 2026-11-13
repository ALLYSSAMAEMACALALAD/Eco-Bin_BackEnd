ARG PHP_VERSION=8.5
 
FROM php:${PHP_VERSION}-apache
 
# Install database and Cloudinary upload extensions
RUN apt-get update \
 && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
 && docker-php-ext-install pdo pdo_mysql curl \
 && rm -rf /var/lib/apt/lists/*
 
# Enable Apache mod_rewrite
RUN a2enmod rewrite
 
# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
 
# Copy app files
COPY . /var/www/html/

# Install PHP dependencies (vendor is gitignored, so build them during image build)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader --no-interaction
 
# Fix permissions
RUN chown -R www-data:www-data /var/www/html \
&& chmod -R 755 /var/www/html
 
# Point Apache document root to public/
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
 
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot ${APACHE_DOCUMENT_ROOT}|g' /etc/apache2/sites-available/000-default.conf \
&& sed -i 's|<Directory /var/www/html>|<Directory ${APACHE_DOCUMENT_ROOT}>|g' /etc/apache2/apache2.conf
 
EXPOSE 80

CMD ["sh", "-c", "php /var/www/html/public/index.php migrate && exec apache2-foreground"]

RUN echo "upload_max_filesize=8M" > /usr/local/etc/php/conf.d/uploads.ini \
 && echo "post_max_size=10M" >> /usr/local/etc/php/conf.d/uploads.ini
