#!/bin/bash

export HOST_UID=$(id -u)
export HOST_GID=$(id -g)

# Uruchom na początku, aby zbudować kontener (środowisko uruchomieniowe)
# oraz zainstalować zależności PHP

docker compose build
docker compose up -d

# Install dependencies inside the PHP container if they are not present
if [ ! -d scripts/vendor ]; then
    docker compose exec php composer install --no-interaction
fi

