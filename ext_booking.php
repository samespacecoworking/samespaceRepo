<?php
/**
 * Create a new account and a booking
 */

require_once 'autoload.php';

use UniFi\Session as UniFiSession;

// Check for config file
if (is_file('ext_booking_config.php') && is_readable('ext_booking_config.php')) {
	require_once 'ext_booking_config.php';
} else {
	http_response_code(500);	
	echo json_encode(["error" => "No configuration file found"]);
	exit;
}

// Retrieve and validate parameters
$json = file_get_contents('php://input');
$params = json_decode($json,true);

// Check if decoding was successful
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$errors = [];

// Check 'name'
if (!isset($params['name']) || empty(trim($params['name']))) {
	$errors[] = "The 'name' parameter is required and must be a non-empty string.";
}

// Check 'email'
if (!isset($params['email']) || !filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
	$errors[] = "The 'email' parameter is required and must be a valid email address.";
}

// Check 'startDate'
if (isset($params['startDate']) && is_numeric($params['startDate'])) {
    $startDate = new DateTime("@{$params['startDate']}");
    $startDate->setTimezone(new DateTimeZone('UTC'));
    $currentDate = new DateTime();
    $currentDate->setTime(0, 0); // Normalize to midnight for comparison

    if ($startDate < $currentDate) {
        $errors[] = "The 'startDate' parameter cannot be earlier than today.";
    }
}
if (!isset($params['startDate']) || !is_numeric($params['startDate']) || (int)$params['startDate'] <= 0) {
	$errors[] = "The 'startDate' parameter is required and must be a valid Unix timestamp.";
}

// Check 'endDate'
if (!isset($params['endDate']) || !is_numeric($params['endDate']) || (int)$params['endDate'] <= 0) {
	$errors[] = "The 'endDate' parameter is required and must be a valid Unix timestamp.";
}

// Check 'termsAccepted'
if (!isset($params['termsAccepted']) || !in_array($params['termsAccepted'], [true, false], true)) {
    $errors[] = "The 'termsAccepted' parameter is required and must be a boolean (true or false).";
} else {
    // Convert to a strict boolean for further use
    $termsAccepted = filter_var($params['termsAccepted'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if (!$termsAccepted === true) {
        $errors[] = "The 'termsAccepted' parameter must be a boolean value 'true'.";
    }
}

// Handle errors
if (!empty($errors)) {
	http_response_code(400); // Bad Request
	echo json_encode(['errors' => $errors]);
	exit;
}

$fp = fopen("./ext_booking.log", 'a');

// If validation passes, process the parameters
$name = trim($params['name']);
$email = $params['email'];
$startTimestamp = (int)$params['startDate'];
$endTimestamp = (int)$params['endDate'];
$billing_date = $params['billingDate'] ?? null;

// Extract first and last name from name parameter
$namearray = explode(' ', $name);
$first_name = $namearray[0];
$last_name = end($namearray);

//Connect to Nexudus
$nexudus_session = new NexudusSession($nexudusBusinessId, $countryId, $simpleTimeZoneId);

// Check that email address isn't already registered in Nexudus
$coworkers = $nexudus_session->searchCustomers($email);
if (is_array($coworkers)) {
	$result_count = count($coworkers);
	if ($result_count === 0) {
		fwrite ($fp, date('Y-m-d\TH:i:s') . ' No coworker found with email ' . $email . PHP_EOL);
	} else {
		$coworker = $coworkers[0];
		fwrite ($fp, date('Y-m-d\TH:i:s') . ' Found existing customer ' . $coworker['Id'] . ' with email ' . $email . PHP_EOL);
		http_response_code(409);
		echo json_encode(["error" => "Customer with email " . $email . " is already registered with Samespace."]);
		exit;
	}
} else {
	http_response_code(500);
	echo json_encode(["error" => "searchCustomers didn't return an array."]);
}

// Create new Nexudus user
$newNexudusUser = $nexudus_session->createCustomer($name, $email, $billingEmail, $termsAccepted);
$coworkerId = $newNexudusUser["Value"]["Id"];
$response = $nexudus_session->grantOnlineAccess($coworkerId);

fwrite ($fp, date('Y-m-d\TH:i:s') . ' Nexudus account ' . $coworkerId . ' created for ' . $name . PHP_EOL);

//Connect to UniFI
$unifi_session = new UniFiSession();

// Create new UniFi user
$newUnifiUser = $unifi_session->registerUser($first_name, $last_name, $email, $coworkerId, time());
if ($newUnifiUser["code"] === "CODE_ADMIN_EMAIL_EXIST") {
	fwrite ($fp, date('Y-m-d\TH:i:s') . ' Unifi Access account already exists with email ' . $email . PHP_EOL);
	fclose($fp);
	http_response_code(409);
	echo json_encode(["error" => "Customer with email " . $email . " is already registered with Samespace."]);
	exit;
}
$unifi_user_id = $newUnifiUser["data"]["id"];

// Assign new user to the Samespace UDMSE group (ID in config file)
$response = $unifi_session->assignUserToGroup($unifi_user_id, $unifi_group_id);
fwrite ($fp, date('Y-m-d\TH:i:s') . ' Unifi Access account created for ' . $name . ' (' . $coworkerId . ')' . PHP_EOL);

// Get a PIN from UniFi
$pin = $unifi_session->generatePin()["data"];

// Assign PIN to UniFi user
$response = $unifi_session->assignPin($unifi_user_id, $pin);

// Update PIN in Nexudus
$response = $nexudus_session->updatePin($coworkerId, $pin);
fwrite ($fp, date('Y-m-d\TH:i:s') . ' Updated Nexudus PIN for for ' . $name . ' (' . $coworkerId . ')' . PHP_EOL);

// Check if endDate is earlier than startDate
if ($endTimestamp < $startTimestamp) {
    http_response_code(400); // Bad request
    echo json_encode([
        "error" => "Invalid input: endDate must be later than or equal to startDate."
    ]);
    exit;
}

// Convert timestamps to DateTimes
$startDate = new DateTime("@$startTimestamp");
$endDate = new DateTime("@$endTimestamp");

// Calculate the difference between the dates
$interval = $startDate->diff($endDate);

// Add 1 to include both start and end days
$numberOfDays = $interval->days + 1;

// Check for maximum booking length
if ($numberOfDays > $maxBookingLength) {
    http_response_code(400); // Bad request
    echo json_encode([
        "error" => "Invalid input: booking length is $numberOfDays which exceeds maximum booking length of $maxBookingLength"
    ]);
    exit;
}

// Sell the requisite number of passes
//$coworkerProductId = $nexudus_session->addCoworkerProduct($coworkerId, $dayPassId, $numberOfDays, true, true)["Value"]["Id"];
//$response = $nexudus_session->invoiceCoworkerProduct($coworkerProductId);

// todo: Just add special floe passes until I've worked out how to sell day passes and bill to the sales agent - coworker billing email is static

$coworkerPassIds = $nexudus_session->addCoworkerPasses($coworkerId, $floePassId, $numberOfDays);

// Make the booking
try {
	$bookingRef = $nexudus_session->bookResourceWholeDays($focusSeatedDeskId, $coworkerId, $startDate, $endDate);
	$response = [
		'Message' => 'Success',
		'BookingRef' => $bookingRef,
		'PIN' => "{$pin}",
	];
	http_response_code(200);
	echo json_encode($response);
} catch (Exception $e) {
	http_response_code(500);
	echo json_encode(["error" => $e->getMessage()]);
}

?>
