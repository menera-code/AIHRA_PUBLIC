FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    git \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions - ADDED bcmath
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mysqli gd zip bcmath

# Enable Apache rewrite module
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html

# ✅ DEBUGGING: Check files before composer install
RUN echo "=== DEBUG: Checking files ===" && \
    ls -la && \
    echo "=== DEBUG: Checking composer.json ===" && \
    cat composer.json && \
    echo "=== DEBUG: Checking if vendor exists ===" && \
    ls -la vendor/ 2>/dev/null || echo "No vendor directory"

# Install Composer dependencies WITH VERBOSE OUTPUT
RUN composer update --no-dev --no-interaction --optimize-autoloader

# ✅ INSTALL CORS PACKAGE
RUN composer require fruitcake/laravel-cors --no-interaction

# ✅ DEBUGGING: Check after install
RUN echo "=== DEBUG: After composer install ===" && \
    echo "Vendor directory:" && \
    ls -la vendor/ && \
    echo "Google packages:" && \
    ls -la vendor/google/ 2>/dev/null || echo "No google directory" && \
    echo "Dialogflow package:" && \
    ls -la vendor/google/cloud-dialogflow/ 2>/dev/null || echo "No dialogflow package" && \
    echo "CORS package:" && \
    ls -la vendor/fruitcake/ 2>/dev/null || echo "No fruitcake directory"

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Update Apache config for Laravel
RUN cat > /etc/apache2/sites-available/000-default.conf << 'EOF'
<VirtualHost *:80>
    DocumentRoot /var/www/html/public
    
    <Directory /var/www/html/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF

EXPOSE 80
CMD ["apache2-foreground"]
