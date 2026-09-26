FROM composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b AS composer

FROM php:8.5-cli-bookworm@sha256:97c41462ce985a92b3fcb29dc02d5da568c7865ccb9fb9b0f712e192ad09e1bf

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential ca-certificates procps curl diffutils git patch perl unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl

# GNU make 4.4.1, pinned by the release archive's SHA-256.
RUN curl -fsSL https://ftp.gnu.org/gnu/make/make-4.4.1.tar.gz -o /tmp/make.tar.gz \
    && echo 'dd16fb1d67bfab79a72f5e8390735c49e3e8e70b4945a15ab1f81ddb78658fb3  /tmp/make.tar.gz' | sha256sum -c - \
    && mkdir /opt/make \
    && tar -xzf /tmp/make.tar.gz -C /opt/make --strip-components=1 \
    && rm /tmp/make.tar.gz

WORKDIR /opt/make
RUN ./configure --without-guile --disable-nls

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
COPY --chmod=755 phmake ./phmake

# Recursive make commands must use phmake as well.
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

CMD ["sh", "/usr/local/bin/verify-make-build"]
