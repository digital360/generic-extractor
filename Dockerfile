FROM php:7-cli

ARG COMPOSER_FLAGS="--no-interaction"
ENV COMPOSER_ALLOW_SUPERUSER 1

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    ssh \
    unzip \
    && rm -r /var/lib/apt/lists/* \
    && cd /root/ \
    && curl -sS https://getcomposer.org/installer | php \
    && ln -s /root/composer.phar /usr/local/bin/composer

WORKDIR /code

# Initialize
COPY php.ini /usr/local/etc/php/

## Composer - deps always cached unless changed
# First copy only composer files
COPY composer.* /code/
# Download dependencies, but don't run scripts or init autoloaders as the app is missing
RUN composer install $COMPOSER_FLAGS --no-scripts --no-autoloader
# copy rest of the app
COPY . /code/
# run normal composer - all deps are cached already
RUN composer install $COMPOSER_FLAGS

CMD php /code/run.php --data=/data
