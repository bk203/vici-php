FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

# Base tooling + strongSwan (charon ships the VICI plugin enabled by default).
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        locales \
        software-properties-common \
        unzip \
        libcharon-extra-plugins \
        libstrongswan-extra-plugins \
        strongswan \
        strongswan-swanctl \
        strongswan-pki \
 && locale-gen C.UTF-8 \
 && rm -rf /var/lib/apt/lists/*

# PHP 8.4 via the Ondrej PPA (works across Ubuntu LTS releases; no-op if
# 8.4 is already preferred by the base image).
RUN add-apt-repository -y ppa:ondrej/php \
 && apt-get update \
 && apt-get install -y --no-install-recommends \
        php8.4-cli \
        php8.4-xml \
        php8.4-mbstring \
        php8.4-sockets \
        php8.4-pcov \
 && update-alternatives --set php /usr/bin/php8.4 \
 && rm -rf /var/lib/apt/lists/*

# Composer (pinned to latest 2.x phar from getcomposer.org).
RUN curl -fsSL https://getcomposer.org/installer | php -- \
        --install-dir=/usr/local/bin --filename=composer \
 && composer --version

# Runtime directories charon expects; keep swanctl config dir present so
# `charon-systemd` doesn't complain on startup.
RUN mkdir -p /run/strongswan /var/run /etc/swanctl/conf.d

COPY docker/strongswan.conf /etc/strongswan.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["bash"]
