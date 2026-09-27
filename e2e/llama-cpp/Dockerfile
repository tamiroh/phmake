FROM composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b AS composer

FROM php:8.5-cli-bookworm@sha256:97c41462ce985a92b3fcb29dc02d5da568c7865ccb9fb9b0f712e192ad09e1bf

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential ca-certificates cmake procps curl git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl

RUN echo 'memory_limit=1G' > /usr/local/etc/php/conf.d/phmake.ini

# llama.cpp v0.5.0, pinned by the SHA-256 of GitHub's tag archive.
RUN curl -fsSL https://github.com/ggml-org/llama.cpp/archive/refs/tags/v0.5.0.tar.gz -o /tmp/llama.cpp.tar.gz \
    && echo 'fef9ed754f4e031fb5c663c29260feda4ebc241abb68d64a81c0f1df5f1748e2  /tmp/llama.cpp.tar.gz' | sha256sum -c - \
    && mkdir /opt/llama.cpp \
    && tar -xzf /tmp/llama.cpp.tar.gz -C /opt/llama.cpp --strip-components=1 \
    && rm /tmp/llama.cpp.tar.gz

# Generate CMake's Unix Makefiles; portable CPU flags keep the build independent of the runner.
RUN cmake -S /opt/llama.cpp -B /opt/llama.cpp-build -G 'Unix Makefiles' \
    -DCMAKE_BUILD_TYPE=Release -DGGML_NATIVE=OFF -DGGML_CCACHE=OFF -DLLAMA_OPENSSL=OFF \
    -DLLAMA_BUILD_TESTS=ON -DLLAMA_BUILD_TOOLS=ON \
    -DLLAMA_BUILD_EXAMPLES=OFF -DLLAMA_BUILD_SERVER=OFF -DLLAMA_BUILD_APP=OFF

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/phmake
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins
COPY src/ src/
COPY --chmod=755 phmake ./phmake

# Default to phmake; run-build selects GNU make for reference builds.
RUN ln -s /opt/phmake/phmake /usr/local/bin/make

WORKDIR /opt/llama.cpp-build
COPY e2e/llama-cpp/verify.sh /usr/local/bin/verify-llama-cpp-build

COPY e2e/run-build.sh /usr/local/bin/run-build
COPY --chmod=755 e2e/phase.sh /usr/local/bin/phase

CMD ["sh", "/usr/local/bin/run-build", "llama-cpp"]
