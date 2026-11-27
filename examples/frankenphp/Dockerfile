FROM dunglas/frankenphp:1.14.0-php8.5-bookworm@sha256:584ba154d93a5fa5d4e24fdd5ded41cfe2f103d577802787fb71c197d582851d AS base

FROM base AS build
# Excimer 1.2.7, compiled against this image's PHP ZTS headers.
ADD --checksum=sha256:c25ff4bbf0c5a32a506463b83096a358a37f9bcce0b97933611ac89367dcb0b4 \
    https://codeload.github.com/wikimedia/php-excimer/tar.gz/23f541b9d970db0524a1b2506b4df727e5e59998 /tmp/excimer.tar.gz
RUN apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && mkdir /tmp/excimer \
    && tar -xzf /tmp/excimer.tar.gz --strip-components=1 -C /tmp/excimer \
    && cd /tmp/excimer \
    && phpize && ./configure && make -j2

FROM base
COPY --from=build /tmp/excimer/modules/excimer.so /tmp/excimer.so
RUN mv /tmp/excimer.so "$(php-config --extension-dir)/excimer.so" \
    && docker-php-ext-enable excimer \
    && php --ri excimer
