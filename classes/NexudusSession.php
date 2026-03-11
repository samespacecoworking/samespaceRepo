<?php

class NexudusSession {
	private $apiUsername;
	private $apiPassword;
	private $apiBase = 'https://spaces.nexudus.com/api/';

	public function __construct($businessId, $countryId, $simpleTimeZoneId, $debug = false) {
		$this->apiUsername = getenv('NEXUDUS_USERNAME');
		$this->apiPassword = getenv('NEXUDUS_PASSWORD');
		$this->businessId = $businessId;
		$this->countryId = $countryId;
		$this->simpleTimeZoneId = $simpleTimeZoneId;
		$this->debug = $debug;
	}

	private function request($endpoint, $method = 'GET', $data = null) {
		// Generic API request method
		$url = $this->apiBase . $endpoint;
		$debug = $this->debug;

		$headers = ['Authorization: Basic ' . base64_encode($this->apiUsername . ':' . $this->apiPassword)];
		$body = json_encode($data);
		
		// Initialize and configure CURL request here...
		$pch = curl_init();
		if ($debug === true) {
			curl_setopt($pch, CURLOPT_VERBOSE, true); 
			$verboseLog = fopen('php://temp', 'w+');
			curl_setopt($pch, CURLOPT_STDERR, $verboseLog);
		}
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

		if ($debug === true) {
			// Log cURL verbose output
			rewind($verboseLog);
			$verboseOutput = stream_get_contents($verboseLog);
			fclose($verboseLog);
			error_log("cURL verbose output:\n" . $verboseOutput);
		}

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

	public function fetchDeliveries($coworkerId) {
		return $this->request('spaces/coworkerdeliveries?size=1000&CoworkerDelivery_Collected=false&CoworkerDelivery_Coworker=' . $coworkerId)["Records"];
	}

	public function runCommandOnDeliveries($arrayOfDeliveryIds, $command) {
		$data = [
			'Ids' => $arrayOfDeliveryIds,
			'Key' => "$command",
		];
		return $this->request('spaces/coworkerdeliveries/runcommand', 'POST', $data);
	}

	public function fetchPaidInvoices($startDateTime) {
		return $this->request('billing/coworkerinvoices?size=1000&CoworkerInvoice_CreditNote=false&CoworkerInvoice_Paid=true&CoworkerInvoice_Refunded=false&from_CoworkerInvoice_PaidOn=' . $startDateTime)["Records"];
	}

	public function runCommandOnInvoices($arrayOfInvoiceIds, $command) {
		$data = [
			'Ids' => $arrayOfInvoiceIds,
			'Key' => "$command",
		];
		return $this->request('billing/coworkerinvoices/runcommand', 'POST', $data);
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
		$coworker = $this->request("spaces/coworkers/{$id}");
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

	// Authenticate a coworker via the Nexudus bearer token endpoint
	public function authenticateCoworker($email, $password) {
		$url = $this->apiBase . 'token';
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/x-www-form-urlencoded',
		]);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
			'grant_type' => 'password',
			'username' => $email,
			'password' => $password,
		]));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpCode !== 200) {
			return null;
		}
		return json_decode($response, true);
	}

	// Get a coworker's unused time passes
	public function getUnusedPasses($coworkerId) {
		return $this->request(
			'billing/coworkertimepasses?size=1000'
			. '&CoworkerTimePass_Coworker=' . $coworkerId
			. '&CoworkerTimePass_IsUsed=false'
			. '&CoworkerTimePass_CancelDate=null'
		)["Records"] ?? [];
	}

	// Check if a coworker has an active tariff/plan matching a given name
	public function hasActivePlan($coworkerId, $planName) {
		$contracts = $this->request(
			'billing/coworkercontracts?CoworkerContract_Coworker=' . $coworkerId
			. '&CoworkerContract_Active=true'
		)["Records"] ?? [];

		foreach ($contracts as $contract) {
			if (stripos($contract['TariffName'] ?? '', $planName) !== false) {
				return true;
			}
		}
		return false;
	}

	// Get resource availability for a given date
	public function getResourceAvailability($resourceId, $date) {
		// date should be 'YYYY-MM-DD'
		$fromTime = $date . 'T08:00:00Z';
		$toTime = $date . 'T18:00:00Z';
		return $this->request(
			"spaces/bookings?Booking_Resource=$resourceId"
			. "&from_Booking_FromTime=$fromTime"
			. "&to_Booking_ToTime=$toTime"
		)["Records"] ?? [];
	}

	// Create an hourly booking for a room
	public function bookResourceHours($resourceId, $coworkerId, $date, $startHour, $endHour) {
		$fromTime = sprintf('%sT%02d:00:00Z', $date, $startHour);
		$toTime = sprintf('%sT%02d:00:00Z', $date, $endHour);

		$data = [
			'CoworkerId' => $coworkerId,
			'ResourceId' => $resourceId,
			'FromTime' => $fromTime,
			'ToTime' => $toTime,
		];
		return $this->request('spaces/bookings', 'POST', $data);
	}

	// Check if a coworker has a checkin for a given date
	public function hasCheckinForDate($coworkerId, $date) {
		$fromTime = $date . 'T00:00:00Z';
		$toTime = $date . 'T23:59:59Z';
		$checkins = $this->request(
			"spaces/checkins?Checkin_Coworker=$coworkerId"
			. "&from_Checkin_FromTime=$fromTime"
			. "&to_Checkin_ToTime=$toTime"
		)["Records"] ?? [];
		return count($checkins) > 0;
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
