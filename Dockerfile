ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-cli-alpine

# Install system dependencies required by Composer and common PHP extensions
RUN apk add --no-cache git unzip zip

# Copy Composer binary from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
