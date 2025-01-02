<?php
// Fetch currently checked-in customers

require_once '../autoload.php';

// Business ID for Samespace
$nexudusBusinessId = '1414914964';
$countryId = 1220;
$simpleTimeZoneId = 2023;

//Connect to Nexudus
$nexudusSession = new NexudusSession($nexudusBusinessId, $countryId, $simpleTimeZoneId);

// Get current date and time
$date = new DateTime();
$today = $date->format('Y-m-d') . "T00:01:00";

// Fetch checkins from Nexudus API
$checkins = $nexudusSession->getCurrentCheckins($today);
echo json_encode($checkins);

?>

