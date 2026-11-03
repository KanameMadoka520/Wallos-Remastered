# PHP 8.3.36 fixes CVE-2026-109618 and CVE-2026-109619.
# Local base build recipe: docker/php-base/Dockerfile.
FROM nginx:1.30.6-alpine AS security_nginx
FROM wallos-php-base:8.3.36-fpm-alpine3.24

# Set working directory to /var/www/html
WORKDIR /var/www/html

# Update packages and install dependencies
RUN apk add --no-cache dumb-init shadow sqlite-dev libpng libpng-dev libjpeg-turbo libjpeg-turbo-dev freetype freetype-dev curl autoconf libgomp icu-dev icu-data-full nginx dcron tzdata imagemagick imagemagick-dev libzip-dev sqlite libwebp-dev && \
    docker-php-ext-install pdo pdo_sqlite calendar && \
    docker-php-ext-enable pdo pdo_sqlite && \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && \
    docker-php-ext-install -j$(nproc) gd intl zip && \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS && \
    pecl install imagick-3.8.1 && \
    docker-php-ext-enable imagick && \
    apk del .build-deps

# Preserve the patched NGINX binary until Alpine ships the same security version.
# Change SECURITY_REFRESH (or pass today's date at build time) to invalidate the
# package layer without recompiling PHP extensions. --no-cache here only controls
# apk's index cache; it does not bypass Docker's layer cache.
ARG SECURITY_REFRESH=2026-11-03
RUN echo "Refreshing security packages (${SECURITY_REFRESH})" && \
    apk upgrade --no-cache && \
    apk add --no-cache 'libexpat>=2.10.0-r0' && \
    mkdir -p /var/cache/nginx
COPY --from=security_nginx /usr/sbin/nginx /usr/sbin/nginx

# Copy your PHP application files into the container
COPY . .

# Copy Nginx configuration
COPY nginx.conf /etc/nginx/nginx.conf
COPY docker/php/zz-wallos-performance.ini /usr/local/etc/php/conf.d/zz-wallos-performance.ini
COPY docker/php/zz-security.ini /usr/local/etc/php/conf.d/zz-security.ini
COPY docker/php/zzz-wallos-security.conf /usr/local/etc/php-fpm.d/zzz-wallos-security.conf

# Remove nginx conf files from webroot
RUN rm -rf /var/www/html/nginx.conf && \
    rm -rf /var/www/html/nginx.default.conf && \
    rm -rf /var/www/html/docker

# Keep the entrypoint executable when the repository was edited on Windows.
RUN sed -i 's/\r$//' /var/www/html/startup.sh

# Stage the cron jobs outside dcron's system schedule directory. The file is
# installed as root's per-user crontab below and must not remain in both
# locations, otherwise dcron executes every job twice.
COPY cronjobs /tmp/wallos-cronjobs

# Convert the line endings, allow read access to the cron file, and create cron log folder
RUN dos2unix /tmp/wallos-cronjobs && \
    chmod 0600 /tmp/wallos-cronjobs && \
    /usr/bin/crontab /tmp/wallos-cronjobs && \
    rm -f /tmp/wallos-cronjobs && \
    mkdir /var/log/cron && \
    chown -R www-data:www-data /var/www/html && \
    chmod +x /var/www/html/startup.sh && \
    echo 'pm.max_children = 15' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    echo 'pm.max_requests = 500' >> /usr/local/etc/php-fpm.d/zz-docker.conf

# Expose port 80 for Nginx
EXPOSE 80

ENTRYPOINT ["dumb-init", "--single-child", "--"]

# Requires docker engine 25+ for the --start-interval flag
HEALTHCHECK --interval=2m --timeout=5s --start-period=20s --start-interval=5s --retries=3 \
    CMD ["curl", "-fsS", "http://127.0.0.1/health.php"]

# Start PHP-FPM, cron, and Nginx under the entrypoint supervisor.
CMD ["/var/www/html/startup.sh"]
