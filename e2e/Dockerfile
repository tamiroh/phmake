FROM ubuntu:24.04@sha256:008173c23f95b170204355c12626cb5a965d779a7e1283b09e9cffbb1bf33ca3

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        autoconf bison build-essential ca-certificates git pkg-config re2c \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/php-src

# PHP 8.5.0, pinned independently of the release tag.
RUN git init \
    && git remote add origin https://github.com/php/php-src.git \
    && git fetch --depth=1 origin 685e99655ae97c667950f7f7d176985958718f56 \
    && git checkout --detach FETCH_HEAD \
    && ./buildconf --force \
    && ./configure --disable-all --enable-cli --disable-cgi --disable-phpdbg

COPY verify.sh /usr/local/bin/verify-php-build

CMD ["sh", "/usr/local/bin/verify-php-build"]
