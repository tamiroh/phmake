FROM composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b AS composer

FROM php:8.5-cli-bookworm@sha256:97c41462ce985a92b3fcb29dc02d5da568c7865ccb9fb9b0f712e192ad09e1bf

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential ca-certificates procps curl git unzip gettext perl python3 cargo \
        libcurl4-openssl-dev libexpat1-dev libssl-dev zlib1g-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl

# Git expands a large Coccinelle rule matrix even for the normal build targets.
RUN echo 'memory_limit=1G' > /usr/local/etc/php/conf.d/phmake.ini

# Git 2.55.0 and its SHA-256 from https://www.kernel.org/pub/software/scm/git/sha256sums.asc.
RUN curl -fsSL https://www.kernel.org/pub/software/scm/git/git-2.55.0.tar.gz -o /tmp/git.tar.gz \
    && echo '0842dc384a23ac33ba3e570c4f3a8ded85963ee4713b1cd21153c3db41813d1e  /tmp/git.tar.gz' | sha256sum -c - \
    && mkdir /opt/git \
    && tar -xzf /tmp/git.tar.gz -C /opt/git --strip-components=1 \
    && rm /tmp/git.tar.gz

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/phmake
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins
COPY src/ src/
COPY --chmod=755 phmake ./phmake

# Recursive make commands must use phmake as well.
RUN ln -s /opt/phmake/phmake /usr/local/bin/make

WORKDIR /opt/git
COPY e2e/git/verify.sh /usr/local/bin/verify-git-build

CMD ["sh", "/usr/local/bin/verify-git-build"]
