<?php

require  'Medoo.php';
require  'helpers.php';
use Medoo\Medoo;

$domain   = getenv('FAKTUROWNIA_DOMAIN')      ?: '';
$apiToken = getenv('FAKTUROWNIA_API_TOKEN')   ?: '';
$faktPageLimit = getenv('FAKTUROWNIA_INVOICE_PAGE_LIMIT') ?: 1;
$faktDsn  = getenv('FAKTUROWNIADB_DSN')       ?: '';
$faktUsr  = getenv('FAKTUROWNIADB_USER')      ?: '';
$faktPwd  = getenv('FAKTUROWNIADB_PASS')      ?: '';
$optDsn   = getenv('OPTIMADB_DSN')            ?: '';
$optUsr   = getenv('OPTIMADB_USER')           ?: '';
$optPwd   = getenv('OPTIMADB_PASS')           ?: '';
$pathComp = getenv('COMPANIES')               ?: '';  
$fileInvoicesId = getenv('FILE_INVOICES_ID')  ?: 'invoices.txt';
$fileCustomersNIP = getenv('FILE_CUSTOMERS_NIP')  ?: 'customers.txt';

$companies = json_decode(file_get_contents($pathComp), true, flags: JSON_THROW_ON_ERROR);

foreach($companies as $k => $company) {
    if ($company['status'])
        $companies[$company['NIP']] = $company;
    unset($companies[$k]);
}

$dbMySql = new Medoo([
	'pdo' => new PDO($faktDsn, $faktUsr, $faktPwd, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]),
    'type' => 'mysql'
]);

$dbSqlServer = new Medoo([
    'pdo' => new PDO($optDsn,  $optUsr,  $optPwd, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]),
    'type' => 'mssql'
]);
