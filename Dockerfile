FROM php:8.3-apache

# Install the native libraries and PHP extensions used by the application.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        gd \
        mbstring \
        opcache \
        pdo_mysql \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable .htaccess routing and configure the application document root.
# Use a conventional Apache vhost; a wildcard ServerAlias is unnecessary.
RUN a2enmod headers rewrite \
    && { \
        echo '<VirtualHost *:80>'; \
        echo '    ServerName localhost'; \
        echo '    DocumentRoot /var/www/html'; \
        echo '    <Directory /var/www/html>'; \
        echo '        AllowOverride All'; \
        echo '        Options -Indexes +FollowSymLinks'; \
        echo '        Require all granted'; \
        echo '        LimitRequestBody 52428800'; \
        echo '    </Directory>'; \
        echo '    ErrorLog /dev/stderr'; \
        echo '    CustomLog /dev/stdout combined'; \
        echo '</VirtualHost>'; \
    } > /etc/apache2/sites-available/000-default.conf

# Production-oriented PHP defaults. HostForge supplies secrets at runtime.
RUN { \
        echo 'expose_php=Off'; \
        echo 'display_errors=Off'; \
        echo 'log_errors=On'; \
        echo 'error_log=/proc/self/fd/2'; \
        echo 'memory_limit=256M'; \
        echo 'upload_max_filesize=8M'; \
        echo 'post_max_size=50M'; \
        echo 'max_file_uploads=20'; \
        echo 'max_input_time=120'; \
        echo 'max_execution_time=120'; \
        echo 'session.cookie_httponly=1'; \
        echo 'session.cookie_samesite=Lax'; \
        echo 'session.use_strict_mode=1'; \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=128'; \
    } > /usr/local/etc/php/conf.d/app-production.ini

WORKDIR /var/www/html
# Copy the application directly into Apache's configured DocumentRoot.
# Keeping this explicit avoids an accidental extra project-folder level.
COPY . /var/www/html/

# Validate the web-server configuration and prepare writable runtime folders.
# Required-file checks are explicit so the build log identifies the missing path.
RUN set -eux; \
    test -f /var/www/html/index.php; \
    test -f /var/www/html/auth/login.php; \
    test -f /var/www/html/health.php; \
    apache2ctl -t; \
    mkdir -p /var/www/html/storage/logs /var/www/html/storage/exports /var/www/html/storage/reports /var/www/html/storage/health_safety/incidents; \
    chown -R www-data:www-data /var/www/html/storage; \
    chmod -R 0755 /var/www/html/storage; \
    rm -f /var/www/html/.env

# HostForge Advanced settings: application/container port = 80; health path = /health.php.
# Apache listens on port 80 in this image.
EXPOSE 80

# Docker-level health check for the same endpoint configured in HostForge.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r '$ctx=stream_context_create(["http"=>["timeout"=>4,"ignore_errors"=>true]]); $c=@file_get_contents("http://127.0.0.1/health.php", false, $ctx); if($c===false) exit(1); $j=json_decode($c,true); exit(($j["status"]??"")==="ok" ? 0 : 1);'

CMD ["apache2-foreground"]
