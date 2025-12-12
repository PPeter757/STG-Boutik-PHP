# IMAGE PHP + APACHE
FROM php:8.2-apache

# Installer extensions nécessaires
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql

# Installer Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copier les fichiers du projet
COPY . /var/www/html

# Se déplacer dans le projet
WORKDIR /var/www/html

# Installer automatiquement PHPMailer + vendor
RUN composer install --no-dev --optimize-autoloader

# Permissions Apache
RUN chown -R www-data:www-data /var/www/html

# Activer mod_rewrite
RUN a2enmod rewrite

# Config Render : Apache doit écouter sur 8080
RUN sed -i "s/80/8080/g" /etc/apache2/ports.conf && \
    sed -i "s/:80>/:8080>/" /etc/apache2/sites-available/000-default.conf

EXPOSE 8080

CMD ["apache2-foreground"]
