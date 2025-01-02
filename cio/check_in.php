<?php
// check_in.php

// open log file
$fp = fopen("check_in_out.log", 'a');

// Nexudus API credentials
$api_url = 'https://spaces.nexudus.com/api/spaces';
$nexudus_username = getenv('NEXUDUS_USER');
$nexudus_password = getenv('NEXUDUS_PASSWORD');
$business_id = '1414914964';

// Function to make API requests
function make_api_request($url, $method = 'GET', $data = null) {
    global $nexudus_username, $nexudus_password;
    $curl = curl_init();

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    if ($data) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    }
    curl_setopt($curl, CURLOPT_USERPWD, $nexudus_username . ":" . $nexudus_password);

    $response = curl_exec($curl);
    curl_close($curl);

    return json_decode($response, true);
}

// Look for a current checkin for this customer
function isCustomerAlreadyCheckedIn($coworkerId) {
    global $api_url;

    $date = new DateTime(); // Current date and time
    $today = $date->format('Y-m-d') . "T00:00:01"; // One second after midnight

    $checkins_url = "$api_url/checkins?Checkin_Coworker=$coworkerId&from_Checkin_FromTime=$today";
    $checkins = make_api_request($checkins_url)["Records"];

    // Check if any checkin record has a null ToTime value
    foreach ($checkins as $checkin) {
        if (is_null($checkin['ToTime'])) {
            return true;
        }
    }
    
    return false;
}

function checkInCustomer($coworkerId) {
    global $api_url, $business_id, $fp;    
    
    $nowutc = gmdate('Y-m-d\TH:i:s\Z');
    $post_data = array(
	'BusinessId' => $business_id,
	'CoworkerId' => $coworkerId,
	'Source' => 'Manual',
	'FromTime' => $nowutc
    );

    fwrite($fp, date('Y-m-d\TH:i:s') . ' Checking in using this data:' . json_encode($post_data) . PHP_EOL);
    $response = make_api_request("$api_url/checkins",'POST',$post_data);

    return $response;
}

// Get input params
$input = json_decode(file_get_contents('php://input'), true);

$coworkerId = $input['id'];

if (isCustomerAlreadyCheckedIn($coworkerId)) {
    http_response_code(409); // Set the response code to 409 Conflict
    echo json_encode(['success' => false, 'error' => 'already_checked_in']);
    exit();
}

// Check the customer in
$success = checkInCustomer($coworkerId);

if ($success) {
	echo json_encode(['success' => true]);
	fwrite ($fp, date('Y-m-d\TH:i:s') . ' Coworker ' . $coworkerId  . ' checked in manually' . PHP_EOL);
} else {
    http_response_code(500); // Internal Server Error for any other issues
    echo json_encode(['success' => false, 'error' => 'internal_error']);
}

fclose($fp);

?>
