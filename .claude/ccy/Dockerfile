FROM claude-yolo:latest

# ========================================================================
# PHP 8.5 + Composer
# ========================================================================
# Project: pure-PHP command-line equivalents of jq and yq (PHP 8.5, no
# production dependencies; dev dependencies only, e.g. lts/php-qa-ci).
#
# Debian 12 (bookworm) ships PHP 8.2, so PHP 8.5 comes from the Sury repo
# (packages.sury.org), the standard Debian PHP source.
#
# Extensions: only what Composer and the QA tooling need. The tool itself is
# pure PHP and must not depend on anything beyond core PHP.
# ========================================================================
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg lsb-release && \
    curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/deb.sury.org-php.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
        > /etc/apt/sources.list.d/php.list && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
        php8.5-cli \
        php8.5-curl \
        php8.5-mbstring \
        php8.5-xml \
        php8.5-zip \
        php8.5-intl \
        php8.5-bcmath \
        php8.5-sqlite3 \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# Composer (copied from the official image, pinned to the 2.x line)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Composer config: run as root inside the container without warnings
ENV COMPOSER_ALLOW_SUPERUSER=1

# Verify installations
RUN php --version && \
    composer --version
