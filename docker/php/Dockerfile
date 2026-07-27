FROM php:8.3-fpm

WORKDIR /var/www/html

ARG APP_XDEBUG=false

# Install system dependencies
RUN apt-get update && apt-get install -y \
    pigz \
    libbz2-dev \
    zlib1g-dev \
    libpng-dev \
    libgmp-dev \
    libpq-dev \
    libicu-dev \
    libonig-dev \
    libzip-dev \
    libmagickwand-dev \
    curl \
    git \
    unzip \
    cron \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install \
    gmp \
    pdo \
    pdo_pgsql \
    pgsql \
    intl \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    bz2 \
 && pecl install redis imagick \
 && docker-php-ext-enable redis imagick \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy scripts and config
COPY ./scripts /usr/local/bin/scripts
COPY ./config/www.conf /usr/local/etc/php-fpm.d/www.conf

# Set permissions for scripts
RUN chmod +x /usr/local/bin/scripts/*.sh

# Run install script (for NVM, Node, Xdebug)
RUN bash /usr/local/bin/scripts/install.sh -d "${APP_XDEBUG}"

# Default to app user
USER www-data

CMD ["php-fpm"]
