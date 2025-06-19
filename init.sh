#!/bin/bash

# Uruchom na początku, aby zbudować kontener (środowisko uruchomieniowe)
# oraz zainstalować zależności PHP

docker compose build
docker compose up -d
docker compose exec php composer install --no-interaction
