# MoneyUnify Switch: one container with the web app (nginx + PHP-FPM), the
# queue worker and the scheduler, run by supervisord. Built for Render, and
# works on any host that runs Docker and sets PORT.

FROM php:8.4-fpm-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PORT=10000

# System packages: nginx, supervisord, envsubst (gettext-base), redis-server and Node.js 22,
# which builds the front end and runs PayPal's MCP server for the AI agents.
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx supervisor gettext-base git unzip curl ca-certificates redis-server \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl bcmath gd zip pdo_pgsql pdo_mysql pcntl opcache redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first, so code changes don't reinstall them.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .

# Autoloader, route types for the front end, the front-end build and the docs
# index. Dev-only npm packages are dropped afterwards; @paypal/mcp stays.
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && php artisan package:discover --ansi \
    && php artisan wayfinder:generate --with-form \
    && npm run build \
    && npm prune --omit=dev --no-audit --no-fund \
    && php artisan docs:index --fresh \
    && chown -R www-data:www-data storage bootstrap/cache database

# PHP: production settings, native OPcache with JIT, and keep the host's environment variables visible
# to PHP-FPM workers (they're cleared by default).
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf '%s\n' \
        'memory_limit=256M' \
        'upload_max_filesize=20M' \
        'post_max_size=20M' \
        'opcache.enable=1' \
        'opcache.enable_cli=1' \
        'opcache.memory_consumption=256' \
        'opcache.interned_strings_buffer=16' \
        'opcache.max_accelerated_files=30000' \
        'opcache.validate_timestamps=0' \
        'opcache.revalidate_freq=0' \
        'opcache.save_comments=1' \
        'opcache.fast_shutdown=1' \
        'opcache.jit=tracing' \
        'opcache.jit_buffer_size=64M' \
        'redis.pconnect.pooling_enabled=1' \
        'redis.pconnect.connection_limit=0' \
        > "$PHP_INI_DIR/conf.d/zz-moneyunify.ini" \
    && printf '%s\n' \
        '[www]' \
        'clear_env = no' \
        'pm = dynamic' \
        'pm.max_children = 10' \
        'pm.start_servers = 2' \
        'pm.min_spare_servers = 1' \
        'pm.max_spare_servers = 4' \
        > /usr/local/etc/php-fpm.d/zz-moneyunify.conf

# nginx: serves public/ and hands PHP to PHP-FPM. PORT is filled in at start.
# Larger FastCGI buffers fit the asset preload (Link) headers on app pages,
# gzip compression and immutable asset caching accelerate page loads.
RUN rm -f /etc/nginx/sites-enabled/default \
    && mkdir -p /etc/nginx/templates \
    && printf '%s\n' \
        'server {' \
        '    listen ${PORT};' \
        '    root /var/www/html/public;' \
        '    index index.php;' \
        '    client_max_body_size 20m;' \
        '    access_log /dev/stdout;' \
        '    error_log /dev/stderr warn;' \
        '    add_header X-Content-Type-Options nosniff;' \
        '' \
        '    gzip on;' \
        '    gzip_vary on;' \
        '    gzip_proxied any;' \
        '    gzip_comp_level 6;' \
        '    gzip_types text/plain text/css text/xml application/json application/javascript application/rss+xml application/atom+xml image/svg+xml;' \
        '' \
        '    location /build/ {' \
        '        expires max;' \
        '        access_log off;' \
        '        add_header Cache-Control "public, max-age=31536000, immutable";' \
        '        try_files $uri =404;' \
        '    }' \
        '' \
        '    location ~* \.(?:ico|css|js|gif|jpe?g|png|woff2?|eot|otf|ttf|svg|webp)$ {' \
        '        expires 30d;' \
        '        access_log off;' \
        '        add_header Cache-Control "public, no-transform";' \
        '        try_files $uri =404;' \
        '    }' \
        '' \
        '    location / {' \
        '        try_files $uri $uri/ /index.php?$query_string;' \
        '    }' \
        '' \
        '    location ~ \.php$ {' \
        '        fastcgi_pass 127.0.0.1:9000;' \
        '        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;' \
        '        include fastcgi_params;' \
        '        fastcgi_read_timeout 120;' \
        '        fastcgi_buffer_size 64k;' \
        '        fastcgi_buffers 16 32k;' \
        '        fastcgi_busy_buffers_size 64k;' \
        '    }' \
        '' \
        '    location ~ /\.(?!well-known) {' \
        '        deny all;' \
        '    }' \
        '}' \
        > /etc/nginx/templates/default.conf.template

