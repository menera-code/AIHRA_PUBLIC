FROM php:8.2-apache

# Update PHP to 8.2.15
RUN apt-get update && apt-get install -y software-properties-common
RUN add-apt-repository ppa:ondrej/php
RUN apt-get update && apt-get install -y \
    php8.2 \
    php8.2-cli \
    php8.2-common \
    php8.2-curl \
    php8.2-mbstring \
    php8.2-xml \
    php8.2-zip \
    php8.2-bcmath \
    php8.2-gd \
    libapache2-mod-php8.2 \
    git unzip curl \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY . /var/www/html/
WORKDIR /var/www/html

# Force fresh install
RUN rm -rf vendor composer.lock && \
    composer clear-cache && \
    composer install --no-dev --no-interaction --optimize-autoloader --ignore-platform-reqs

RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf

CMD ["apache2-foreground"]
