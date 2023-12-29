ARG CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX
ARG COMPOSER_VERSION
ARG PHP_VERSION

FROM ${CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX}/composer:${COMPOSER_VERSION} as composer
FROM ${CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX}/php:${PHP_VERSION}-cli

# Install composer from image
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Install PHP extensions
RUN apt-get update && \
  apt-get install -y libgmp-dev libxml2-dev libzip-dev zip unzip && \
  pecl install inotify && \
  docker-php-ext-enable inotify && \
  docker-php-ext-install gmp && \
  docker-php-ext-install soap && \
  docker-php-ext-install zip
