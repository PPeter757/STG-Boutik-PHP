FROM php:8.2-apache

# Installer les extensions nécessaires
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql

# Copier les fichiers du projet
COPY . /var/www/html

# Donner les permissions à Apache
RUN chown -R www-data:www-data /var/www/html

# Activer mod_rewrite
RUN a2enmod rewrite

# Remplacer le port 80 par 8080
RUN sed -i "s/Listen 80/Listen 8080/" /etc/apache2/ports.conf && \
    sed -i "s/:80>/:8080>/" /etc/apache2/sites-available/000-default.conf

EXPOSE 8080

# Render remplace automatiquement $PORT au runtime
CMD ["apache2-foreground"]
