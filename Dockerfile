# Creativo Advertising - PHP + Apache
# Kode asli ditulis untuk PHP 7.3; sudah dites jalan di PHP 8.2.
FROM php:8.2-apache

# Ekstensi mysqli dipakai oleh functions.php
RUN docker-php-ext-install mysqli \
    && a2enmod rewrite headers

# Pakai konfigurasi PHP production, tapi tampilkan error ke log saja (bukan ke browser)
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i 's/^expose_php = .*/expose_php = Off/' "$PHP_INI_DIR/php.ini"

# Hardening session cookie (pelapis tambahan untuk CSRF/XSS) dan header HTTP.
# - HttpOnly: cookie session tidak terbaca JavaScript (mengurangi dampak XSS)
# - SameSite=Lax: cookie tidak ikut dikirim pada POST lintas-situs (pelapis CSRF)
# - strict_mode: tolak session ID yang tidak dikenal server (anti session fixation)
# Flag "Secure" sengaja tidak di-set karena TLS diterminasi di reverse proxy (PHP melihat http).
RUN { \
      echo "session.cookie_httponly=1"; \
      echo "session.cookie_samesite=Lax"; \
      echo "session.use_strict_mode=1"; \
      echo "session.use_only_cookies=1"; \
    } > "$PHP_INI_DIR/conf.d/zz-security.ini" \
    && { \
      echo 'ServerTokens Prod'; \
      echo 'ServerSignature Off'; \
      echo 'Header always set X-Content-Type-Options "nosniff"'; \
      echo 'Header always set X-Frame-Options "SAMEORIGIN"'; \
      echo 'Header always set Referrer-Policy "strict-origin-when-cross-origin"'; \
    } > /etc/apache2/conf-available/zz-hardening.conf \
    && a2enconf zz-hardening

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD curl -fsS http://localhost/login.php >/dev/null || exit 1
