FROM composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b AS composer

FROM php:8.5-cli-bookworm@sha256:97c41462ce985a92b3fcb29dc02d5da568c7865ccb9fb9b0f712e192ad09e1bf

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        autoconf bison build-essential ca-certificates git pkg-config re2c unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/php-src

# PHP 8.5.0, pinned independently of the release tag.
RUN git init \
    && git remote add origin https://github.com/php/php-src.git \
    && git fetch --depth=1 origin 685e99655ae97c667950f7f7d176985958718f56 \
    && git checkout --detach FETCH_HEAD \
    && ./buildconf --force \
    && ./configure --disable-all --enable-cli --disable-cgi --disable-phpdbg

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/phmake
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins
COPY src/ src/
COPY --chmod=755 phmake ./phmake

# Recursive make commands must use phmake as well.
RUN ln -s /opt/phmake/phmake /usr/local/bin/make

WORKDIR /opt/php-src
COPY e2e/verify.sh /usr/local/bin/verify-php-build

CMD ["sh", "/usr/local/bin/verify-php-build"]
