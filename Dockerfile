FROM php:8.2-apache

# Installer dépendances
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql

# Copier Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copier les fichiers du projet
COPY . /var/www/html

# Installer dépendances Composer dans /var/www/html
WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader

# Permissions
RUN chown -R www-data:www-data /var/www/html

# Activer mod_rewrite
RUN a2enmod rewrite

# Fix port Apache interne
RUN sed -i "s/80/8080/g" /etc/apache2/ports.conf && \
    sed -i "s/:80>/:8080>/" /etc/apache2/sites-available/000-default.conf

EXPOSE 8080

# Adapter Apache au port Render
CMD ["bash", "-c", "sed -i \"s/8080/${PORT}/g\" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
