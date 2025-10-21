<?php
/**
 * Import jednej faktury z Fakturowni do Optimy.
 * Użycie: php fakturownia_optima.php 12345
 */

require_once __DIR__ . '/lib/init.php';

if ($argc < 2) {
    echo"Użycie: php ".$argv[0]." <invoice_id>\n";
    exit(1);
}
$invoiceId = (int)$argv[1];

$url  = "https://{$domain}/invoices/{$invoiceId}.json?api_token={$apiToken}";
// dbg($url);die;
$json = @file_get_contents($url);
if ($json === false) {
    echo "Nie udało się pobrać faktury z: $url\n";
    exit(1);
}

$inv = json_decode($json, true);
if ($inv['id'] != $invoiceId) {
    echo "Błędna odpowiedź JSON z Fakturowni\n";
    exit(1);
}

$nipArr = splitVatId($inv['seller_tax_no']);
$nip = $nipArr['number'];
if (!$database = $companies[$nip]['DATABASE']) {
    echo "Nie zdefiniowano nazwy bazy w .env dla firmy NIP: {$nip}\n";
    exit(1);
}
// dbg($database);return;
$dbSqlServer->pdo->exec("USE [{$database}];");

$now = Medoo\Medoo::raw('GETDATE()');

$positions = $inv['positions'] ?? [];
foreach ($positions as &$p) {
    $t = strtolower(trim($p['tax'] ?? ''));
    $p['vat_rate'] = in_array($t, ['zw', 'zw.', 'np', 'np.', 'disabled']) ? 0.0 : ((float)$t ?: 23.0);
    $p['vat_flag'] = $p['vat_rate'] == 0.0 ? (in_array($t, ['zw', 'zw.']) ? 1 : 4) : 2;
    $p['optima_code'] = $p['name'];
}
unset($p);

