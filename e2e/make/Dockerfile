FROM composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b AS composer

FROM php:8.5-cli-bookworm@sha256:97c41462ce985a92b3fcb29dc02d5da568c7865ccb9fb9b0f712e192ad09e1bf

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential ca-certificates procps curl diffutils git patch perl unzip guile-3.0-dev pkg-config \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl

# GNU make 4.4.1, pinned by the release archive's SHA-256.
RUN curl -fsSL https://ftp.gnu.org/gnu/make/make-4.4.1.tar.gz -o /tmp/make.tar.gz \
    && echo 'dd16fb1d67bfab79a72f5e8390735c49e3e8e70b4945a15ab1f81ddb78658fb3  /tmp/make.tar.gz' | sha256sum -c - \
    && mkdir /opt/make \
    && tar -xzf /tmp/make.tar.gz -C /opt/make --strip-components=1 \
    && rm /tmp/make.tar.gz

WORKDIR /opt/make
RUN ./configure --with-guile --disable-nls

# Keep a reference executable without prebuilding the phmake build smoke test.
RUN /usr/bin/make -j2 \
    && mkdir -p /usr/local/libexec \
    && cp make /usr/local/libexec/gnu-make-4.4.1 \
    && /usr/bin/make clean

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/phmake
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins
COPY src/ src/
COPY native/module-host.c native/guile.c native/module-api.h /tmp/
RUN cc -std=c11 -O2 -Wall -Wextra -Werror -DPHMAKE_GUILE -Wl,--export-dynamic \
    -o /usr/local/libexec/phmake-module-host /tmp/module-host.c /tmp/guile.c $(pkg-config --cflags --libs guile-3.0) -ldl \
    && rm /tmp/module-host.c /tmp/guile.c /tmp/module-api.h
ENV PHMAKE_MODULE_HOST=/usr/local/libexec/phmake-module-host
COPY --chmod=755 phmake ./phmake
COPY tools/build-phar.php tools/build-phar.php

# A copied test executable must retain its dependencies and survive PATH changes.
RUN php -d phar.readonly=0 tools/build-phar.php /tmp/phmake.phar /usr/local/bin/php \
    && mv /tmp/phmake.phar /opt/phmake/phmake

# Default to phmake; run-build selects GNU make for reference builds.
RUN ln -s /opt/phmake/phmake /usr/local/bin/make

WORKDIR /opt/make
COPY e2e/make/verify.sh /usr/local/bin/verify-make-build
COPY e2e/make/run-tests.sh /usr/local/bin/run-gnu-make-tests
COPY e2e/make/test-runner.patch /tmp/test-runner.patch
COPY e2e/make/verify-runner.pl /usr/local/bin/verify-gnu-test-runner
RUN patch -d /opt/make -p1 < /tmp/test-runner.patch \
    && rm /tmp/test-runner.patch \
    && perl /usr/local/bin/verify-gnu-test-runner \
    && chmod -R a+rwX /opt/make/tests

COPY e2e/run-build.sh /usr/local/bin/run-build

CMD ["sh", "/usr/local/bin/run-build", "make"]
