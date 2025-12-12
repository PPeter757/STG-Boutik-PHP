FROM php:8.2-apache

# Installer les extensions nécessaires
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql

# Installer Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Activer mod_rewrite
RUN a2enmod rewrite

# Copier le projet avant installation vendor
COPY . /var/www/html/

# Installer les dépendances PHP (PHPMailer)
WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader

# Permissions
RUN chown -R www-data:www-data /var/www/html

# Render port configuration
ENV PORT=10000
RUN sed -i "s/80/${PORT}/g" /etc/apache2/sites-available/000-default.conf
RUN echo "Listen ${PORT}" >> /etc/apache2/ports.conf

CMD ["apache2-foreground"]
