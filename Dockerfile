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

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mysqli gd zip

# Enable Apache rewrite module
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . /var/www/html/

# Install PHP dependencies
WORKDIR /var/www/html
RUN if [ -f "composer.json" ]; then \
    composer install --no-dev --optimize-autoloader --no-interaction; \
    fi

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# ✅ FIXED: Change DocumentRoot to /var/www/html/public for Laravel
RUN echo '<VirtualHost *:80>
    DocumentRoot /var/www/html/public
    
    <Directory /var/www/html/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    # Also allow .htaccess in parent directory if needed
    <Directory /var/www/html>
        AllowOverride All
    </Directory>
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# Create Laravel storage directories if they don't exist
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && chmod -R 775 storage bootstrap/cache

# Generate Laravel key if .env doesn't exist
RUN if [ ! -f ".env" ] && [ -f ".env.example" ]; then \
    cp .env.example .env; \
    php artisan key:generate; \
    fi

EXPOSE 80
CMD ["apache2-foreground"]
