FROM php:8.5.13-cli-bookworm@sha256:7594f2ba7a3bfef73dbf26d92b26d7247165a46040dc1950f015a9beb1242ccf AS base

FROM base AS build
# Compile both extensions against the pinned PHP ABI.
ADD --checksum=sha256:c25ff4bbf0c5a32a506463b83096a358a37f9bcce0b97933611ac89367dcb0b4 \
    https://codeload.github.com/wikimedia/php-excimer/tar.gz/23f541b9d970db0524a1b2506b4df727e5e59998 /tmp/excimer.tar.gz
ADD --checksum=sha256:61f477f6c4515b8b85a9e353cc68298a210521d064e37e734d24a13e68c9a579 \
    https://codeload.github.com/swoole/swoole-src/tar.gz/refs/tags/v6.3.0 /tmp/swoole.tar.gz
RUN apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS libssl-dev zlib1g-dev \
    && mkdir /tmp/excimer /tmp/swoole \
    && tar -xzf /tmp/excimer.tar.gz --strip-components=1 -C /tmp/excimer \
    && tar -xzf /tmp/swoole.tar.gz --strip-components=1 -C /tmp/swoole \
    && cd /tmp/excimer && phpize && ./configure && make -j2 \
    && cd /tmp/swoole && phpize && ./configure --enable-swoole --disable-brotli && make -j2

FROM base
COPY --from=build /tmp/excimer/modules/excimer.so /tmp/swoole/modules/swoole.so /tmp/extensions/
RUN mv /tmp/extensions/*.so "$(php-config --extension-dir)/" \
    && docker-php-ext-enable excimer swoole \
    && php --ri excimer && php --ri swoole
