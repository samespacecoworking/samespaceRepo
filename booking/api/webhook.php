<?php
/**
 * Stripe webhook handler.
 * Receives checkout.session.completed events and provisions the booking.
 */

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/provision.php';

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Verify webhook signature
if (!StripeSession::verifyWebhookSignature($payload, $sigHeader, $stripeWebhookSecret)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($payload, true);

if ($event['type'] !== 'checkout.session.completed') {
    // Acknowledge but ignore other event types
    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

$session = $event['data']['object'];
$intentId = $session['metadata']['intent_id'] ?? '';

if (!$intentId) {
    http_response_code(400);
    echo json_encode(['error' => 'No intent_id in metadata']);
    exit;
}

// Load the booking intent
$intentFile = __DIR__ . '/../intents/' . basename($intentId) . '.json';
if (!file_exists($intentFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'Booking intent not found']);
    exit;
}

$intent = json_decode(file_get_contents($intentFile), true);

// Don't process twice
if ($intent['status'] === 'completed') {
    http_response_code(200);
    echo json_encode(['received' => true, 'already_processed' => true]);
    exit;
}

// Provision the booking
$result = provisionBooking($intent);

$intent['status'] = $result['success'] ? 'completed' : 'failed';
$intent['provision'] = $result;
$intent['stripePaymentIntent'] = $session['payment_intent'] ?? '';
$intent['completedAt'] = date('c');

file_put_contents($intentFile, json_encode($intent, JSON_PRETTY_PRINT));

http_response_code(200);
echo json_encode(['received' => true, 'provisioned' => $result['success']]);
