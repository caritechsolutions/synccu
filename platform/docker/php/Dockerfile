################################################################################
# SyncCU Platform - PHP 8.2-FPM Dockerfile
# Core Banking Platform - Backend API v1
################################################################################

FROM php:8.2-fpm

LABEL maintainer="SyncCU Platform <devops@synccu.com>"
LABEL description="PHP 8.2-FPM with extensions for SyncCU banking platform"

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libicu-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    unzip \
    curl \
    git \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        intl \
        zip \
        opcache \
        bcmath \
        mbstring \
        xml \
        pcntl

# Install Redis extension via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis

# Configure opcache for production
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.revalidate_freq=0'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.save_comments=1'; \
    echo 'opcache.fast_shutdown=1'; \
} > /usr/local/etc/php/conf.d/opcache.ini

# Configure PHP for production
RUN { \
    echo 'upload_max_filesize=10M'; \
    echo 'post_max_size=12M'; \
    echo 'memory_limit=256M'; \
    echo 'max_execution_time=300'; \
    echo 'expose_php=Off'; \
    echo 'date.timezone=UTC'; \
    echo 'session.cookie_httponly=1'; \
    echo 'session.cookie_secure=1'; \
    echo 'session.use_strict_mode=1'; \
} > /usr/local/etc/php/conf.d/synccu.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install php-fpm-healthcheck
RUN curl -o /usr/local/bin/php-fpm-healthcheck \
    https://raw.githubusercontent.com/renatomefi/php-fpm-healthcheck/master/php-fpm-healthcheck \
    && chmod +x /usr/local/bin/php-fpm-healthcheck

# Enable PHP-FPM status page for healthcheck
RUN sed -i 's/;pm.status_path = \/status/pm.status_path = \/status/' \
    /usr/local/etc/php-fpm.d/www.conf

# Set working directory
WORKDIR /var/www/synccu/backend

# Copy backend application code
COPY backend/ /var/www/synccu/backend/

# Install PHP dependencies
RUN if [ -f composer.json ]; then \
    composer install --no-dev --optimize-autoloader --no-interaction --no-scripts; \
    fi

# Create required directories
RUN mkdir -p storage/logs storage/documents storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

# Set ownership to www-data
RUN chown -R www-data:www-data /var/www/synccu/backend

# Switch to www-data user
USER www-data

EXPOSE 9000

CMD ["php-fpm"]
