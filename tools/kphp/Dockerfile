FROM ubuntu:22.04

RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    autoconf automake build-essential ca-certificates cmake git gperf \
    libfmt-dev libtool patch php-cli pkg-config python3 python3-jsonschema \
    python3-six re2c zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*

ARG KPHP_REPOSITORY=https://github.com/VKCOM/kphp.git
ARG KPHP_COMMIT
ARG KPHP_JOBS=2

WORKDIR /opt/kphp
RUN test -n "$KPHP_COMMIT" \
    && git init \
    && git remote add origin "$KPHP_REPOSITORY" \
    && git fetch --depth 1 origin "$KPHP_COMMIT" \
    && git checkout --detach FETCH_HEAD \
    && git submodule update --init --recursive

# Ninja avoids nested GNU Make jobserver descriptors in upstream ExternalProjects.
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends ninja-build \
    && rm -rf /var/lib/apt/lists/*

RUN cmake -G Ninja -S . -B build -DKPHP_TESTS=OFF \
    && cmake --build build --target kphp --parallel "$KPHP_JOBS" \
    && ln -s /opt/kphp/objs/bin/kphp2cpp /usr/local/bin/kphp \
    && kphp --version

# Generated programs link this library, which is not part of the kphp target.
RUN cmake --build build --target flex_data_static-no-pic --parallel "$KPHP_JOBS"

WORKDIR /work
