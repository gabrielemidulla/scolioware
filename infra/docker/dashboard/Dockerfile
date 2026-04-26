FROM php:8.3-fpm

# System libs needed by TCPDF (image/font support) + tools to fetch the vendor archive.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl unzip libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_mysql mysqli gd zip

# Composer: run `composer install` in `apps/dashboard/src` (bind-mounted as /var/www/html in compose).
RUN curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# TCPDF is vendored at /usr/local/lib/tcpdf so the bind-mount on /var/www/html stays untouched.
ARG TCPDF_VERSION=6.7.5
RUN mkdir -p /usr/local/lib \
    && curl -fsSL "https://github.com/tecnickcom/TCPDF/archive/refs/tags/${TCPDF_VERSION}.tar.gz" -o /tmp/tcpdf.tgz \
    && tar -xzf /tmp/tcpdf.tgz -C /usr/local/lib \
    && mv "/usr/local/lib/TCPDF-${TCPDF_VERSION}" /usr/local/lib/tcpdf \
    && rm /tmp/tcpdf.tgz

ENV SW_TCPDF_PATH=/usr/local/lib/tcpdf

COPY infra/config/php/zz-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

COPY infra/docker/dashboard/docker-entrypoint.sh /usr/local/bin/sw-dashboard-entrypoint.sh
RUN chmod +x /usr/local/bin/sw-dashboard-entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["/usr/local/bin/sw-dashboard-entrypoint.sh"]
CMD ["php-fpm"]
