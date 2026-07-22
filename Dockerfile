# Dockerfile
FROM php:8.2-apache

# 1. Instalar dependencias del sistema y extensiones de SQLite para PDO
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    sqlite3 \
    && docker-php-ext-install pdo pdo_sqlite

# 2. Habilitar mod_rewrite de Apache (útil para la navegación y headers)
RUN a2enmod rewrite

# 3. Copiar el código fuente del proyecto al directorio de Apache
COPY . /var/www/html/

# 4. Asignar los permisos adecuados a www-data (usuario de Apache)
# Esto garantiza que SQLite y la subida de PDFs puedan escribir sin errores de permisos
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html

EXPOSE 80