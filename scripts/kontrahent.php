<?php
/**
 * Import jednego kontrahenta z Fakturowni do Optimy
 */

require_once __DIR__ . '/lib/init.php';

if ($argc < 3) {
    fwrite(STDERR, "Użycie: php ".$argv[0]." <database> <client_id>\n");
    exit(1);
}
$database = $argv[1];
$client_id = (int)$argv[2];

$url  = "https://{$domain}/clients/{$client_id}.json?api_token={$apiToken}";
// dbg($url);

$json = @file_get_contents($url);
if ($json === false) {
    fwrite(STDERR, "Nie udało się pobrać kontrahekontrahenta z: $url\n");
    exit(1);
}

$inv = json_decode($json, true);

// dbg($inv);

if (!$inv) {
    fwrite(STDERR, "Błędna odpowiedź JSON z Fakturowni\n");
    exit(1);
}

$dbSqlServer->pdo->exec("USE [{$database}];");

$nip = splitVatId($inv['tax_no']);
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
    $kntId, $nip['pure'] ?: mb_substr($inv['name'], 0, 19, 'UTF-8'), '', mb_strlen($inv['name'], 'UTF-8') >= 50 ? mb_substr($inv['name'], 0, 49, 'UTF-8') : $inv['name'], '', '',
    $inv['country'], '', '', '', $inv['street'],
    $inv['street_no'] ?: '', '', $inv['city'], $inv['post_code'], $inv['city'],
    '', $nip['country'], $nip['number'], $nip['number'], '', '',
    '', '', '', 1, date('Y-m-d H:i:s'), 
    '', 0
];

// dbg($params);

$stmt = $dbSqlServer->pdo->prepare($sql);
// dbg($sql);
$kntId_new = $stmt->execute($params);

if ($kntId_new > 0)
{
    $dbSqlServer->update(
            "CDN.Kontrahenci", 
            [ "Knt_TelefonSms" => $client_id ],
            [ "Knt_KntId" => $kntId_new]
        );
}

if ($kntId == 0)
    echo "✓ Utworzono konhtrahenta: NIP: {$nip['pure']}, Nazwa: {$inv['name']}, w bazie: {$database}.\n";
else
    echo "✓ Zaktualizowano konhtrahenta: NIP: {$nip['pure']}, Nazwa: {$inv['name']}, w bazie: {$database}.\n";
