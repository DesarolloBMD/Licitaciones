# Imagen base con PHP 8.2
FROM php:8.2-cli

# Instalar dependencias necesarias (PostgreSQL, ZIP, GD, etc.)
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libzip-dev libpng-dev \
 && docker-php-ext-install pdo pdo_pgsql zip gd \
 && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configuración PHP global (mayor tamaño de carga y ejecución)
RUN echo "upload_max_filesize=1024M\npost_max_size=1024M\nmemory_limit=2G\nmax_execution_time=600\nmax_input_time=600\n" > /usr/local/etc/php/conf.d/uploads.ini

# Directorio de trabajo
WORKDIR /app

# Copiar archivos del proyecto
COPY . /app

# Instalar dependencias PHP (PhpSpreadsheet u otras)
RUN composer install --no-dev --optimize-autoloader || true

# Exponer el puerto usado por Render
ENV PORT=10000

# Comando para iniciar el servidor PHP embebido
CMD php -S 0.0.0.0:${PORT} -t /app
