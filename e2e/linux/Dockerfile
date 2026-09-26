FROM composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b AS composer

FROM php:8.5-cli-bookworm@sha256:97c41462ce985a92b3fcb29dc02d5da568c7865ccb9fb9b0f712e192ad09e1bf

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bc bison build-essential ca-certificates procps curl flex git libelf-dev libssl-dev \
        qemu-system-x86 unzip xz-utils \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl

# Linux 6.12, pinned by https://cdn.kernel.org/pub/linux/kernel/v6.x/sha256sums.asc.
RUN curl -fsSL https://cdn.kernel.org/pub/linux/kernel/v6.x/linux-6.12.tar.xz -o /tmp/linux.tar.xz \
    && echo 'b1a2562be56e42afb3f8489d4c2a7ac472ac23098f1ef1c1e40da601f54625eb  /tmp/linux.tar.xz' | sha256sum -c - \
    && mkdir /opt/linux \
    && tar -xJf /tmp/linux.tar.xz -C /opt/linux --strip-components=1 \
    && rm /tmp/linux.tar.xz

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/phmake
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins
COPY src/ src/
COPY --chmod=755 phmake ./phmake

# Recursive make commands must use phmake as well.
RUN ln -s /opt/phmake/phmake /usr/local/bin/make

COPY e2e/linux/init.c /tmp/linux-init.c
RUN cc -static -Os -Wall -Wextra -Werror /tmp/linux-init.c -o /opt/linux-smoke-init \
    && rm /tmp/linux-init.c
COPY e2e/linux/initramfs.list /opt/initramfs.list
COPY e2e/linux/kernel.config /opt/kernel.config
COPY e2e/linux/verify.sh /usr/local/bin/verify-linux-build

WORKDIR /opt/linux
CMD ["sh", "/usr/local/bin/verify-linux-build"]
