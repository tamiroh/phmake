FROM composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b AS composer

FROM php:8.5-cli-bookworm@sha256:97c41462ce985a92b3fcb29dc02d5da568c7865ccb9fb9b0f712e192ad09e1bf

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential ca-certificates curl git unzip xz-utils \
        bison flex texinfo python3 libgmp-dev libmpfr-dev libmpc-dev zlib1g-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl

RUN echo 'memory_limit=1G' > /usr/local/etc/php/conf.d/phmake.ini

# GCC 15.2.0, pinned by https://gcc.gnu.org/pub/gcc/releases/gcc-15.2.0/sha512.sum.
RUN curl -fsSL https://gcc.gnu.org/pub/gcc/releases/gcc-15.2.0/gcc-15.2.0.tar.xz -o /tmp/gcc.tar.xz \
    && echo '89047a2e07bd9da265b507b516ed3635adb17491c7f4f67cf090f0bd5b3fc7f2ee6e4cc4008beef7ca884b6b71dffe2bb652b21f01a702e17b468cca2d10b2de  /tmp/gcc.tar.xz' | sha512sum -c - \
    && mkdir /opt/gcc-source \
    && tar -xJf /tmp/gcc.tar.xz -C /opt/gcc-source --strip-components=1 \
    && rm /tmp/gcc.tar.xz

# GCC requires a separate build directory. Avoid a three-stage bootstrap in CI.
WORKDIR /opt/gcc-build
RUN /opt/gcc-source/configure --prefix=/opt/gcc-install \
    --enable-languages=c,c++ --disable-bootstrap --disable-multilib \
    --disable-nls --disable-libsanitizer --disable-analyzer --without-isl \
    CFLAGS='-O2' CXXFLAGS='-O2'

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/phmake
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins
COPY src/ src/
COPY --chmod=755 phmake ./phmake

# Default to phmake; run-build selects GNU make for reference builds.
RUN ln -s /opt/phmake/phmake /usr/local/bin/make

WORKDIR /opt/gcc-build
COPY e2e/gcc/verify.sh /usr/local/bin/verify-gcc-build

COPY e2e/run-build.sh /usr/local/bin/run-build

CMD ["sh", "/usr/local/bin/run-build", "gcc"]
