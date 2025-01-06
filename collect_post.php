<?php
/**
 * Mark all deliveries for a customer as collected
 */

require_once 'autoload.php';

// Set the default timezone
date_default_timezone_set('Europe/London');

// Check for config file
if (is_file('collect_post_config.php') && is_readable('collect_post_config.php')) {
    require_once 'collect_post_config.php';
} else {
    echo "No config.file";
    exit;
}

// open log file
$fp = fopen("./collect_post.log", 'a');

// get request content
$request_body = file_get_contents("php://input");
$request_json = json_decode($request_body);
$coworkerId = strval($request_json->coworker->Id);

//Connect to Nexudus
$nexudusSession = new NexudusSession($nexudusBusinessId, $countryId, $simpleTimeZoneId);

// Get coworker record from Nexudus
$coworkerRecord = $nexudusSession->fetchCustomer($coworkerId);
$fullname = $coworkerRecord['FullName'];

$deliveryList = $nexudusSession->fetchDeliveries($coworkerId);

// Construct array of Ids for items in Delivery array
$arrayOfDeliveryIds = [];
foreach ($deliveryList as $delivery) {
	$arrayOfDeliveryIds[] = $delivery["Id"];
}

// Mark all deliveries in the array as collected
$response = $nexudusSession->runCommandOnDeliveries($arrayOfDeliveryIds, "DELIVERY_COLLECT");
fwrite ($fp, date('Y-m-d\TH:i:s') . ' Marked following deliveries as collected: ' . $arrayOfDeliveryIds . PHP_EOL);

fclose ($fp);

