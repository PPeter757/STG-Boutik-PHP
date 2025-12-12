FROM php:8.2-apache

# Installer dépendances système
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql

# Installer Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Définir le dossier de travail
WORKDIR /var/www/html

# Copier ton projet dans le container
COPY . .

# Installer dépendances (PHPMailer sera installé ici)
RUN composer install --no-dev --optimize-autoloader

# Permissions
RUN chown -R www-data:www-data /var/www/html

# Activer mod_rewrite
RUN a2enmod rewrite

# Mettre Apache sur 8080 (Render changera ensuite vers $PORT)
RUN sed -i "s/80/8080/g" /etc/apache2/ports.conf && \
    sed -i "s/:80>/:8080>/" /etc/apache2/sites-available/000-default.conf

EXPOSE 8080

# Adapter Apache au port Render au runtime
CMD ["bash", "-c", "sed -i \"s/8080/${PORT}/g\" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
