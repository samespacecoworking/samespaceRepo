<?php
/**
 * Authenticate an existing customer against Nexudus.
 * POST: { "email": "...", "password": "..." }
 * Returns: { "success": true, "coworkerId": ..., "name": "...", "unusedPasses": N, "hasBusinessAddress": bool }
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
$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password are required']);
    exit;
}

$nexudus = new NexudusSession($nexudusBusinessId, $countryId, $simpleTimeZoneId);

// Validate credentials via Nexudus token endpoint
$authResult = $nexudus->authenticateCoworker($email, $password);
if (!$authResult || isset($authResult['error'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password']);
    exit;
}

// Look up the coworker by email to get their ID and details
$coworkers = $nexudus->searchCustomers($email);
if (!is_array($coworkers) || count($coworkers) === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Customer not found']);
    exit;
}

$coworker = $coworkers[0];
$coworkerId = $coworker['Id'];

// Check unused passes
$unusedPasses = $nexudus->getUnusedPasses($coworkerId);

// Check for Business Address plan (room discount)
$hasBusinessAddress = $nexudus->hasActivePlan($coworkerId, $businessAddressPlanName);

echo json_encode([
    'success' => true,
    'coworkerId' => $coworkerId,
    'name' => $coworker['FullName'],
    'email' => $coworker['Email'],
    'unusedPasses' => count($unusedPasses),
    'hasBusinessAddress' => $hasBusinessAddress,
]);
