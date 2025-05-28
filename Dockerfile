FROM php:8.4-fpm

# Instalar dependencias del sistema y librerías requeridas
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    zip \
    unzip \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libsqlite3-dev \
    liblz4-dev \
    libonig-dev \
    sqlite3 \
    lz4 \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && docker-php-ext-install -j$(nproc) intl mbstring zip pdo_mysql dom pdo_sqlite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Instalar Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www

COPY . .

RUN rm -rf public/storage/

RUN composer install --no-dev --optimize-autoloader

RUN php artisan storage:link

RUN npm install

# Configurar límite de subida a 4GB
RUN echo "upload_max_filesize = 4096M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 4096M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_input_time = 600" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 600" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 4096M" >> /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 8000

CMD ["sh", "-c", "npm start & php-fpm"]