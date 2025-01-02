<?php
/**
 * Collect a delivery
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
$nexudus_session = new NexudusSession($businessId, $countryId, $simpleTimeZoneId);

// Get coworker record from Nexudus
$coworker_record = $nexudus_session->fetchCustomer($coworkerId);
$fullname = $coworker_record['FullName'];

// Get deliveries from Nexudus
$gch = curl_init();
curl_setopt($gch, CURLOPT_URL, 'https://spaces.nexudus.com/api/spaces/coworkerdeliveries?size=1000&CoworkerDelivery_Collected=false&CoworkerDelivery_Coworker=' . $coworkerId);
curl_setopt($gch, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . base64_encode($nexudus_username . ':' . $nexudus_password)));
curl_setopt($gch, CURLOPT_HEADER, 0);
curl_setopt($gch, CURLOPT_RETURNTRANSFER, true);
$get_response = curl_exec($gch);
curl_close ($gch);

fwrite ($fp, date('Y-m-d\TH:i:s') . ' Got list of uncollected post for user ' . $coworkerId . ' (' . $fullname . ')' . PHP_EOL);
$delivery_list = json_decode($get_response)->Records;

// Construct array of Ids for items in Delivery array
$array_of_delivery_ids = '[';
foreach ($delivery_list as $delivery) {
	if ($array_of_delivery_ids != '[') {
		$array_of_delivery_ids .= ',';
	}
	$array_of_delivery_ids .= $delivery->Id;
}
$array_of_delivery_ids .= ']';

// Mark all deliveries in the array as collected
$pch = curl_init();
curl_setopt($pch, CURLOPT_URL, 'https://spaces.nexudus.com/api/spaces/coworkerdeliveries/runcommand');
curl_setopt($pch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Authorization: Basic ' . base64_encode($nexudus_username . ':' . $nexudus_password)));
curl_setopt($pch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($pch, CURLOPT_POSTFIELDS, '{"Ids":' . $array_of_delivery_ids . ',"Key": "DELIVERY_COLLECT"}');
$put_response = curl_exec($pch);
curl_close ($pch);

fwrite ($fp, date('Y-m-d\TH:i:s') . ' Marked following deliveries as collected: ' . $array_of_delivery_ids . PHP_EOL);

fclose ($fp);

