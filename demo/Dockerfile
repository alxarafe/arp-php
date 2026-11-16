FROM composer:2@sha256:0c8e663f14c94d07b3c45b175c51f5a14718508f21fe4dd7e54f09488dd0ed71 AS composer
FROM php:8.5-cli-bookworm@sha256:050713ce008e2360ea9dc96e1f9a075690132388d917fc3552806f7a7be6f2a0
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock contract.ref ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-plugins --no-scripts
COPY src ./src
COPY tests ./tests
COPY demo/router.php ./demo/router.php
COPY demo/start ./demo/start
CMD ["sh", "/app/demo/start"]