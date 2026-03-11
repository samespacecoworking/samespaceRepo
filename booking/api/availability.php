<?php
/**
 * Get room availability for a given date.
 * GET: ?resourceId=...&date=YYYY-MM-DD
 * Returns: { "slots": [ { "hour": 8, "available": true }, ... ] }
 */

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$resourceId = $_GET['resourceId'] ?? '';
$date = $_GET['date'] ?? '';

if (!$resourceId || !$date) {
    http_response_code(400);
    echo json_encode(['error' => 'resourceId and date are required']);
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Date must be YYYY-MM-DD format']);
    exit;
}

// Check it's a weekday
$dayOfWeek = (new DateTime($date))->format('N');
if ($dayOfWeek > 5) {
    echo json_encode(['slots' => [], 'message' => 'Not available on weekends']);
    exit;
}

$nexudus = new NexudusSession($nexudusBusinessId, $countryId, $simpleTimeZoneId);

// Get existing bookings for this resource on this date
$bookings = $nexudus->getResourceAvailability($resourceId, $date);

// Build hourly slots (8am to 6pm)
$bookedHours = [];
foreach ($bookings as $booking) {
    $from = new DateTime($booking['FromTime']);
    $to = new DateTime($booking['ToTime']);
    $startHour = (int)$from->format('G');
    $endHour = (int)$to->format('G');
    for ($h = $startHour; $h < $endHour; $h++) {
        $bookedHours[$h] = true;
    }
}

$slots = [];
for ($h = 8; $h < 18; $h++) {
    $slots[] = [
        'hour' => $h,
        'label' => sprintf('%02d:00 - %02d:00', $h, $h + 1),
        'available' => !isset($bookedHours[$h]),
    ];
}

echo json_encode(['slots' => $slots]);
