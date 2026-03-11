<?php
/**
 * Calculate pricing for a booking.
 * POST: { "type": "desk"|"room", "days": N, "hours": N, "existingPasses": N, "hasBusinessAddress": bool }
 * Returns: { "lineItems": [...], "total": N, "passesNeeded": N }
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$type = $input['type'] ?? '';
$existingPasses = max(0, (int)($input['existingPasses'] ?? 0));

if ($type === 'desk') {
    $days = (int)($input['days'] ?? 0);
    if ($days < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'At least 1 day is required']);
        exit;
    }

    // Subtract existing passes
    $passesNeeded = max(0, $days - $existingPasses);
    $lineItems = calculateDayPassLineItems($passesNeeded, $pricing);

    echo json_encode([
        'lineItems' => $lineItems,
        'total' => array_sum(array_column($lineItems, 'subtotal')),
        'passesNeeded' => $passesNeeded,
        'existingPassesUsed' => $days - $passesNeeded,
    ]);

} elseif ($type === 'room') {
    $hours = (int)($input['hours'] ?? 0);
    $hasBusinessAddress = (bool)($input['hasBusinessAddress'] ?? false);

    if ($hours < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'At least 1 hour is required']);
        exit;
    }

    $hourlyRate = $hasBusinessAddress ? $pricing['room_discount'] : $pricing['room_standard'];
    $lineItems = [];

    $lineItems[] = [
        'name' => 'Meeting room' . ($hasBusinessAddress ? ' (Business Address rate)' : ''),
        'amount' => $hourlyRate,
        'quantity' => $hours,
        'subtotal' => $hourlyRate * $hours,
    ];

    // Day pass needed if they don't already have one
    $dayPassNeeded = $existingPasses < 1;
    if ($dayPassNeeded) {
        $lineItems[] = [
            'name' => 'Day pass (included with room booking)',
            'amount' => 0,
            'quantity' => 1,
            'subtotal' => 0,
        ];
    }

    echo json_encode([
        'lineItems' => $lineItems,
        'total' => array_sum(array_column($lineItems, 'subtotal')),
        'dayPassNeeded' => $dayPassNeeded,
    ]);

} else {
    http_response_code(400);
    echo json_encode(['error' => "type must be 'desk' or 'room'"]);
}

/**
 * Calculate optimal day pass bundles.
 * Maximise 20-packs, then 4-packs, then singles.
 */
function calculateDayPassLineItems($passesNeeded, $pricing) {
    if ($passesNeeded === 0) {
        return [];
    }

    $lineItems = [];
    $remaining = $passesNeeded;

    $packs20 = intdiv($remaining, 20);
    if ($packs20 > 0) {
        $lineItems[] = [
            'name' => 'Day pass (20-pack)',
            'amount' => $pricing['day_pass_20pack'],
            'quantity' => $packs20,
            'subtotal' => $pricing['day_pass_20pack'] * $packs20,
        ];
        $remaining -= $packs20 * 20;
    }

    $packs4 = intdiv($remaining, 4);
    if ($packs4 > 0) {
        $lineItems[] = [
            'name' => 'Day pass (4-pack)',
            'amount' => $pricing['day_pass_4pack'],
            'quantity' => $packs4,
            'subtotal' => $pricing['day_pass_4pack'] * $packs4,
        ];
        $remaining -= $packs4 * 4;
    }

    if ($remaining > 0) {
        $lineItems[] = [
            'name' => 'Day pass',
            'amount' => $pricing['day_pass_single'],
            'quantity' => $remaining,
            'subtotal' => $pricing['day_pass_single'] * $remaining,
        ];
    }

    return $lineItems;
}
