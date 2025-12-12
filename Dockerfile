FROM php:8.2-apache

# Installer dépendances
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql

# Installer Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copier les fichiers du projet
COPY . /var/www/html

# Installer dépendances PHP (PHPMailer ...)
RUN composer install --no-dev --optimize-autoloader

# Donner permissions
RUN chown -R www-data:www-data /var/www/html

# Activer mod_rewrite
RUN a2enmod rewrite

# Configure Apache pour écouter sur le port Render ($PORT)
RUN echo "Listen ${PORT}" > /etc/apache2/ports.conf

RUN sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Démarrer Apache
CMD ["apache2-foreground"]
