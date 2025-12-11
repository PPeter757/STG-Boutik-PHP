FROM php:8.2-apache

# Activer PDO MySQL si tu utilises MySQL local
RUN docker-php-ext-install pdo pdo_mysql

# Copier le code vers le serveur web Apache
COPY . /var/www/html/

# Exposer le port
EXPOSE 80

# Lancer Apache
CMD ["apache2-foreground"]
