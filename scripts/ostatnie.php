<?php
/**
 * Pobiera ostatnie faktury z Fakturowni
 * tylko te zmodyfikowane od ostatniego updatu
 * 
 * CREATE TABLE [CDN].[VatNag_fakturownia](
 *	[VaN_VaNID] [int] NOT NULL,
 *	[fakturownia_invoice_id] [bigint] NOT NULL,
 *	[fakturownia_income] [tinyint] NOT NULL,
 *	[fakturownia_updated_at] [datetime] NOT NULL
 * ) ON [PRIMARY]
 * GO
 * 
 */

require_once __DIR__ . '/lib/init.php';

$faktury = array();

foreach([0, 1] as $income) {
    for ($page = 1; $page <= $faktPageLimit; $page++) {
        $url = "https://{$domain}/invoices.json?period=all&api_token={$apiToken}&order=updated_at.desc&per_page=100&page={$page}&income={$income}";
        $url = "https://{$domain}/invoices.json?period=all&api_token={$apiToken}&query=&kinds%5B%5D=vat&kinds%5B%5D=advance&kinds%5B%5D=final&kinds%5B%5D=correction&order=updated_at.desc&per_page=100&page={$page}&income={$income}";
        // $url = "https://{$domain}/invoices.json?period=all&api_token={$apiToken}&query=1PL%2F7%2F05%2F2025&order=updated_at.desc&per_page=100&page={$page}&income={$income}";
        // $url = "https://eengine.fakturownia.pl/invoices.json?period=all&api_token=aMljtq051l4alX2GTNw&query=PL%2F7%2F05%2F2025&order=updated_at.desc&per_page=100&page=1&income=1";

        // dbg($url);
        $json = @file_get_contents($url);
        $json_data = json_decode($json, true);

        foreach($json_data as $faktura) { 
            // dbg($faktura);
            $nipArr = splitVatId($faktura['seller_tax_no']);
            $nip = $nipArr['number'];

            if (isset($companies[$nip]))
            {
                // dbg();
                // dbg($faktura);

                $dbSqlServer->pdo->exec("USE [{$companies[$nip]['DATABASE']}];");

                $updated_at_in_optima = $dbSqlServer->get("CDN.VatNag_fakturownia", 'fakturownia_updated_at', [ 'fakturownia_invoice_id' => $faktura["id"] ]) ?: date('Y-m-d', strtotime($companies[$nip]['beginning_date'])-86400);

                // dbg([ date('Y-m-d H:i:s', strtotime($faktura['updated_at'])), date('Y-m-d H:i:s', strtotime($updated_at_in_optima)) ]);
                if (
                    date('Y-m-d H:i:s', strtotime($faktura['updated_at'])) > date('Y-m-d H:i:s', strtotime($updated_at_in_optima))                              # faktura zaktualizowana
                    && date('Y-m-d', strtotime($faktura['delivery_date'] ?: $faktura['sell_date'])) >= date('Y-m-d', strtotime($companies[$nip]['beginning_date']))    # faktura w zakresie zainteresowań
                    ) {
                        $faktury[] = $faktura['id'];
                        appendIdIfNew($fileInvoicesId, $faktura['id']);
                    }
            }
        }
    }
}

// dbg($faktury);
