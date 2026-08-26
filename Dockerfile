# Keep production rebuilds on the audited PHP 8.3.33 / Alpine 3.24 base.
FROM php:8.3.33-fpm-alpine3.24@sha256:bf90236449d333cef008b1f01c72a3d4f11a6470a74629665e4c6b6158f03fc8

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

# Copy your PHP application files into the container
COPY . .

# Copy Nginx configuration
COPY nginx.conf /etc/nginx/nginx.conf
COPY nginx.default.conf /etc/nginx/http.d/default.conf

# Remove nginx conf files from webroot
RUN rm -rf /var/www/html/nginx.conf && \
    rm -rf /var/www/html/nginx.default.conf

# Keep the entrypoint executable when the repository was edited on Windows.
RUN sed -i 's/\r$//' /var/www/html/startup.sh

# Copy the custom crontab file
COPY cronjobs /etc/cron.d/cronjobs

# Convert the line endings, allow read access to the cron file, and create cron log folder
RUN dos2unix /etc/cron.d/cronjobs && \
    chmod 0644 /etc/cron.d/cronjobs && \
    /usr/bin/crontab /etc/cron.d/cronjobs && \
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
