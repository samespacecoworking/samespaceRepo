<?php
/**
 * When a booking is made, check whether the customer has an account in UnifiOS and a PIN. If not, create them and send PIN reminder email.
 */

// include important files
require_once('classes/UnifiSession.php');
require_once('classes/NexudusSession.php');

use UniFi\Session as UniFiSession;

// Set the default timezone
date_default_timezone_set('Europe/London');

// Check for config file
if (is_file('new_booking_config.php') && is_readable('new_booking_config.php')) {
	require_once 'new_booking_config.php';
} else {
	echo "No config.file";
	exit;
}

// get raw content from request

$request_body = file_get_contents("php://input");
$webhookRecord = json_decode($request_body, true)[0];

// Immediately acknowledge the webhook so Nexudus doesn't retry
http_response_code(200);
header('Content-Type: text/plain');
header('Connection: close');
echo 'OK';
ob_flush();
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
ignore_user_abort(true);

$fp = fopen("./new_booking.log", 'a');

// extract fields from JSON
$fullName = $webhookRecord['CoworkerFullName'];
$namearray = explode(' ', $fullName);
$firstName = $namearray[0];
$lastName = end($namearray);
$coworkerId = strval($webhookRecord['CoworkerId']);

// Connect to Unifi
$unifiSession = new UniFiSession();

// Find an Unifi user(s) that with employee number that matches the coworkerId
$existing_users = $unifiSession->fetchAllUsers();
// Cycle through any matching records, if we find one that matches, drop out because we already have a record
foreach ($existing_users as $user) {
	$matching_user = $user["employee_number"];
	if ($matching_user === $coworkerId) {
		fwrite ($fp, date('Y-m-d\TH:i:s') . ' Found existing account for ' . $fullName . ' (' . $coworkerId . ')' . PHP_EOL);
		return;
	}
}

// Get user's email address from Nexudus
$nexudusSession = new NexudusSession($nexudusBusinessId, $countryId, $simpleTimeZoneId);
$coworker = $nexudusSession->fetchCustomer($coworkerId);
$customerEmail = $coworker["Email"];

// Create a new Unifi user record
$newUnifiUser = $unifiSession->registerUser($firstName, $lastName, $customerEmail, $coworkerId, time());
$unifiUserId = $newUnifiUser["data"]["id"];

// Assign new user to the Samespace UDMSE group (ID in config file)
$response = $unifiSession->assignUserToGroup($unifiUserId, $unifiGroupId);
fwrite ($fp, date('Y-m-d\TH:i:s') . ' Unifi Access account created for ' . $fullName . ' (' . $coworkerId . ')' . PHP_EOL);

// Get a PIN from UniFi
$pin = $unifiSession->generatePin()["data"];

// Assign PIN to UniFi user
$response = $unifiSession->assignPin($unifiUserId, $pin);

// Update PIN in Nexudus
$response = $nexudusSession->updatePin($coworkerId, $pin);
fwrite ($fp, date('Y-m-d\TH:i:s') . ' Updated Nexudus PIN for for ' . $fullName . ' (' . $coworkerId . ')' . PHP_EOL);

// Send PIN reminder
$response = $nexudusSession->sendPinReminder($coworkerId);
fwrite ($fp, date('Y-m-d\TH:i:s') . ' Sent PIN email for for ' . $fullName . ' (' . $coworkerId . ') with PIN ' . $pin . PHP_EOL);

