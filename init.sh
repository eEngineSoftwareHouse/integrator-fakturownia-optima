#!/bin/bash

# Uruchom na początku, aby zbudować kontener (środowisko uruchomieniowe)

docker compose build
docker compose up -d

# Install dependencies inside the PHP container if they are not present
if [ ! -d scripts/vendor ]; then
    docker compose exec php composer install
fi