/* ---------- 3. Zapis do Optimy (SQL Server) ---------- */
try {
    $dbSqlServer->pdo->beginTransaction();

    $VatNag = $dbSqlServer->get(
            "CDN.VatNag", 
            ["VaN_VaNID", "VaN_DekID"],
            [ "VaN_AppID" => $inv['id']]
        );


    if ($VatNag && (int)$VatNag['VaN_DekID'] > 0) {           # Faktura istnieje i NIE można jej usunąć (została wysłana do US)
        echo "{$database}: Faktura numer: {$inv['number']} ({$inv['id']}) zablokowana - wysłana do US\n";

        $dbSqlServer->update(
            "CDN.VatNag", 
            [ "VaN_TS_Mod" => date('Y-m-d H:i:s', strtotime($inv['updated_at'])) ],
            [ "VaN_AppID" => $inv['id']]
        );

        $dbSqlServer->pdo->commit();
        exit(0);
    }
    else {                                                    # Faktura do usunięcia, będzie aktualizacja      
        $dbSqlServer->delete('CDN.VatNag', [
                'VaN_AppID' => $inv['id'],
                'VaN_DekID' => null,
            ]);

        $dbSqlServer->query("DELETE FROM CDN.VatTab WHERE VaT_VaNID NOT IN (SELECT VaN_VaNID FROM CDN.VatNag)");
    }    

    /* kontrahent */
    $nip = splitVatId($inv['buyer_tax_no']);
    $nipPelny = $nip['pure'];
    $kntId = (int)$dbSqlServer->get(
        "CDN.Kontrahenci",
        'Knt_KntId',
        ['Knt_Kod' => $nipPelny]
    ) ?: 0;

    /* ---------- 1. Rejestr, kontrahent, duplikat ---------- */
    $income    = !empty($inv['income']);               // true = sprzedaż
    $kind      = $inv['kind'];                         // np. "vat", "proforma"
    $sellerNip = trim($inv['seller_tax_no'] ?? '');    // NipSprzedawcy
    $buyerNip  = splitVatId(trim($inv['buyer_tax_no']  ?? ''));    // NipKlienta
    $clientId  = $inv['client_id'] ?? '';              // IdKlienta (gdy brak NIP)

    // $kindList = ['final', 'vat'];
    $kindList = ['vat', 'correction'];
    // $kindList = [ 'vat' ];
    if (!in_array($kind, $kindList, true)) {
        // dbg($inv);
        echo "{$database}: Faktura {$inv['number']} jest typu {$kind}\n";
        return;                                        // zmieniono rodzaj lub NIP sprzedawcy
    }

    if ($kind == 'correction') {

        $url  = "https://{$domain}/invoices/{$inv['from_invoice_id']}.json?api_token={$apiToken}";
        $json = @file_get_contents($url);
        if ($json === false) {
            echo "Nie udało się pobrać faktury z: $url\n";
            exit(1);
        }

        $invBase = json_decode($json, true);
        if ($invBase['id'] != $inv['from_invoice_id']) {
            echo "Błędna odpowiedź JSON z Fakturowni\n";
            exit(1);
        }

        // dbg($invBase);
        // dbg($inv);
        // dbg($inv['from_invoice_id']);
        // exit;

        // if ($inv['number'] != 'PLKOR/1/08/2025') exit;
    }

    /* wybór rejestru i kontrahenta */
    if ($buyerNip['pure'] !== '' && $buyerNip['country'] != '' && $buyerNip['country'] != 'PL') {
        // zagraniczny NIP (prefiks literowy)  →  RZ_02
        $register = $income ? 'SPRZEDAZ' : 'RZ_02';
        $kntId = (int)$dbSqlServer->get("CDN.Kontrahenci", 'Knt_KntId', ['Knt_Kod' => $buyerNip['pure']]) ?? 0;
    } elseif ($buyerNip['number'] === '') {
        // brak NIP  →  szukamy po telefonSms = IdKlienta
        $register = $income ? 'SPRZEDAZ' : 'RZ_02';
        $kntId = (int)$dbSqlServer->get("CDN.Kontrahenci", 'Knt_KntId', ['Knt_TelefonSms' => $clientId]) ?? 0;
    } else {
        // zwykły krajowy NIP  →  RZ_01
        $register = $income ? 'SPRZEDAZ' : 'RZ_01';
        $kntId = (int)$dbSqlServer->get("CDN.Kontrahenci", 'Knt_KntId', ['Knt_Kod' => $buyerNip['number']]) ?? 0;
    }

    if ($kntId === 0) {
        // dbg($buyerNip);
        echo "{$database}: Brak kontrahenta dla fakturownia.client_id: {$clientId}\n";

        appendIdIfNew($fileInvoicesId, $inv['id']);

        appendIdIfNew($fileCustomersNIP, "{$database} {$clientId}");

        return;
    }
    
    // dbg($inv);
    

    /* sprawdzenie duplikatu (VaNDokument + VaN_PodId) */
    $VaN_VaNID = $dbSqlServer->get("CDN.VatNag", 'VaN_VaNID', [
        'VaN_Dokument'  => $inv['number'],   // NrFaktury
        'VaN_PodId'     => $kntId
    ]);
    // dbg($VaN_VaNID);return;
    if ($VaN_VaNID ?: 0) 
    {
        echo "{$database}: Faktura {$inv['number']} wprowadzona poza integratorem – pomijam.\n";
        return;
    }

    /* ---------- 2. numer kolejny (VaNLp) ---------- */
    $sellDate = $inv['sell_date'] ?: $inv['issue_date'];         // DataSprzedazy  "YYYY-MM-DD"
    $start = date('Y-m-01', strtotime($sellDate)); // 2025-06-01
    $end   = date('Y-m-t',  strtotime($sellDate)); // 2025-06-30
    $lp = ($dbSqlServer->max("CDN.VatNag", 'VaN_Lp', [
            'VaN_Rejestr'                         => $register,
            'VaN_DataObowiazkuPodatkowego[<>]'    => [$start, $end]
        ]) ?: 0) + 1;

    $rokMies = (int)date('Ym', strtotime($sellDate));
    $razemNetto  = $inv['price_net'] * $inv['exchange_rate'];
    $razemVat    = ($inv['price_gross'] - $inv['price_net']) * $inv['exchange_rate'];
    $razemBrutto = $inv['price_gross'] * $inv['exchange_rate'];

    $knt = $dbSqlServer->get(
        "CDN.Kontrahenci", [
            'Knt_Nazwa1','Knt_Nazwa2','Knt_Nazwa3',
            'Knt_Kraj','Knt_Wojewodztwo','Knt_Powiat','Knt_Gmina',
            'Knt_Ulica','Knt_NrDomu','Knt_NrLokalu','Knt_Miasto',
            'Knt_KodPocztowy','Knt_Poczta','Knt_Adres2',
            'Knt_NipKraj','Knt_NipE'
        ], [
            'Knt_KntId' => $kntId
        ]);

    // dbg($knt); dbg($buyerNip);

    if (!isset($knt['Knt_Nazwa1']))
        { dbg($clientId); die; }

    /* ---------- 3. INSERT do VatNag ---------- */
    $dbSqlServer->insert(
        "CDN.VatNag", [
            /* klucze i klasyfikacja */
            'VaN_Typ'           => $income ? 2 : 1,       // 1 = zakup, 2 = sprzedaż
            'VaN_Rodzaj'        => 0,                     
            'VaN_Rejestr'       => $register,
            'VaN_RokMies'       => $rokMies,
            'VaN_Lp'            => $lp,
            'VaN_PodatekVat'    => $income ? 1 : 0,       // 1 = należny, 0 = naliczony
            'VaN_PodmiotTyp'    => 1,
            'VaN_PodID'         => $kntId,

            /* podstawowe daty */
            'VaN_DataObowiazkuPodatkowego' => $sellDate,
            'VaN_DataPrawaOdliczenia'      => $sellDate,
            'VaN_DataZap'                  => $inv['delivery_date'] ?: $inv['sell_date'],        # delivery_date
            'VaN_DataWys'                  => $sellDate,

            /* kursy i deklaracje – integrator ustawia 1/0 */
            'VaN_DataOpe'      => $sellDate,
            'VaN_DataKur'      => $sellDate,
            'VaN_DataKurDoVAT' => $sellDate,
            'VaN_KursDoKsiegowania' => 0,
            'VaN_DeklRokMies'       => 0,
            'VaN_DeklRokMiesKasa'   => 0,
            'VaN_RozliczacVat7'     => 1,
            'VaN_RozliczacVatUE'    => 0,
            'VaN_RozliczacVat27'    => 0,
            'VaN_AutoVat7Kasa'      => 1,
            'VaN_MetodaKasowa'      => 0,
            'VaN_JPK_FA'            => 0,
            'VaN_IdentKsieg'            => '',
            'VaN_IdentKsiegNumeracja'   => null,
            'VaN_IdentKsiegDDfID'       => null,

            /* dokumenty */
            'VaN_Dokument'          => $inv['number'],
            'VaN_DokumentyNadrzedne'=> null,
            'VaN_KorektaDo'         => ($kind == 'correction' ? $invBase['number'] : ""),
            'VaN_Korekta'           => ($kind == 'correction' ? 1 : 0),
            'VaN_Fiskalna'          => 0,
            'VaN_KorektaVAT'        => 0,
            'VaN_Detal'             => 0,
            'VaN_Wewnetrzna'        => 0,

            /* dane kontrahenta (puste – integrator uzupełnia tylko ID) */
            'VaN_KntNazwa1'    => $knt['Knt_Nazwa1'],
            'VaN_KntNazwa2'    => $knt['Knt_Nazwa2'],
            'VaN_KntNazwa3'    => $knt['Knt_Nazwa3'],
            'VaN_KntKraj'      => $knt['Knt_Kraj'],
            'VaN_KntWojewodztwo'=> $knt['Knt_Wojewodztwo'],
            'VaN_KntPowiat'    => $knt['Knt_Powiat'],
            'VaN_KntGmina'     => $knt['Knt_Gmina'],
            'VaN_KntUlica'     => $knt['Knt_Ulica'],
            'VaN_KntNrDomu'    => $knt['Knt_NrDomu'],
            'VaN_KntNrLokalu'  => $knt['Knt_NrLokalu'],
            'VaN_KntMiasto'    => $knt['Knt_Miasto'],
            'VaN_KntKodPocztowy'=> $knt['Knt_KodPocztowy'],
            'VaN_KntPoczta'    => $knt['Knt_Poczta'],
            'VaN_KntAdres2'    => $knt['Knt_Adres2'],
            'VaN_KntNipKraj'   => $knt['Knt_NipKraj'],
            'VaN_KntNipE'      => $knt['Knt_NipE'],
            'VaN_Pesel'           => '',
            'VaN_KntKonto'        => '',
            'VaN_Finalny'         => 0,
            'VaN_Export'          => 0,
            'VaN_MalyPod'         => 0,
            'VaN_Rolnik'          => 0,

            /* płatnik (puste / zero) */
            'VaN_PlatnikTyp'          => 1,
            'VaN_PlatnikID'           => $kntId,
            'VaN_PlatnikRachunekLp'   => null,
            'VaN_PlatnikKraj'         => '',
            'VaN_PlatnikWojewodztwo'  => '',
            'VaN_PlatnikPowiat'       => '',
            'VaN_PlatnikGmina'        => '',
            'VaN_PlatnikUlica'        => '',
            'VaN_PlatnikNrDomu'       => '',
            'VaN_PlatnikNrLokalu'     => '',
            'VaN_PlatnikMiasto'       => '',
            'VaN_PlatnikKodPocztowy'  => '',
            'VaN_PlatnikPoczta'       => '',
            'VaN_PlatnikAdres2'       => '',
            'VaN_PlatnikNazwa1'       => '',
            'VaN_PlatnikNazwa2'       => '',
            'VaN_PlatnikNazwa3'       => '',
            'Van_PlatnikRachunekNr'   => '',

            /* kategorie i statusy */
            'VaN_KatID'        => null,
            'VaN_Kategoria'    => '',
            'VaN_WzID'         => null,
            'VaN_Rozliczono'   => 0,
            'VaN_Zaplacono'    => 0,            
            'VaN_FplID'        => 3,

            /* terminy i wartości */
            'VaN_Termin'         => $inv['payment_to'],
            'VaN_RazemNetto'     => (float)$razemNetto,
            'VaN_RazemNettoDoVAT'=> (float)$razemNetto,
            'VaN_RazemVAT'       => (float)$razemVat,
            'VaN_RazemVATDoVAT'  => (float)$razemVat,
            'VaN_RazemBrutto'    => (float)$razemBrutto,
            'VaN_RazemBruttoDoVAT'=> (float)$razemBrutto,
            'VaN_RazemBruttoWal' => (float)$razemBrutto,
            'VaN_KwotaNKUP'      => 0,
            'VaN_VATNKUP'        => 0,

            /* waluta i kurs */
            'VaN_Waluta'         => '',
            'VaN_WalutaDoVAT'    => '',
            'VaN_KursNumer'      => 3,
            'VaN_KursNumerDoVAT' => 3,
            'VaN_KursL'          => 1,
            'VaN_KursLDoVAT'     => 1,
            'VaN_KursM'          => 1,
            'VaN_KursMDoVAT'     => 1,

            /* zapłata i inne kwoty */
            'VaN_Zaplata'      => 0,
            'VaN_WartoscZak'   => 0,
            'VaN_AkcyzaWegiel' => 0,
            'VaN_AkcyzaWegiel_KolumnaKPR' => 0,

            /* kolejne flagi / pola liczbowe – integrator ustawia 0 */
            'VaN_TrybNettoVAT'    => 0,
            'VaN_CloProc'         => 0,
            'VaN_CloWart'         => 0,
            'VaN_AkcyzaProc'      => 0,
            'VaN_AkcyzaWart'      => 0,
            'VaN_PodImpProc'      => 0,
            'VaN_PodImpWart'      => 0,
            'VaN_SplitPay'        => 0,
            'VaN_OCR'             => 0,
            'VaN_RozliczacOSS'    => 0,
            'VaN_RokOSS'          => 0,
            'VaN_KwartalOSS'      => 0,
            'VaN_DataKurOSS'      => '1900-01-01 00:00:00.000',
            'VaN_WalutaOSS'       => 'EUR',
            'VaN_RazemBruttoOSS'  => 0,
            'VaN_RazemNettoOSS'   => 0,
            'VaN_RazemVATOSS'     => 0,
            'VaN_KursMOSS'        => 1,
            'VaN_KursLOSS'        => 1,
            'VaN_KursNumerOSS'    => 1,
            'VaN_KodKrajuOSS'     => '',
            'VaN_NrKSeF'          => '',
            'VaN_Opis'            => '',

            /* pola techniczne */
            'VaN_TS_Zal'   => $now,
            'VaN_OpeZalID' => null,
            'VaN_StaZalId' => null,
            'VaN_TS_Mod'   => $now,
            'VaN_OpeModId'       => 1,
            'VaN_StaModId'       => 1,
            'VaN_OpeModKod'      => 'ADMIN',
            'VaN_OpeModNazwisko' => 'Administrator',
            'VaN_OpeZalKod'      => 'P001',
            'VaN_OpeZalNazwisko' => 'INTEGRATOR',

            'VaN_AppID'     =>  $inv['id']
        ]);

    $vnId = $dbSqlServer->id();

    echo "{$database}: ✓ Zapisano nagłówek VAT (VaN_VaNID={$vnId}) dla faktury numer: {$inv['number']}\n";

    /* ---------- 4.  Pozycje VAT (VatTab) ---------- */
    $lp = 1;                                    // numeracja pozycji w deklaracji

    foreach ($positions as $p) 
    {
        // dbg($p);
        if ($p['total_price_gross'] == 0 && $p['discount'] == 0) continue;

        // $razemNetto  = $inv['price_net'] * $inv['exchange_rate'];
        // $razemVat    = ($inv['price_gross'] - $inv['price_net']) * $inv['exchange_rate'];
        // $razemBrutto = $inv['price_gross'] * $inv['exchange_rate'];

        $totalNet      = (float)$p['total_price_net'];
        $totalGross    = (float)$p['total_price_gross'];
        $discountNet   = (float)$p['discount_net'];
        $discountGross = (float)$p['discount'];

        $vat_rate = round(((float)$p['vat_rate']) / 100, 2);
        if (abs($discountNet) <= 0.00001 && abs($discountGross) > 0.00001) {
            $discountNet = abs($vat_rate) > 0.00001
                ? round($discountGross / (1 + $vat_rate), 2)
                : round($discountGross, 2);
        }

        if (abs($totalNet) > 0.00001) {
            $vat_rate = round(($totalGross - $totalNet) / $totalNet, 2);
        } elseif (abs($discountNet) > 0.00001) {
            $vat_rate = round(($discountGross - $discountNet) / $discountNet, 2);
        }

        $netto = round(($totalNet - $discountNet) * $inv['exchange_rate'], 2);
        $vat   = round((($totalGross - $discountGross) - ($totalNet - $discountNet)) * $inv['exchange_rate'], 2);

        // dbg([$vat_rate, $netto, $vat]);

        /* stawka symboliczna zgodna z Optimą */
        $stawkaSymbol = $p['vat_rate'] == 0.0
            ? ($p['vat_flag'] == 1 ? 'zw' : 'np')
            : $p['vat_rate'] . '%';

        $dbSqlServer->insert('CDN.VatTab', [
            'VaT_VaNID'        => $vnId,             // FK do nagłówka
            'VaT_Stawka'       => $vat_rate * 100,    // 23.00 / 8.00 / 0.00
            'VaT_Flaga'        => $p['vat_flag'],    // 1=zw, 2=liczb., 4=np
            'VaT_Zrodlowa'     => $vat_rate * 100,    // integrator kopiuje stawkę
            'VaT_RodzajZakupu' => 4,                 // zgodnie z C# (sprzedaż)
            'VaT_Odliczenia'   => 1,                 // brak częściowego odliczania
            'VaT_Netto'        => $netto,
            'VaT_NettoDoVAT'   => $netto,
            'VaT_VAT'          => $vat,
            'VaT_VATDoVAT'     => $vat,
            'VaT_NettoWal'     => $netto,
            'VaT_VATWal'       => $vat,
            'VaT_NettoOSS'     => 0,
            'VaT_VATOSS'       => 0,
            'VaT_KodKrajuOSS'  => '',
            'VaT_KolumnaKPR'   => 1,
            'VaT_KolumnaRYC'   => 0,
            'VaT_Lp'           => $lp++,
            'VaT_KwotaNKUP'    => $netto,
            'VaT_VATNKUP'      => 0,
            'VaT_StawkaSymbol' => $stawkaSymbol,
            'VaT_VaTOrgId'   => null,
            'VaT_KatID'      => null,
            'VaT_KatOpis'    => mb_strlen($p['name'], 'UTF-8') >= 50 ? mb_substr($p['name'], 0, 49, 'UTF-8') : $p['name'],
            'VaT_Kat2ID'     => null,
            'VaT_Kat2Opis'   => null,
            'VaT_Segment1'   => null,
            'VaT_Segment2'   => null,
            'VaT_Segment3'   => null,
            'VaT_Segment4'   => null
        ]);

        $vtId = $dbSqlServer->id();
        echo "{$database}:    ↪ Zapisano pozycję (VaT_VaTID={$vtId}): {$p['name']}\n";
    }
    $dbSqlServer->pdo->commit();
} catch (Throwable $e) {
    $dbSqlServer->pdo->rollBack();
    echo "{$database}: Błąd zapisu do Optimy: {$e->getMessage()}\n";
    exit(1);
}
