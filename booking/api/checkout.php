<?php
/**
 * Create a Stripe Checkout Session and store booking intent.
 * POST: {
 *   "type": "desk"|"room",
 *   "dates": ["2024-03-15", ...],       (desk bookings)
 *   "roomId": "...",                     (room bookings)
 *   "date": "2024-03-15",               (room bookings)
 *   "startHour": 10,                    (room bookings)
 *   "endHour": 12,                      (room bookings)
 *   "customer": { "type": "new"|"existing", ... },
 *   "lineItems": [...],
 *   "total": N
 * }
 */

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$type = $input['type'] ?? '';
$customer = $input['customer'] ?? [];
$lineItems = $input['lineItems'] ?? [];
$total = (int)($input['total'] ?? 0);

if (!in_array($type, ['desk', 'room'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid booking type']);
    exit;
}

if (empty($customer['type']) || !in_array($customer['type'], ['new', 'existing'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid customer type']);
    exit;
}

if ($customer['type'] === 'new') {
    if (empty($customer['name']) || empty($customer['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Name and email are required for new customers']);
        exit;
    }
    if (empty($customer['termsAccepted'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Terms must be accepted']);
        exit;
    }

    // Check email isn't already registered
    $nexudus = new NexudusSession($nexudusBusinessId, $countryId, $simpleTimeZoneId);
    $existing = $nexudus->searchCustomers($customer['email']);
    if (is_array($existing) && count($existing) > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'An account with this email already exists. Please log in instead.']);
        exit;
    }
}

// Generate a booking intent ID
$intentId = bin2hex(random_bytes(16));

// Build the booking intent
$intent = [
    'id' => $intentId,
    'type' => $type,
    'customer' => $customer,
    'total' => $total,
    'status' => 'pending',
    'created' => date('c'),
];

if ($type === 'desk') {
    $intent['dates'] = $input['dates'] ?? [];
    $intent['passesNeeded'] = (int)($input['passesNeeded'] ?? 0);
    $intent['existingPassesUsed'] = (int)($input['existingPassesUsed'] ?? 0);
} else {
    $intent['roomId'] = $input['roomId'] ?? '';
    $intent['date'] = $input['date'] ?? '';
    $intent['startHour'] = (int)($input['startHour'] ?? 0);
    $intent['endHour'] = (int)($input['endHour'] ?? 0);
    $intent['dayPassNeeded'] = (bool)($input['dayPassNeeded'] ?? false);
}

// Save intent to file
$intentFile = __DIR__ . '/../intents/' . $intentId . '.json';
file_put_contents($intentFile, json_encode($intent, JSON_PRETTY_PRINT));

// If total is 0 (existing customer with enough passes), skip Stripe and provision directly
if ($total === 0) {
    require_once __DIR__ . '/provision.php';
    $result = provisionBooking($intent);

    if ($result['success']) {
        $intent['status'] = 'completed';
        $intent['provision'] = $result;
        file_put_contents($intentFile, json_encode($intent, JSON_PRETTY_PRINT));

        echo json_encode([
            'success' => true,
            'intentId' => $intentId,
            'redirect' => false,
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Booking failed: ' . ($result['error'] ?? 'unknown error')]);
    }
    exit;
}

// Create Stripe Checkout Session
$stripe = new StripeSession($stripeSecretKey);

$stripeLineItems = [];
foreach ($lineItems as $item) {
    if ($item['amount'] > 0) {
        $stripeLineItems[] = [
            'name' => $item['name'],
            'amount' => $item['amount'],
            'quantity' => $item['quantity'],
        ];
    }
}

$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$successUrl = $baseUrl . '/confirmation.html?intent=' . $intentId;
$cancelUrl = $baseUrl . '/';

$email = $customer['email'] ?? null;

try {
    $session = $stripe->createCheckoutSession(
        $stripeLineItems,
        ['intent_id' => $intentId],
        $successUrl,
        $cancelUrl,
        $email
    );

    $intent['stripeSessionId'] = $session['id'];
    file_put_contents($intentFile, json_encode($intent, JSON_PRETTY_PRINT));

    echo json_encode([
        'success' => true,
        'intentId' => $intentId,
        'redirect' => true,
        'checkoutUrl' => $session['url'],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create payment session: ' . $e->getMessage()]);
}
