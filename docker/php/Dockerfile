# PHP-FPM 8.4 pour KEneYa WorkFlow.
#
# 8.4 et non 8.3 : le composer.lock committe verrouille symfony/clock v8.1, qui
# exige php >= 8.4.1. Sur 8.3, le `composer install` ci-dessous echoue et
# l'image ne se construit pas — donc ni `app`, ni `scheduler`, ni
# `queue-worker`. Le serveur de production tourne deja en php8.4-fpm.
FROM php:8.4-fpm-alpine

# `gd` est indispensable et ne l'etait pas : dompdf s'en sert pour toute
# image qu'il embarque. Sans elle, la generation d'une ordonnance s'arretait
# sur « The PHP GD extension is required » — le logo de l'en-tete suffisait a
# la declencher, et le cachet de l'etablissement comme la signature du
# medecin n'arrivaient donc jamais sur le document.
#
# Les trois bibliotheques d'images accompagnent l'extension parce que les
# depots acceptent PNG, JPEG et WebP : compiler `gd` sans l'une d'elles
# rendrait un format sur trois illisible, et le refus n'apparaitrait qu'a
# l'impression, chez l'utilisateur.
RUN apk add --no-cache \
        freetype-dev \
        git \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        mysql-client \
        oniguruma-dev \
        tzdata \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/keneya.ini

WORKDIR /var/www/html

# Les dependances sont installees d'abord, pour profiter du cache de couches
# lorsque seul le code applicatif change.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
