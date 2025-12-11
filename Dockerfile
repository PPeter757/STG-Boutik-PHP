FROM php:8.2-apache

# Installer les extensions nécessaires
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql

# Activer mod_rewrite si tu utilises des routes
RUN a2enmod rewrite

# Copier les fichiers du projet
COPY . /var/www/html

# Donner les permissions à Apache
RUN chown -R www-data:www-data /var/www/html

# Exposer le port utilisé par Render (⚠ Render n’utilise pas 80 mais $PORT)
EXPOSE 8080

# Apache doit écouter sur le port Render : $PORT
ENV PORT=8080
RUN sed -i "s/80/\${PORT}/g" /etc/apache2/sites-available/000-default.conf

# Lancer Apache
CMD ["apache2-foreground"]
