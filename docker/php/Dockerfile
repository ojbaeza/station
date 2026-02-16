FROM php:8.5-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    zip \
    unzip \
    linux-headers \
    $PHPIZE_DEPS

# Install PHP extensions required for Station drivers
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    sockets

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install AMQP extension (for RabbitMQ)
RUN apk add --no-cache rabbitmq-c-dev \
    && pecl install amqp \
    && docker-php-ext-enable amqp

# Install rdkafka extension (for Kafka)
RUN apk add --no-cache librdkafka-dev \
    && pecl install rdkafka \
    && docker-php-ext-enable rdkafka

# Install Xdebug (for coverage)
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Create system user
RUN addgroup -g 1000 station && adduser -u 1000 -G station -s /bin/sh -D station

# Set ownership
RUN chown -R station:station /var/www/html

USER station

EXPOSE 9000

CMD ["php-fpm"]
