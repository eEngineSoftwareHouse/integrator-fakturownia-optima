# Integrator Fakturownia ↔ Optima

Projekt pozwala na import faktur oraz kontrahentów z systemu [Fakturownia](https://fakturownia.pl/) do bazy programu [Comarch ERP Optima](https://www.comarch.pl/erp/comarch-optima/).

## Cel projektu i zależności

Integrator pobiera dane z Fakturowni i zapisuje je do bazy Comarch ERP Optima. Środowisko działa w kontenerze wykorzystując obraz `namoshek/php-mssql:8.3-cli` z doinstalowanym `pdo_mysql`.  Biblioteka [Medoo](https://medoo.in) jest instalowana poprzez Composer.


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

3. Zainstaluj zależności PHP (wymagany Composer):

```bash
docker compose exec php composer install
```


## Zmienne środowiskowe (.env)

| Nazwa | Opis |
| ----- | ---- |
| `FAKTUROWNIA_DOMAIN` | adres instancji Fakturowni |
| `FAKTUROWNIA_API_TOKEN` | token API |
| `FAKTUROWNIA_INVOICE_PAGE_LIMIT` | liczba stron pobieranych przez `ostatnie.php` |
| `OPTIMADB_DSN` | DSN do bazy SQL Server |
| `OPTIMADB_USER` | nazwa użytkownika SQL Server |
| `OPTIMADB_PASS` | hasło do SQL Server |
| `COMPANIES` | ścieżka do `companies.json` |
| `FILE_INVOICES_ID` | plik z ID faktur do importu |
| `FILE_CUSTOMERS_NIP` | plik z numerami NIP kontrahentów |
| `FAKTUROWNIADB_DSN`, `FAKTUROWNIADB_USER`, `FAKTUROWNIADB_PASS` | opcjonalne dane do bazy MySQL Fakturowni |

## Użycie
Skrypty uruchamiamy za pośrednictwem `docker compose exec php`.

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

## ToDo

1. DONE - Obsługa faktur walutowych (do weryfikacji)
2. DONE - Tworzenie kontrahentów i ich aktualizacja(?)
3. Aktualizacja danych na fakturach w fakturowni (zapisanie w optimie id faktury i pozycji), zabezpieczenie, aby nie modyfikować już rozliczonych faktur (niebieskie w Optimie). Pole VaN_DekID wiąże fakturę z CDN.DekretyNag i jeżeli istnieje powiązane,
   to należy taką fakturę pozostawić już w spokoju (integrator nie powinien jej dotykać)
4. Przypisywanie kategorii (VaN_KatID, VaN_Kategoria)
5. Obsługa faktur korygujących (VaN_DokumentyNadrzedne, VaN_KorektaDo, VaN_Korekta)

## Changelog

### 0.1

- pierwsza publiczna wersja integratora
