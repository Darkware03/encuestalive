# =============================================================================
# EncuestaLive — imagen de aplicación (multi-etapa)
# =============================================================================
#
#   *** ESTADO: NO VERIFICADO ***
#
#   Este fichero NUNCA se ha construido ni ejecutado. El host de la fábrica
#   (`c9bb1cd3a76d`) no tiene Docker, ni root, ni sudo (BRIEF §15, D-09), así
#   que `docker build` no se ha podido invocar una sola vez.
#
#   Lo que SÍ se ha hecho: revisión línea a línea contra la documentación
#   oficial de las imágenes base y contra el stack real del proyecto
#   (PHP 8.4.23, Laravel 13.35.0, Livewire 4.4.7, Reverb 1.12.0).
#   Lo que NO se ha hecho: construirlo. Trátese como borrador revisado, no
#   como artefacto probado. Ver DEPLOYMENT.md §9 («Qué falta por verificar»).
#
# Objetivos (`--target`):
#   · `app` — PHP-FPM 8.4. Lo usan los servicios `app`, `reverb` y `scheduler`.
#   · `web` — nginx sin privilegios con `public/` ya horneado dentro.
#
# =============================================================================


# -----------------------------------------------------------------------------
# Etapa 1 · Dependencias de PHP (sin dev)
# -----------------------------------------------------------------------------
FROM composer:2.8 AS vendor

WORKDIR /build

# Copiamos primero solo los manifiestos para aprovechar la caché de capas:
# mientras composer.json/lock no cambien, esta capa no se reconstruye.
COPY composer.json composer.lock ./

# --no-scripts: los scripts de Laravel (`package:discover`) necesitan el código
#   de la aplicación, que todavía no está copiado. Se ejecutan al final.
# --no-dev: phpunit, pint, pail y collision no deben viajar a producción.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --no-progress

# Ahora sí, el código de la aplicación, y el volcado final del autoloader.
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative


# -----------------------------------------------------------------------------
# Etapa 2 · Compilación de assets (Vite + Tailwind 4)
# -----------------------------------------------------------------------------
FROM node:24-alpine AS assets

WORKDIR /build

# =============================================================================
#  TRAMPA DE DESPLIEGUE Nº 1 — LEER ANTES DE CONSTRUIR
# =============================================================================
#  Las variables `VITE_*` NO se leen en tiempo de ejecución: Vite las SUSTITUYE
#  LITERALMENTE dentro del bundle JavaScript durante `npm run build`.
#  Verificado en `resources/js/realtime.js:46-63`, que lee
#  `import.meta.env.VITE_REVERB_APP_KEY/HOST/PORT/SCHEME`.
#
#  Consecuencia práctica: si construyes la imagen con los valores de desarrollo
#  (`127.0.0.1:8080/http`) y la despliegas en `encuestas.example.sv`, el
#  navegador del administrador intentará abrir un WebSocket contra SU PROPIO
#  127.0.0.1 y el tiempo real no funcionará jamás. No habrá ningún error en el
#  servidor: el dashboard simplemente se quedará en el sondeo de 15 s.
#
#  Por eso son ARGUMENTOS DE CONSTRUCCIÓN. Hay que pasarlos al construir:
#
#     docker compose build \
#       --build-arg VITE_REVERB_HOST=encuestas.example.sv \
#       --build-arg VITE_REVERB_PORT=443 \
#       --build-arg VITE_REVERB_SCHEME=https \
#       --build-arg VITE_REVERB_APP_KEY=<la clave pública de Reverb>
#
#  `VITE_REVERB_APP_KEY` es PÚBLICA por diseño (viaja en el bundle). NO es un
#  secreto: `REVERB_APP_SECRET` sí lo es y NO se pasa aquí nunca.
# =============================================================================
ARG VITE_APP_NAME="EncuestaLive"
ARG VITE_REVERB_APP_KEY="encuestalive-local-key"
ARG VITE_REVERB_HOST="127.0.0.1"
ARG VITE_REVERB_PORT="8080"
ARG VITE_REVERB_SCHEME="http"

ENV VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

COPY package.json package-lock.json ./

# --include=dev es OBLIGATORIO: Vite, Tailwind y laravel-vite-plugin son
# devDependencies y sin ellas `npm run build` no existe siquiera. Además, la
# config global de npm de la fábrica trae `omit=dev` (BRIEF §15.2).
RUN npm ci --include=dev --no-audit --no-fund

