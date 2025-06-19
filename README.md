# Integrator Fakturownia ↔ Optima

Projekt pozwala na import faktur oraz kontrahentów z systemu [Fakturownia](https://fakturownia.pl/) do bazy programu [Comarch ERP Optima](https://www.comarch.pl/erp/comarch-optima/).

## Wymagania

* Docker z obsługą `docker compose`
* Dostęp do bazy SQL Server (Optima)
* Klucz API oraz domena w Fakturowni

## Konfiguracja

1. Skopiuj plik `.env` i uzupełnij swoje dane dostępowe:
   - `FAKTUROWNIA_DOMAIN` – adres Twojej instancji w Fakturowni
   - `FAKTUROWNIA_API_TOKEN` – token API
   - `OPTIMADB_DSN`, `OPTIMADB_USER`, `OPTIMADB_PASS` – dane do połączenia z bazą Optimy
   - ścieżki do plików `companies.json`, `invoices.txt`, `customers.txt`
2. Zbuduj oraz uruchom kontener:

```bash
./init.sh
```

## Użycie

* Pobranie ostatnich faktur i utworzenie listy do importu:

```bash
docker compose exec php php ostatnie.php
```

* Import wskazanych faktur oraz kontrahentów do Optimy:

```bash
./process_invoices.sh
```

Skrypty wykorzystują pliki `invoices.txt` oraz `customers.txt` jako kolejkę identyfikatorów do przetworzenia.

Możliwe jest również wywoływanie pojedynczych skryptów z poziomu kontenera, np.:

```bash
docker compose exec php php faktura.php <ID_FAKTURY>
docker compose exec php php kontrahent.php <BAZA> <NIP>
```

## Struktura projektu

```
.
├── Dockerfile
├── docker-compose.yml
├── init.sh           # buduje i uruchamia środowisko
├── process_invoices.sh
└── scripts/
    ├── faktura.php   # import pojedynczej faktury
    ├── kontrahent.php # import kontrahenta
    ├── ostatnie.php  # pobieranie ostatnich faktur
    └── lib/          # biblioteki pomocnicze
```

## Licencja

Projekt udostępniany jest na licencji MIT. Szczegóły w pliku `LICENSE`.
