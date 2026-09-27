# Extend a build comparison image to profile phmake rebuilds with Excimer.
ARG BASE_IMAGE
FROM ${BASE_IMAGE}

RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install excimer-1.2.6 \
    && docker-php-ext-enable excimer

COPY e2e/profile/prepend.php /usr/local/lib/phmake-profile.php
RUN echo 'auto_prepend_file=/usr/local/lib/phmake-profile.php' > /usr/local/etc/php/conf.d/phmake-profile.ini

# Keep the timer from e2e/phase.sh and wrap it to choose what each phase runs.
RUN mv /usr/local/bin/phase /usr/local/bin/timed-phase
COPY --chmod=755 e2e/profile/phase.sh /usr/local/bin/phase
