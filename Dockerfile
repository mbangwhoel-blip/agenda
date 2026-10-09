FROM php:8.2-apache

# Install ekstensi PHP yang dibutuhkan: pdo_mysql, gd, zip, curl, mbstring, intl
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libzip-dev libicu-dev libmagickwand-dev \
    unzip git \
    tesseract-ocr poppler-utils \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo_mysql zip intl opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Aktifkan mod_rewrite (untuk .htaccess) + headers
RUN a2enmod rewrite headers

# Set document root
ENV APACHE_DOCUMENT_ROOT /var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Copy aplikasi
COPY . /var/www/html/

# Set permission
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads /var/www/html/storage

# Konfigurasi PHP produksi
RUN echo "expose_php = Off\n\
display_errors = Off\n\
log_errors = On\n\
error_log = /dev/stderr\n\
upload_max_filesize = 20M\n\
post_max_size = 25M\n\
memory_limit = 256M\n\
session.cookie_httponly = 1\n\
session.cookie_samesite = Lax\n\
session.use_strict_mode = 1" > /usr/local/etc/php/conf.d/99-production.ini

EXPOSE 80
