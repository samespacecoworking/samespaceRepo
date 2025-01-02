<?php

use DateTime;
use DateTimeZone;
use DateInterval;
use DatePeriod;
use Exception;

class NexudusSession {
	private $apiUsername;
	private $apiPassword;
	private $apiBase = 'https://spaces.nexudus.com/api/';

	public function __construct($businessId, $countryId, $simpleTimeZoneId) {
		$this->apiUsername = getenv('NEXUDUS_USERNAME');
		$this->apiPassword = getenv('NEXUDUS_PASSWORD');
		$this->businessId = $businessId;
		$this->countryId = $countryId;
		$this->simpleTimeZoneId = $simpleTimeZoneId;
	}

	private function request($endpoint, $method = 'GET', $data = null) {
		// Generic API request method
		$url = $this->apiBase . $endpoint;
		$headers = ['Authorization: Basic ' . base64_encode($this->apiUsername . ':' . $this->apiPassword)];
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

	// Fetch UK public holidays from gov.uk API
	private function fetchUKPublicHolidays() {
		$apiUrl = 'https://www.gov.uk/bank-holidays.json';
		$response = file_get_contents($apiUrl);
		if ($response === FALSE) {
			throw new Exception("Failed to fetch public holidays data.");
		}
		$data = json_decode($response, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception("Invalid JSON response.");
		}
		return $data['england-and-wales']['events'];
	}

	public function searchCustomers($email) {
		return $this->request('spaces/coworkers?Coworker_Email=' . $email)["Records"];
	}

	public function fetchCustomer($coworkerId) {
		return $this->request('spaces/coworkers/' . $coworkerId);
	}

	// Create a new customer
	public function createCustomer($fullname, $email, $billing_email = null, $termsAccepted = false) {
		$data = [
			'FullName' => $fullname,
			'Email' => $email,
			'CountryId' => $this->countryId,
			'SimpleTimeZoneId' => $this->simpleTimeZoneId,
			'BillingEmail' => $billing_email,
			'GeneralTermsAccepted' => $termsAccepted,
		];
		return $this->request('spaces/coworkers','POST',$data);
	}


	// Update a customer's billing date
	public function updateBillingDate($id, $billingDate) {
		// Get coworker record
		$coworker = $this->request("coworkers/{$id}");

		// Update NextAutoInvoice (billing date) field in customer record
		$coworker['NextAutoInvoice'] = $billingDate;
		return $this->request('spaces/coworkers','PUT',$coworker);
	}

	// Update a customer's access PIN
	public function updatePin($id, $pin) {
		// Get coworker record
		$coworker = $this->request("spaces/coworkers/{$id}");

		// Update pin field in customer record
		$coworker['AccessPincode'] = $pin;
		return $this->request('spaces/coworkers','PUT',$coworker);
	}

	// Send customer PIN reminder email
	public function sendPinReminder($coworkerId) {
		$data = [
			'Ids' => ["$coworkerId"],
			'Key' => 'COWORKER_PIN_REMINDER',
		];
		return $this->request('spaces/coworkers/runcommand','POST',$data);
	}

	// Grant access for a coworker to the customer portal
	public function grantOnlineAccess($coworker_id) {
		$data = [
			'Ids' => ["$coworker_id"],
			'Key' => 'COWORKER_NEW_USER',
		];
		return $this->request('spaces/coworkers/runcommand','POST',$data);
	}

	// Revoke access for a coworker to the customer portal
	public function revokeOnlineAccess($coworker_id) {
		$data = [
			'Ids' => ["$coworker_id"],
			'Key' => 'COWORKER_REMOVE_USER',
		];
		return $this->request('spaces/coworkers/runcommand','POST',$data);
	}

	// Sell a product to a coworker
	public function addCoworkerProduct($coworkerId, $productId, $quantity = 1, $activateNow = false, $invoiceNow = false) {
		$data = [
			"CoworkerId" => $coworkerId,
			"BusinessId" => $this->businessId,
			"ProductId" => $productId,
			"Quantity" => $quantity,
			"ActivateNow" => $activateNow,
			"InvoiceThisCoworker" => $invoiceNow,
		];
		return $this->request('billing/coworkerproducts','POST',$data);
	}

	// Invoice a product which has been sold to a coworker
	public function invoiceCoworkerProduct($coworkerProductId) {
		$data = [
			'Ids' => ["$coworkerProductId"],
			'Key' => 'COWORKER_PRODUCT_INVOICE',
		];
		return $this->request('billing/coworkerproducts/runcommand','POST',$data);
	}

	// Sell a product to a coworker
	public function addCoworkerPasses($coworkerId, $timePassId, $quantity = 1) {
		// Create a DateTime object for the current date and time
		$expireDate = new DateTime();

		// Add 12 months to the current date
		$expireDate->modify('+12 months');

		// Set the timezone to UTC
		$expireDate->setTimezone(new DateTimeZone('UTC'));

		// Format the date as YYYY-MM-DDTHH:MM:SSZ
		$formattedDate = $expireDate->format('Y-m-d\TH:i:s\Z');

		$data = [
			"CoworkerId" => $coworkerId,
			"BusinessId" => $this->businessId,
			"TimePassId" => $timePassId,
			"CreateMultiple" => $quantity,
			"ExpireDate" => "${formattedDate}"
		];
		return $this->request('billing/coworkertimepasses','POST',$data);
	}

	private function createBooking($resourceId, $coworkerId, $fromTime, $toTime) {
		$data = [
			'resourceId' => $resourceId,
			'coworkedId' => $coworkerdId,
			'FromTime' => $fromTime,
			'ToTime' => $toTime,
		];
		return $this->request('spaces/bookings','POST',$data);
	}

	public function bookResourceWholeDays($resourceId, $coworkerId, $fromDateTime, $toDateTime) {

		if ($fromDateTime > $toDateTime) {
			throw new Exception("From date cannot be later than To date");
		}
		
		// Set the time zone to UTC for proper formatting
		$fromDateTime->setTimezone(new DateTimeZone('UTC'));
		$toDateTime->setTimezone(new DateTimeZone('UTC'));

		// Define booking time for each day
		$startTime = '08:00:00';
		$endTime = '18:00:00';

		// Fetch public holidays
		$publicHolidays = $this->fetchUKPublicHolidays();
		$holidayDates = [];
		foreach ($publicHolidays as $holiday) {
			$holidayDates[] = $holiday['date'];
		}

		// Iterate over each day in the range
		$interval = new DateInterval('P1D'); // 1-day interval
		$dateRange = new DatePeriod($fromDateTime, $interval, $toDateTime->modify('+1 day')); // Include end date

		$bookingRef = null;
		foreach ($dateRange as $date) {
			$dayOfWeek = $date->format('N'); // 1 (Monday) to 7 (Sunday)
			$currentDate = $date->format('Y-m-d'); // Format date as 'YYYY-MM-DD'

			// Check if it's a weekday and not a public holiday
			if ($dayOfWeek < 6 && !in_array($currentDate, $holidayDates)) {
				
				//Construct the start and end datetime strings in Nexudus-compatible format
				$startDateTime = "{$currentDate}T{$startTime}Z";
				$endDateTime = "{$currentDate}T{$endTime}Z";

				// Construct booking parameters for the API
				$data = [
					'CoworkerId' => $coworkerId,
					'ResourceId' => $resourceId,
					'FromTime' => $startDateTime,
					'ToTime' => $endDateTime,
				];
				
				$booking = $this->request('spaces/bookings','POST',$data);
				if (!isset($bookingRef)) {
					$bookingRef = $booking["Value"]["Id"];
				}
			}
		}

		return $bookingRef;
	}

	public function getCurrentCheckins($fromDateTime = null) {
		// Set default value for $fromDateTime to midnight today if not provided
		if ($fromDateTime === null) {
			$fromDateTime = (new DateTime())->setTime(0, 0)->format('Y-m-d\TH:i:s\Z');
		}

		$checkins = $this->request("spaces/checkins?from_Checkin_FromTime=$fromDateTime")["Records"];

		$array_of_checkins = [];

		foreach ($checkins as $checkin) {
			if (is_null($checkin['ToTime'])) {
			    $coworker_id = $checkin["CoworkerId"];
			    $coworker_company = $this->request("spaces/coworkers/$coworker_id")["CompanyName"];
			    $array_of_checkins[] = ['id' => $coworker_id, 'name' => $checkin["CoworkerFullName"], 'company' => $coworker_company];
			} 
		}
		//var_dump($array_of_checkins);
		return $array_of_checkins;
	}

}

?>
