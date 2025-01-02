<?php

namespace UniFi;

class Session {
	private $apiKey;
	private $apiBase = 'https://unifi.samespace.work:12445/api/v1/developer/';
	private $userGroupId = '51fd8fa6-2521-4960-8b65-817bd28121d3';

	public function __construct($apiKey = null) {
		$this->apiKey = $apiKey ?? getenv('UNIFI_API_KEY');
	}

	private function request($endpoint, $method = 'GET', $data = null) {
		// Generic API request method
	$url = $this->apiBase . $endpoint;
		$headers = ['Authorization: Bearer ' . $this->apiKey];
	$body = json_encode($data);
	// Initialize and configure CURL request here...
	$pch = curl_init();
	curl_setopt($pch, CURLOPT_URL, $url);
	curl_setopt($pch, CURLOPT_CUSTOMREQUEST, $method);
	if ($data) {
		curl_setopt($pch, CURLOPT_POSTFIELDS, $body);
		$headers = [...$headers,'Content-Type: application/json','Content-Length: ' . strlen($body)];
	}
	curl_setopt($pch, CURLOPT_HEADER, 0);
	curl_setopt($pch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($pch, CURLOPT_RETURNTRANSFER, true);
	$response = curl_exec($pch);
	if ($response === false) {
		echo 'cURL error: ' . curl_error($pch);
	}
	curl_close ($pch);
		return json_decode($response, true);
	}

	public function fetchAllUsers() {
		return $this->request('users?page_size=1000&page_num=1')["data"];
	}

	public function generatePin() {
		return $this->request('credentials/pin_codes', 'POST', '');
	}

	public function registerUser($first_name, $last_name, $user_email = null, $employee_number = null, $onboard_time = null) {
		$data = [
			'first_name' => $first_name,
			 'last_name' => $last_name,
			 'user_email' => $user_email,
			 'employee_number' => strval($employee_number),
			 'onboard_time' => $onboard_time,
		];
		return $this->request('users', 'POST', $data);
	}

	public function assignUserToGroup($user_id, $group_id) {
		$data = ["{$user_id}"];
		return $this->request("user_groups/{$group_id}/users", 'POST', $data);
	}

	public function assignPin($id, $pin) {
		$data = ["pin_code" => "{$pin}"];
		return $this->request("users/{$id}/pin_codes", 'PUT', $data);
	}

}

?>
