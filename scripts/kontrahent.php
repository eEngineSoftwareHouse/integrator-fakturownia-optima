<?php
/**
 * Import jednego kontrahenta z Fakturowni do Optimy
 */

require_once __DIR__ . '/lib/init.php';

if ($argc < 3) {
    fwrite(STDERR, "Użycie: php ".$argv[0]." <database> <NIP>\n");
    exit(1);
}
$database = $argv[1];
$NIP = $argv[2];

// print "> {$database} <> {$NIP} <\n";

$url  = "https://{$domain}/clients.json?api_token={$apiToken}&tax_no={$NIP}";
// dbg($url);

$json = @file_get_contents($url);
if ($json === false) {
    fwrite(STDERR, "Nie udało się pobrać faktury z: $url\n");
    exit(1);
}

$inv = json_decode($json, true);

if (!$inv[0]) {
    fwrite(STDERR, "Błędna odpowiedź JSON z Fakturowni\n");
    exit(1);
}

// dbg($inv[0]);

$dbSqlServer->pdo->exec("USE [{$database}];");

$nip = splitVatId($inv[0]['tax_no']);
$nipPelny = $nip['pure'];
$kntId = (int)$dbSqlServer->get(
    "CDN.Kontrahenci",
        'Knt_KntId',
        ['Knt_Kod' => $nipPelny]
    ) ?: 0;

$sql = "EXEC CDN.Med_DodajKontrahenta
        @OptimaId=? , @Kod=? , @EAN=? , @Nazwa1=? , @Nazwa2=? , @Nazwa3=? ,
        @Kraj=? , @Wojewodztwo=? , @Powiat=? , @Gmina=? , @Ulica=? ,
        @NrDomu=? , @NrLokalu=? , @Miasto=? , @KodPocztowy=? , @Poczta=? ,
        @Adres2=? , @NipKraj=? , @NipE=? , @Nip=? , @Regon=? , @Pesel=? ,
        @Telefon=? , @Opis=? , @OsobaOdbierajaca=? , @OpeID=? , @TS_Mod=? ,
        @KodGrupyKnt=? , @StanDetalID=?";

$params = [
    $kntId, $nip['pure'], '', mb_strlen($inv[0]['name'], 'UTF-8') >= 50 ? mb_substr($inv[0]['name'], 0, 49, 'UTF-8') : $inv[0]['name'], '', '',
    $inv[0]['country'], '', '', '', $inv[0]['street'],
    $inv[0]['street_no'] ?: '', '', $inv[0]['city'], $inv[0]['post_code'], $inv[0]['city'],
    '', $nip['country'], $nip['number'], $nip['number'], '', '',
    '', '', '', 1, date('Y-m-d H:i:s'), 
    '', 0
];

$stmt = $dbSqlServer->pdo->prepare($sql);
$stmt->execute($params);

if ($kntId == 0)
    echo "✓ Utworzono konhtrahenta: NIP: {$nip['pure']}, Nazwa: {$inv[0]['name']}, w bazie: {$database}.\n";
else
    echo "✓ Zaktualizowano konhtrahenta: NIP: {$nip['pure']}, Nazwa: {$inv[0]['name']}, w bazie: {$database}.\n";
