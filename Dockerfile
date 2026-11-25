ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm

ARG UID=1000
ARG GID=1000

# PHP extensions + Python runtime for the vCenter collector
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev libonig-dev \
        python3 python3-venv ca-certificates \
    && docker-php-ext-install pdo_pgsql pgsql zip intl opcache bcmath pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# vCenter collector dependencies live in an isolated venv (pinned in docker/requirements.txt)
COPY docker/requirements.txt /opt/collector-requirements.txt
RUN python3 -m venv /opt/venv \
    && /opt/venv/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/venv/bin/pip install --no-cache-dir -r /opt/collector-requirements.txt
ENV NXTVM_PYTHON=/opt/venv/bin/python \
    LANG=C.UTF-8

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Align container www-data with the host user so bind-mounted src/ stays writable
RUN groupmod -o -g ${GID} www-data \
    && usermod  -o -u ${UID} -g ${GID} www-data

WORKDIR /var/www/html

RUN mkdir -p /var/www/.config && chown -R www-data:www-data /var/www

USER www-data
