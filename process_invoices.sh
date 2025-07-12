#!/bin/bash
set -euo pipefail

export HOST_UID=$(id -u)
export HOST_GID=$(id -g)

INVOICES="scripts/invoices.txt"
CUSTOMERS="scripts/customers.txt"

docker compose exec php php ostatnie.php

if [[ -s "$INVOICES" ]]; then
    mv $INVOICES $INVOICES.tmp

    while true; do
        if [[ ! -s "$INVOICES.tmp" ]]; then
            break
        fi

        invoice_id=$(head -n1 "$INVOICES.tmp")

        tail -n +2 "$INVOICES.tmp" > "$INVOICES.tmp2"
        mv "$INVOICES.tmp2" "$INVOICES.tmp"
        
        # echo "faktura.php $invoice_id"
        docker compose exec php php faktura.php "$invoice_id"
    done

    rm $INVOICES.tmp
fi

if [[ -s "$CUSTOMERS" ]]; then
    mv $CUSTOMERS $CUSTOMERS.tmp

    while true; do
        if [[ ! -s "$CUSTOMERS.tmp" ]]; then
            break
        fi

        customer_nip=$(head -n1 "$CUSTOMERS.tmp")

        tail -n +2 "$CUSTOMERS.tmp" > "$CUSTOMERS.tmp2"
        mv "$CUSTOMERS.tmp2" "$CUSTOMERS.tmp"

        # echo "kontrahent.php $customer_nip"
        docker compose exec php php kontrahent.php $customer_nip
    done

    rm $CUSTOMERS.tmp
fi


