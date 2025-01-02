<?php
/**
 * Send invoices to Xero
 */

// Set the default timezone
date_default_timezone_set('Europe/London');

// Check for config file
if (is_file('./send_invoices_to_xero_config.php') && is_readable('./send_invoices_to_xero_config.php')) {
    require_once './send_invoices_to_xero_config.php';
} else {
    echo "No config.file";
    exit;
}

// open log file
$lfp = fopen("./send_invoices_to_xero.log", 'a');

$last_run = time();

//get lastruntime
$fp = fopen("./send_invoices_to_xero_lastruntime.log", 'r+');
$last_run = rtrim(fgets($fp));
//substr_replace($last_run,"\n\r",-1);
fclose($fp);

// Get invoices from Nexudus
$gch = curl_init();
curl_setopt($gch, CURLOPT_URL, 'https://spaces.nexudus.com/api/billing/coworkerinvoices?size=1000&CoworkerInvoice_CreditNote=false&CoworkerInvoice_Paid=true&CoworkerInvoice_Refunded=false&from_CoworkerInvoice_PaidOn=' . $last_run);
curl_setopt($gch, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . base64_encode($nexudus_username . ':' . $nexudus_password)));
curl_setopt($gch, CURLOPT_HEADER, 0);
curl_setopt($gch, CURLOPT_RETURNTRANSFER, true);
$get_response = curl_exec($gch);
curl_close ($gch);

fwrite ($lfp, date('Y-m-d\TH:i:s') . ' Got list of paid invoices starting at date ' . $last_run . ' from Nexudus' . PHP_EOL);

$invoice_list = json_decode($get_response)->Records;
$array_of_invoice_ids = '[';

foreach ($invoice_list as $invoice) {
	if ($invoice->XeroInvoiceTransfered === false) {
		if ($array_of_invoice_ids != '[') {
			$array_of_invoice_ids .= ',';
		}
		$array_of_invoice_ids .= $invoice->Id;
	}
}
$array_of_invoice_ids .= ']';

// Send invoices to Xero
$pch = curl_init();
curl_setopt($pch, CURLOPT_URL, 'https://spaces.nexudus.com/api/billing/coworkerinvoices/runcommand');
curl_setopt($pch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Authorization: Basic ' . base64_encode($nexudus_username . ':' . $nexudus_password)));
curl_setopt($pch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($pch, CURLOPT_POSTFIELDS, '{"Ids":' . $array_of_invoice_ids . ',"Key": "TRANSFER_INVOICE_XERO"}');
$put_response = curl_exec($pch);
curl_close ($pch);

fwrite ($lfp, date('Y-m-d\TH:i:s') . ' Sent following invoices to Xero: ' . $array_of_invoice_ids . PHP_EOL);

//save lastruntime
$timestamp_now = date('Y-m-d\TH:i:s\Z');

$fp = fopen("./send_invoices_to_xero_lastruntime.log", 'w+');
fwrite($fp, $timestamp_now);
fclose($fp);

fclose ($lfp);

