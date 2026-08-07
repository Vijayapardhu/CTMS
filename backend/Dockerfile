# Use PHP 8.3 official image with FPM
FROM php:8.3-fpm

# Set working directory
WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    libzip-dev \
    unzip \
    zip \
    redis-tools \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    zip \
    bcmath \
    pcntl \
    sockets

# Enable OPcache for better performance
RUN docker-php-ext-enable opcache

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy composer files
COPY composer.json composer.lock ./

# Install dependencies
RUN composer install --no-dev --no-interaction --no-progress

# Copy application
COPY . .

# Create necessary directories and set permissions
RUN mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Set permissions
RUN chmod -R 775 storage bootstrap/cache

# Generate app key if not already set
RUN if [ ! -f .env ]; then cp .env.example .env && php artisan key:generate --no-ansi; fi

# Expose port
EXPOSE 9000

# Start PHP-FPM
CMD ["php-fpm"]
