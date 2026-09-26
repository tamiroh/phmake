FROM composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b AS composer

FROM php:8.5-cli-bookworm@sha256:97c41462ce985a92b3fcb29dc02d5da568c7865ccb9fb9b0f712e192ad09e1bf

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential ca-certificates procps curl git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl

# Lua 5.4.8 and its SHA-256 from https://www.lua.org/ftp/.
RUN curl -fsSL https://www.lua.org/ftp/lua-5.4.8.tar.gz -o /tmp/lua.tar.gz \
    && echo '4f18ddae154e793e46eeab727c59ef1c0c0c2b744e7b94219710d76f530629ae  /tmp/lua.tar.gz' | sha256sum -c - \
    && mkdir /opt/lua \
    && tar -xzf /tmp/lua.tar.gz -C /opt/lua --strip-components=1 \
    && rm /tmp/lua.tar.gz

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/phmake
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins
COPY src/ src/
COPY --chmod=755 phmake ./phmake

# Default to phmake; run-build selects GNU make for reference builds.
RUN ln -s /opt/phmake/phmake /usr/local/bin/make

WORKDIR /opt/lua
COPY e2e/lua/verify.sh /usr/local/bin/verify-lua-build

COPY e2e/run-build.sh /usr/local/bin/run-build

CMD ["sh", "/usr/local/bin/run-build", "lua"]
