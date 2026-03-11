<?php
/**
 * Check booking status (polled by frontend after Stripe redirect).
 * GET: ?intent=<intentId>
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$intentId = $_GET['intent'] ?? '';

if (!$intentId || !preg_match('/^[a-f0-9]{32}$/', $intentId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid intent ID']);
    exit;
}

$intentFile = __DIR__ . '/../intents/' . $intentId . '.json';

if (!file_exists($intentFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'Booking not found']);
    exit;
}

$intent = json_decode(file_get_contents($intentFile), true);

$response = [
    'status' => $intent['status'],
    'type' => $intent['type'],
];

if ($intent['status'] === 'completed') {
    $provision = $intent['provision'] ?? [];
    $response['isNew'] = $provision['isNew'] ?? false;
    $response['pin'] = $provision['pin'] ?? null;
    $response['portalUrl'] = $GLOBALS['portalUrl'] ?? '';

    if ($intent['type'] === 'desk') {
        $response['dates'] = $intent['dates'] ?? [];
    } else {
        $response['date'] = $intent['date'] ?? '';
        $response['startHour'] = $intent['startHour'] ?? 0;
        $response['endHour'] = $intent['endHour'] ?? 0;
    }
}

echo json_encode($response);
