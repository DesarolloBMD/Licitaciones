# Imagen base con PHP 8.2
FROM php:8.2-cli

# Instalar dependencias necesarias (zip, gd, postgres)
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    && docker-php-ext-install pdo pdo_pgsql zip gd \
    && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Definir directorio de trabajo
WORKDIR /app

# Copiar archivos del proyecto
COPY . /app

# Instalar dependencias PHP (PhpSpreadsheet y otras)
RUN composer install --no-dev --optimize-autoloader

# Exponer el puerto usado por Render
ENV PORT=10000

# Comando para iniciar el servidor PHP
CMD php -S 0.0.0.0:${PORT} -t /app
