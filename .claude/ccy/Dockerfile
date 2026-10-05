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
        php8.5-xdebug \
        php8.5-pcov \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# Composer (copied from the official image, pinned to the 2.x line)
COPY --from=docker.io/library/composer:2 /usr/bin/composer /usr/local/bin/composer

# Composer config: run as root inside the container without warnings
ENV COMPOSER_ALLOW_SUPERUSER=1

# Coverage drivers for php-qa-ci (coverage and Infection mutation testing). Xdebug stays loaded so the
# pipeline detects it, but off by default so ordinary PHP runs at full speed: php-qa-ci sets
# XDEBUG_MODE=coverage itself for the lanes that need coverage.
ENV XDEBUG_MODE=off

# actionlint: lints .github/workflows (expressions, job wiring, and shellcheck over every `run:` block).
# Pinned and checksum-verified; the release system (docs/RELEASING.md) is workflow-heavy.
ARG ACTIONLINT_VERSION=1.7.12
ARG ACTIONLINT_SHA256=8aca8db96f1b94770f1b0d72b6dddcb1ebb8123cb3712530b08cc387b349a3d8
RUN curl -fsSL -o /tmp/actionlint.tgz \
        "https://github.com/rhysd/actionlint/releases/download/v${ACTIONLINT_VERSION}/actionlint_${ACTIONLINT_VERSION}_linux_amd64.tar.gz" && \
    echo "${ACTIONLINT_SHA256}  /tmp/actionlint.tgz" | sha256sum -c - && \
    tar -xzf /tmp/actionlint.tgz -C /usr/local/bin actionlint && \
    rm /tmp/actionlint.tgz

# Verify installations
RUN php --version && \
    composer --version && \
    actionlint -version
