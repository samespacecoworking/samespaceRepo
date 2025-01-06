<?php

// open log file
$fp = fopen("check_in_out.log", 'a');

// Nexudus API credentials
$api_url = 'https://spaces.nexudus.com/api/spaces';
$nexudus_username = getenv('NEXUDUS_USERNAME');
$nexudus_password = getenv('NEXUDUS_PASSWORD');

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

// Get input params
$input = json_decode(file_get_contents('php://input'), true);

$pin = $input['pin'];

// Find coworker record using PIN
$search_url = "$api_url/coworkers?Coworker_AccessPincode=$pin";
$coworkers = make_api_request($search_url)["Records"];

// Check if the results array has exactly one record
if (count($coworkers) === 1) {
    // There's exactly one record, so you can proceed
    $coworker = $coworkers[0];
    $memberName = $coworker['FullName'];
    $coworkerId = $coworker['Id'];
    $success = true;
    fwrite($fp, date('Y-m-d\TH:i:s') . ' User checked in with PIN ' . $pin . ', found name ' . $memberName . ', Id ' . $coworkerId . PHP_EOL);
} else {
    $success = false;
    if (count($results) > 1) {
        fwrite($fp, date('Y-m-d\TH:i:s') . ' Someone tried to check in with PIN ' . $pin . ' but more than one coworker found with this PIN' . PHP_EOL);
    } else {
        fwrite($fp, date('Y-m-d\TH:i:s') . ' Someone tried to check in with PIN ' . $pin . ' but no coworker found with this PIN' . PHP_EOL);
    }
}

echo json_encode(['success' => $success, 'memberName' => $memberName, 'coworkerId' => $coworkerId]);

fclose($fp);

?>
