<?php
/**
 * Buy a hot drink
 */

require_once 'autoload.php';

// Set the default timezone
date_default_timezone_set('Europe/London');

// Check for config file
if (is_file('buy_hot_drink_config.php') && is_readable('buy_hot_drink_config.php')) {
	require_once 'buy_hot_drink_config.php';
} else {
	http_response_code(500);	
	echo "No config.file";
	exit;
}

// get request content
$request_body = file_get_contents("php://input");
$request_json = json_decode($request_body);

// open log file
$fp = fopen("./buy_hot_drink.log", 'a');

// extract fields from JSON
$coworkerId = strval($request_json->coworker->Id);
$tileNumber = $request_json->tileNumber;

//Connect to Nexudus
$nexudus_session = new NexudusSession($businessId, $countryId, $simpleTimeZoneId);

// Get coworker record from Nexudus
$coworker_record = $nexudus_session->fetchCustomer($coworkerId);
$fullname = $coworker_record['FullName'];
$billing_day = $coworker_record['BillingDay'];

// Add product 

$productSold = $nexudus_session->addCoworkerProduct($coworkerId, $productId, 1, true, false);
fwrite ($fp, date('Y-m-d\TH:i:s') . ' Sold product ' . $productId . ' (' . $productSold["Value"]["ProducName"] . ') to Coworker ' . $fullname . ' (' . $coworkerId . ')' . PHP_EOL);

// If the coworker is a contact (not a member), schedule the invoice for that evening
if (is_null($billing_day)) { 
	
	// Construct next auto invoice timestamp
	$invoice_date = date('Y-m-d\TH:i:s\Z', strtotime("this Saturday 11pm"));
	$response = $nexudus_session->updateBillingDate($coworkerId, $invoice_date);

	fwrite($fp, date('Y-m-d\TH:i:s') . ' Set next invoice date for ' . $fullname . ' (' . $coworkerId . ') to be ' . $invoice_date . PHP_EOL);
}
else {
	fwrite ($fp, date('Y-m-d\TH:i:s') . ' Product for ' . $fullname . ' (' . $coworkerId . ') will be invoiced on day ' . $billing_day . ' of next month' . PHP_EOL);
}

fclose ($fp);

