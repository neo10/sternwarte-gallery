# 8.5 hält den Container auf demselben Stand wie das netcup-Webhosting, wo
# 8.5 im Kundenmenü als Standardversion auswählbar ist. Läuft die Galerie
# hier sauber, läuft sie dort ebenso.
FROM php:8.5-cli-bookworm

# gd erzeugt die WebP-Varianten, exif liefert die Ausrichtung von Kamerabildern.
# Ohne gd fällt die Galerie auf das Ausliefern der Originale zurück.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd exif \
    && rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/gallery.ini

WORKDIR /app

EXPOSE 8080

# Nur für die lokale Entwicklung. Im Produktivbetrieb gehört ein richtiger
# Webserver (nginx/Apache) vor PHP-FPM.
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