# Vite necesita las fuentes CSS/JS y el manifiesto de Blade para el refresco.
COPY vite.config.js ./
COPY resources ./resources

RUN npm run build


# -----------------------------------------------------------------------------
# Etapa 3 · Imagen de aplicación (PHP-FPM)
# -----------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS app

# `fcgi` no es un capricho: es lo que permite el health check real de PHP-FPM
# (`cgi-fcgi -bind -connect 127.0.0.1:9000`). Sin él solo se podría comprobar
# que el puerto TCP está abierto, que no es lo mismo que «FPM responde».
RUN apk add --no-cache \
        fcgi \
        tini \
        icu-libs \
        libzip \
        libpng \
        freetype \
        libjpeg-turbo \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        libpng-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        sockets \
        zip \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

# -----------------------------------------------------------------------------
# Usuario sin privilegios. La aplicación NUNCA corre como root.
# UID/GID fijos y altos para que no colisionen con usuarios del host si alguien
# acaba montando un bind mount en una instalación concreta.
# -----------------------------------------------------------------------------
ARG APP_UID=10001
ARG APP_GID=10001
RUN addgroup -g ${APP_GID} -S encuesta \
    && adduser -u ${APP_UID} -G encuesta -S -h /var/www/html -s /sbin/nologin encuesta

WORKDIR /var/www/html

COPY docker/php/php.ini          /usr/local/etc/php/conf.d/zz-encuestalive.ini
COPY docker/php/opcache.ini      /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/www.conf         /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/bin/entrypoint.sh    /usr/local/bin/entrypoint
COPY docker/bin/scheduler.sh     /usr/local/bin/scheduler
COPY docker/bin/health-fpm.sh    /usr/local/bin/health-fpm
COPY docker/bin/health-reverb.sh /usr/local/bin/health-reverb
RUN chmod +x /usr/local/bin/entrypoint \
             /usr/local/bin/scheduler \
             /usr/local/bin/health-fpm \
             /usr/local/bin/health-reverb

# Código de la aplicación con las dependencias ya resueltas, y los assets ya
# compilados. Nada se instala en tiempo de ejecución.
COPY --chown=encuesta:encuesta --from=vendor /build          /var/www/html
COPY --chown=encuesta:encuesta --from=assets /build/public/build /var/www/html/public/build

# `.env` NO se hornea en la imagen. Se inyecta por variables de entorno desde
# el orquestador (BRIEF §12: «no guardar credenciales en el repositorio»).
RUN rm -f /var/www/html/.env /var/www/html/.env.backup \
    && rm -rf /var/www/html/database/database.sqlite \
              /var/www/html/tests \
              /var/www/html/.git \
    && mkdir -p storage/framework/cache/data \
                storage/framework/sessions \
                storage/framework/views \
                storage/framework/testing \
                storage/logs \
                storage/app/private \
                storage/app/public \
                bootstrap/cache \
    && chown -R encuesta:encuesta storage bootstrap/cache \
    && chmod -R u+rwX storage bootstrap/cache

USER encuesta

EXPOSE 9000

# tini como PID 1: recoge procesos zombis y propaga SIGTERM, que es lo que
# necesita `reverb:start` para cerrar las conexiones WebSocket con limpieza.
ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/entrypoint"]
CMD ["php-fpm", "--nodaemonize"]


# -----------------------------------------------------------------------------
# Etapa 4 · Servidor web
# -----------------------------------------------------------------------------
# `nginx-unprivileged` en lugar de `nginx`: el oficial arranca su proceso
# maestro como root. Este corre entero como uid 101 y escucha en 8080, que no
# es un puerto privilegiado. Es la diferencia entre «hay un root en el
# contenedor» y «no lo hay».
FROM nginxinc/nginx-unprivileged:1.27-alpine AS web

USER root
RUN rm -f /etc/nginx/conf.d/default.conf
COPY docker/nginx/encuestalive.conf /etc/nginx/conf.d/encuestalive.conf

# nginx sirve los ficheros estáticos directamente desde disco, así que necesita
# una copia propia de `public/`. Se hornea en la imagen en vez de compartir un
# volumen con el contenedor de PHP: menos superficie, y ningún estado mutable
# compartido entre servicios.
COPY --from=vendor /build/public       /var/www/html/public
COPY --from=assets /build/public/build /var/www/html/public/build
RUN rm -f /var/www/html/public/hot \
    && chown -R 101:101 /var/www/html/public

USER 101

EXPOSE 8080
