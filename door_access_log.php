<?php
/**
 * Check door accesses during the day and check in any customers who hadn't already checked in
 */

// include important files
require_once('classes/UniFiClient.php');

// Set the default timezone
date_default_timezone_set('Europe/London');

// Open log file for append
$fp = fopen("./door_access_log.log", 'a');

fwrite($fp, date('Y-m-d\TH:i:s') . " Starting" . PHP_EOL);

// Check for config file
if (is_file('door_access_log_config.php') && is_readable('door_access_log_config.php')) {
    require_once 'door_access_log_config.php';
    fwrite($fp, date('Y-m-d\TH:i:s') . " Found config file" . PHP_EOL);
} else {
    fwrite($fp, date('Y-m-d\TH:i:s') . " No config file found" . PHP_EOL);
    exit;
}

// initialize the UniFi API connection class, log in to the controller
$unifi_connection = new UniFi_API\Client($controller_user, $controller_password, $controller_url, $site_id, $controller_version, false);
$set_debug_mode   = $unifi_connection->set_debug($debug);
$login            = $unifi_connection->login();
if($login > 400) {
    fwrite($fp, date('Y-m-d\TH:i:s') . " Failed to log into controller" . PHP_EOL);
    die;
} else {
    fwrite($fp, date('Y-m-d\TH:i:s') . " Logged into controller successfully" . PHP_EOL);
}

//  Start of query interval is first argument, or 6am this morning
if (isset($argv[1])) {
    $search_date = $argv[1];   		
    $from_timestamp = $argv[1] . "T01:00:00\Z";
    $to_timestamp = $argv[1] . "T23:00:00\Z";
} else {
    $search_date = date('Y-m-d');
    $from_timestamp = date('Y-m-d\TH:i:s\Z', strtotime("1am"));
    $to_timestamp = date('Y-m-d\TH:i:s\Z', strtotime("11pm"));
}

fwrite($fp, date('Y-m-d\TH:i:s') . " Search timespan is " . $from_timestamp . " to " . $to_timestamp . PHP_EOL);


// Filter to select only inbound door access events
$filter = '(event.type eq "resource.da.open" or event.type eq "access.door.unlock") and (event.display_message eq "Access Granted (PIN CODE)" or event.display_message eq "Access Granted (NFC)")';

// Fetch door access events
$entry_events = $unifi_connection->get_access_events($from_timestamp, $to_timestamp, $filter);

fwrite($fp, date('Y-m-d\TH:i:s') . " Retreived access events" . PHP_EOL);

foreach (array_reverse($entry_events->hits) as $entry_event) {

    $user_id = $entry_event->_source->actor->alternate_id; // We use the 'Alternate ID' field (shown as 'Employee ID' in the web admin) to store the Nexudus user ID
    $user_name = $entry_event->_source->actor->display_name; // Name of user who opened the door
    $entry_timestamp = $entry_event->{'@timestamp'};

    // If the user ID is blank it's because the user has no account in Nexudus, so no point trying to check them in
    if ($user_id == "") {
    fwrite($fp, date('Y-m-d\TH:i:s') . " User " . $user_name . " who entered at " . $entry_timestamp . " has no Alternate ID, wont try to check in." . PHP_EOL);
    continue;
    }
    fwrite($fp, date('Y-m-d\TH:i:s') . " User " . $user_name . " (" . $user_id . ") entered at " . $entry_timestamp . PHP_EOL);

    // Search Nexudus checkins to see if user has already checked in on this day
    $pch = curl_init();
    $url = 'https://spaces.nexudus.com/api/spaces/checkins?Checkin_Coworker=' . $user_id . '&from_Checkin_FromTime=' . $from_timestamp . '&to_Checkin_FromTime=' . $to_timestamp;
    curl_setopt($pch, CURLOPT_URL, $url);
    curl_setopt($pch, CURLOPT_HTTPHEADER, array('Content-Type: x-www-form-urlencoded','Authorization: Basic ' . base64_encode($nexudus_username . ':' . $nexudus_password)));
    curl_setopt($pch, CURLOPT_RETURNTRANSFER, true);
    $get_response = curl_exec($pch);
    curl_close ($pch);
    $json_get_response = json_decode($get_response);
    if ($json_get_response->TotalItems == 0) {
        $pch = curl_init();
        curl_setopt($pch, CURLOPT_URL, 'https://spaces.nexudus.com/api/spaces/coworkers/' . $user_id);
        curl_setopt($pch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Authorization: Basic ' . base64_encode($nexudus_username . ':' . $nexudus_password)));
        curl_setopt($pch, CURLOPT_RETURNTRANSFER, true);
        $get_response = curl_exec($pch);
        curl_close ($pch);

        $business_id = json_decode($get_response)->Businesses[0];

	$payload = '{"CoworkerId": ' . $user_id . ', "BusinessId": ' . $business_id . ', "Source": "DoorAccess", "FromTime": ' . $entry_timestamp . ', "ToTime": ' . $search_date . "T18:00:00Z" . '}';
        // Check user in
        $pch = curl_init();
        curl_setopt($pch, CURLOPT_URL, 'https://spaces.nexudus.com/api/spaces/checkins');
        curl_setopt($pch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Authorization: Basic ' . base64_encode($nexudus_username . ':' . $nexudus_password)));
        curl_setopt($pch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($pch, CURLOPT_POSTFIELDS, '{"CoworkerId": ' . $user_id . ', "BusinessId": ' . $business_id . ', "Source": "DoorAccess", "FromTime": "' . $entry_timestamp . '", "ToTime": "' . $search_date . "T18:00:00Z" . '"}');
        curl_setopt($pch, CURLOPT_RETURNTRANSFER, true);
        $post_response = curl_exec($pch);
	curl_close ($pch);
	fwrite($fp, date('Y-m-d\TH:i:s') . " Checked in user: " . $user_name . " (" . $user_id . ") as of " . $entry_timestamp . PHP_EOL);
    } else {
	fwrite ($fp, date('Y-m-d\TH:i:s') . " User " . $user_name . " (" . $user_id . ") was already checked in." . PHP_EOL);
    }
}

fwrite($fp, date('Y-m-d\TH:i:s') . " Finished" . PHP_EOL);

