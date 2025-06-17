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


// foreach($companies as $k => $company) {
//     if ($company['status']) {
//         $dbSqlServer->pdo->exec("USE [{$company['DATABASE']}];");
//         $company["updated_at_1"] = $dbSqlServer->max("CDN.VatNag_fakturownia", 'fakturownia_updated_at', [' fakturownia_income' => 1 ]);
//         $company["updated_at_0"] = $dbSqlServer->max("CDN.VatNag_fakturownia", 'fakturownia_updated_at', [' fakturownia_income' => 0 ]);
//         $companies[$company['NIP']] = $company;
//     }
//     unset($companies[$k]);
// }

$faktury = array();

foreach([0, 1] as $income) {
    $page = 1;
    $ile = 0;
    for ($page = 1; $page <= $faktPageLimit; $page++)
    {
        $url = "https://{$domain}/invoices.json?period=all&api_token={$apiToken}&order=updated_at.desc&per_page=100&page={$page}&income={$income}";
        // dbg($url);
        $json = @file_get_contents($url);
        $json_data = json_decode($json, true);

        foreach($json_data as $faktura) { 
            if (isset($companies[$faktura['seller_tax_no']]))
            {
                $dbSqlServer->pdo->exec("USE [{$companies[$faktura['seller_tax_no']]['DATABASE']}];");

                $updated_at_in_optima = $dbSqlServer->get("CDN.VatNag_fakturownia", 'fakturownia_updated_at', [ 'fakturownia_invoice_id' => $faktura["id"] ]) ?: date('Y-m-d', strtotime($companies[$faktura['seller_tax_no']]['beginning_date']));

                // dbg([ date('Y-m-d H:i:s', strtotime($faktura['updated_at'])), date('Y-m-d H:i:s', strtotime($updated_at_in_optima)) ]);
                if (
                    date('Y-m-d H:i:s', strtotime($faktura['updated_at'])) > date('Y-m-d H:i:s', strtotime($updated_at_in_optima))                              # faktura zaktualizowana
                    && date('Y-m-d', strtotime($faktura['issue_date'])) >= date('Y-m-d', strtotime($companies[$faktura['seller_tax_no']]['beginning_date']))    # faktura w zakresie zainteresowań
                    ) {
                        $faktury[] = $faktura['id'];
                        appendIdIfNew($fileInvoicesId, $faktura['id']);
                    }
            }
        }
    }
}

// dbg($faktury);
