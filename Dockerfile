FROM php:8.2-apache

# Install mysqli / pdo (for MySQL)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache mod_rewrite (if using routes)
RUN a2enmod rewrite

# Copy files to server
COPY . /var/www/html/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html
