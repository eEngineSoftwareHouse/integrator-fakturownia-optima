#!/bin/bash

# Ensure Docker Compose has information about the invoking user
export UID=${UID:-$(id -u)}
export GID=${GID:-$(id -g)}

# Uruchom na początku, aby zbudować kontener (środowisko uruchomieniowe)
# oraz zainstalować zależności PHP

docker compose build
docker compose up -d

# Install dependencies inside the PHP container if they are not present
if [ ! -d scripts/vendor ]; then
    docker compose exec php composer install --no-interaction
fi

