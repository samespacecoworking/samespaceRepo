<?php
/**
 * Send invoices to Xero
 */

require_once 'autoload.php';

// Set the default timezone
date_default_timezone_set('Europe/London');

// Check for config file
if (is_file('./send_invoices_to_xero_config.php') && is_readable('./send_invoices_to_xero_config.php')) {
    require_once './send_invoices_to_xero_config.php';
} else {
    echo "No config.file";
    exit;
}

//Connect to Nexudus
$nexudusSession = new NexudusSession($nexudusBusinessId, $countryId, $simpleTimeZoneId);

// open log file
$lfp = fopen("./send_invoices_to_xero.log", 'a');

$last_run = time();

//get lastruntime
$fp = fopen("./send_invoices_to_xero_lastruntime.log", 'r+');
$last_run = rtrim(fgets($fp));
fclose($fp);

$invoice_list = $nexudusSession->fetchPaidInvoices($last_run);
fwrite ($lfp, date('Y-m-d\TH:i:s') . ' Got list of paid invoices starting at date ' . $last_run . ' from Nexudus' . PHP_EOL);

$arrayOfInvoiceIds = [];
foreach ($invoice_list as $invoice) {
	if ($invoice["XeroInvoiceTransfered"] === false) {
		$arrayOfInvoiceIds[] = $invoice["Id"];
	}
}

// Send invoices to Xero
$response = $nexudusSession->runCommandOnInvoices($arrayOfInvoiceIds, "TRANSFER_INVOICE_XERO");
fwrite ($lfp, date('Y-m-d\TH:i:s') . ' Sent following invoices to Xero: ' . implode(',', $arrayOfInvoiceIds) . PHP_EOL);

//save lastruntime
$timestamp_now = date('Y-m-d\TH:i:s\Z');

$fp = fopen("./send_invoices_to_xero_lastruntime.log", 'w+');
fwrite($fp, $timestamp_now);
fclose($fp);

fclose ($lfp);

