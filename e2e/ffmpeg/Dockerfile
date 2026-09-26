FROM composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b AS composer

FROM php:8.5-cli-bookworm@sha256:97c41462ce985a92b3fcb29dc02d5da568c7865ccb9fb9b0f712e192ad09e1bf

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential ca-certificates curl git nasm pkg-config unzip xz-utils \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl

# FFmpeg 8.1.1, pinned by the official release archive's SHA-256.
RUN curl -fsSL https://ffmpeg.org/releases/ffmpeg-8.1.1.tar.xz -o /tmp/ffmpeg.tar.xz \
    && echo 'b6863adde98898f42602017462871b5f6333e65aec803fdd7a6308639c52edf3  /tmp/ffmpeg.tar.xz' | sha256sum -c - \
    && mkdir /opt/ffmpeg \
    && tar -xJf /tmp/ffmpeg.tar.xz -C /opt/ffmpeg --strip-components=1 \
    && rm /tmp/ffmpeg.tar.xz

WORKDIR /opt/ffmpeg
# Retain the upstream Makefile graph while limiting codec compilation time.
RUN ./configure --prefix=/opt/ffmpeg-install \
    --disable-autodetect --disable-doc --disable-debug --disable-network \
    --disable-everything \
    --enable-encoder=ffv1,pcm_s16le,rawvideo --enable-decoder=ffv1,pcm_s16le,wrapped_avframe \
    --enable-muxer=matroska,framemd5,pcm_s16le --enable-demuxer=matroska \
    --enable-protocol=file --enable-indev=lavfi \
    --enable-filter=testsrc2,sine,format,aformat,aresample

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/phmake
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins
COPY src/ src/
COPY --chmod=755 phmake ./phmake

# Default to phmake; run-build selects GNU make for reference builds.
RUN ln -s /opt/phmake/phmake /usr/local/bin/make

WORKDIR /opt/ffmpeg
COPY e2e/ffmpeg/verify.sh /usr/local/bin/verify-ffmpeg-build

COPY e2e/run-build.sh /usr/local/bin/run-build

CMD ["sh", "/usr/local/bin/run-build", "ffmpeg"]
