FROM php:8.2-apache

# Installer les extensions PostgreSQL + outils nécessaires
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql

# Activer mod_rewrite
RUN a2enmod rewrite

# Copier le projet
COPY . /var/www/html/

# Permissions correctes
RUN chown -R www-data:www-data /var/www/html

# Render utilise une variable PORT, pas 80 ni 8080
ENV PORT=10000

# Forcer Apache à écouter sur $PORT → indispensable Render
RUN sed -i "s/80/${PORT}/g" /etc/apache2/sites-available/000-default.conf
RUN echo "Listen ${PORT}" >> /etc/apache2/ports.conf

# Démarrer Apache
CMD ["apache2-foreground"]
