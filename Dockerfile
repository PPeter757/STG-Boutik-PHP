FROM php:8.2-apache

# Installer dépendances
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql

# Installer Composer dans l'image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copier les fichiers du projet
COPY . /var/www/html

# Installer dépendances PHP (dont PHPMailer)
RUN composer install --no-dev --optimize-autoloader

# Donner permissions
RUN chown -R www-data:www-data /var/www/html

# Activer mod_rewrite
RUN a2enmod rewrite

# NE PAS utiliser $PORT ici ! Render injecte le port au runtime.
# Modifier la config Apache au lancement (entrypoint)
RUN sed -i "s/80/8080/g" /etc/apache2/ports.conf && \
    sed -i "s/:80>/:8080>/" /etc/apache2/sites-available/000-default.conf

# Apache écoutera ensuite sur $PORT grâce au CMD ci-dessous.

EXPOSE 8080

# Utiliser le port Render au runtime
CMD ["bash", "-c", "sed -i \"s/8080/${PORT}/g\" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
