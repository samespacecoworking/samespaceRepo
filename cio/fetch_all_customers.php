<?php
header('Content-Type: application/json');

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

// Fetch customer data from Nexudus API
$customers_url = "$api_url/coworkers?size=500";
$customers = make_api_request($customers_url);

if (empty($customers) || empty($customers['Records'])) {
    echo json_encode(['customers' => []]);
    exit;
}

// Format the customer data
$customer_list = array_map(function($customer) {
    return [
        'id' => $customer['Id'],
	'name' => $customer['FullName'],
	'company' => $customer['CompanyName']
    ];
}, $customers['Records']);

echo json_encode($customer_list);

?>
