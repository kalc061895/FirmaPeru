# Dockerfile
FROM php:8.2-apache

# 1. Instalar dependencias del sistema (SQLite + p7zip para soporte 7z)
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    sqlite3 \
    p7zip-full \
    && docker-php-ext-install pdo pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

# 2. Habilitar mod_rewrite de Apache
RUN a2enmod rewrite

# 3. Copiar el código fuente del proyecto
COPY . /var/www/html/

# 4. Permisos para la BD SQLite y la subida/compresión de archivos
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html

EXPOSE 80