# supervisord: web server, PHP-FPM, queue worker and scheduler.
RUN printf '%s\n' \
        '[supervisord]' \
        'nodaemon=true' \
        'user=root' \
        'logfile=/dev/null' \
        'logfile_maxbytes=0' \
        '' \
        '[program:php-fpm]' \
        'command=php-fpm -F' \
        'stdout_logfile=/dev/stdout' \
        'stdout_logfile_maxbytes=0' \
        'redirect_stderr=true' \
        '' \
        '[program:nginx]' \
        'command=nginx -g "daemon off;"' \
        'stdout_logfile=/dev/stdout' \
        'stdout_logfile_maxbytes=0' \
        'redirect_stderr=true' \
        '' \
        '[program:queue]' \
        'command=php /var/www/html/artisan queue:work --sleep=1 --tries=3 --max-time=3600' \
        'user=www-data' \
        'autorestart=true' \
        'stopwaitsecs=60' \
        'stdout_logfile=/dev/stdout' \
        'stdout_logfile_maxbytes=0' \
        'redirect_stderr=true' \
        '' \
        '[program:scheduler]' \
        'command=php /var/www/html/artisan schedule:work' \
        'user=www-data' \
        'autorestart=true' \
        'stdout_logfile=/dev/stdout' \
        'stdout_logfile_maxbytes=0' \
        'redirect_stderr=true' \
        > /etc/supervisor/conf.d/moneyunify.conf

# Start: make sure there's an app key (local Docker gets one generated and
# kept in its storage volume), fill in the port, migrate, optionally add the
# demo account, optimize caches and native OPcache, then hand over to supervisord.
RUN printf '%s\n' \
        '#!/bin/sh' \
        'set -e' \
        'cd /var/www/html' \
        'if [ -z "$APP_KEY" ]; then' \
        '  echo "APP_KEY is not set: using a generated key kept in storage/app/.app-key. Set APP_KEY on hosted deployments."' \
        '  [ -s storage/app/.app-key ] || php artisan key:generate --show > storage/app/.app-key' \
        '  export APP_KEY="$(cat storage/app/.app-key)"' \
        'fi' \
        'envsubst '"'"'${PORT}'"'"' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf' \
        'if [ "$PHP_OPCACHE_VALIDATE_TIMESTAMPS" = "1" ] || [ "$APP_ENV" = "local" ] || [ "$APP_DEBUG" = "true" ]; then' \
        '  sed -i "s/opcache.validate_timestamps=0/opcache.validate_timestamps=1/" "$PHP_INI_DIR/conf.d/zz-moneyunify.ini" 2>/dev/null || true' \
        'fi' \
        'if [ "$PHP_OPCACHE_ENABLE" = "0" ] || [ "$PHP_OPCACHE_ENABLE" = "false" ]; then' \
        '  sed -i "s/opcache.enable=1/opcache.enable=0/" "$PHP_INI_DIR/conf.d/zz-moneyunify.ini" 2>/dev/null || true' \
        '  sed -i "s/opcache.enable_cli=1/opcache.enable_cli=0/" "$PHP_INI_DIR/conf.d/zz-moneyunify.ini" 2>/dev/null || true' \
        'fi' \
        'if [ -z "$REDIS_HOST" ] || [ "$REDIS_HOST" = "127.0.0.1" ] || [ "$REDIS_HOST" = "localhost" ]; then' \
        '  echo "Starting local Redis server on port 6379..."' \
        '  redis-server --daemonize yes --dir /tmp --appendonly no --tcp-keepalive 60 --timeout 0 --protected-mode no 2>/dev/null || true' \
        'fi' \
        'php artisan migrate --force' \
        'if [ "$SEED_DEMO_DATA" = "true" ]; then php artisan demo:seed; fi' \
        'php artisan storage:link 2>/dev/null || true' \
        'php artisan docs:postman || true' \
        'php artisan optimize:clear --except=cache 2>/dev/null || true' \
        'php artisan optimize' \
        'php artisan view:cache' \
        'chown -R www-data:www-data storage bootstrap/cache' \
        'exec supervisord -c /etc/supervisor/conf.d/moneyunify.conf' \
        > /usr/local/bin/start-container \
    && chmod +x /usr/local/bin/start-container

EXPOSE 10000

CMD ["start-container"]
