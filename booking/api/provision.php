<?php
/**
 * Provision a booking in Nexudus and UniFi after payment.
 * Called by webhook.php (after Stripe payment) or checkout.php (for zero-cost bookings).
 */

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../config.php';

use UniFi\Session as UniFiSession;

function provisionBooking($intent) {
    global $nexudusBusinessId, $countryId, $simpleTimeZoneId;
    global $unifi_group_id, $focusSeatedDeskId;
    global $dayPassSingleId, $dayPass4PackId, $dayPass20PackId;
    global $billingEmail, $portalUrl;

    $fp = fopen(__DIR__ . '/../booking.log', 'a');
    $log = function($msg) use ($fp) {
        fwrite($fp, date('Y-m-d\TH:i:s') . ' ' . $msg . PHP_EOL);
    };

    $nexudus = new NexudusSession($nexudusBusinessId, $countryId, $simpleTimeZoneId);
    $customerData = $intent['customer'];
    $isNew = $customerData['type'] === 'new';
    $result = ['success' => false];

    try {
        if ($isNew) {
            // Create Nexudus account
            $newUser = $nexudus->createCustomer(
                $customerData['name'],
                $customerData['email'],
                null,
                $customerData['termsAccepted'] ?? true
            );
            $coworkerId = $newUser['Value']['Id'];
            $nexudus->grantOnlineAccess($coworkerId);
            $log("Created Nexudus account $coworkerId for {$customerData['name']}");

            // Create UniFi account
            $nameArray = explode(' ', $customerData['name']);
            $firstName = $nameArray[0];
            $lastName = end($nameArray);

            $unifi = new UniFiSession();
            $newUnifiUser = $unifi->registerUser($firstName, $lastName, $customerData['email'], $coworkerId, time());
            $unifiUserId = $newUnifiUser['data']['id'] ?? null;

            if ($unifiUserId) {
                $unifi->assignUserToGroup($unifiUserId, $unifi_group_id);
                $pin = $unifi->generatePin()['data'];
                $unifi->assignPin($unifiUserId, $pin);
                $nexudus->updatePin($coworkerId, $pin);
                $nexudus->sendPinReminder($coworkerId);
                $log("Created UniFi account and PIN for $coworkerId");
                $result['pin'] = $pin;
            } else {
                $log("Warning: UniFi account creation returned no user ID for $coworkerId");
            }

            $result['isNew'] = true;
        } else {
            $coworkerId = $customerData['coworkerId'];
            $log("Processing booking for existing customer $coworkerId");
            $result['isNew'] = false;
        }

        // Process based on booking type
        if ($intent['type'] === 'desk') {
            $passesNeeded = $intent['passesNeeded'] ?? 0;

            if ($passesNeeded > 0) {
                // Sell day passes using optimal bundle sizes
                sellDayPasses($nexudus, $coworkerId, $passesNeeded, $log);
            }

            // Book the desk for each selected date
            foreach ($intent['dates'] as $dateStr) {
                $date = new DateTime($dateStr);
                $fromTime = $date->format('Y-m-d') . 'T08:00:00Z';
                $toTime = $date->format('Y-m-d') . 'T18:00:00Z';

                $nexudus->bookResourceHours($focusSeatedDeskId, $coworkerId, $date->format('Y-m-d'), 8, 18);
                $log("Booked desk for $coworkerId on {$date->format('Y-m-d')}");
            }

        } elseif ($intent['type'] === 'room') {
            // Add invisible day pass if needed
            if ($intent['dayPassNeeded'] ?? false) {
                $nexudus->addCoworkerPasses($coworkerId, $dayPassSingleId, 1);
                $log("Added invisible day pass for room booking, customer $coworkerId");
            }

            // Book the room
            $nexudus->bookResourceHours(
                $intent['roomId'],
                $coworkerId,
                $intent['date'],
                $intent['startHour'],
                $intent['endHour']
            );
            $log("Booked room {$intent['roomId']} for $coworkerId on {$intent['date']} {$intent['startHour']}:00-{$intent['endHour']}:00");
        }

        $result['success'] = true;
        $result['coworkerId'] = $coworkerId;

    } catch (Exception $e) {
        $log("ERROR: " . $e->getMessage());
        $result['error'] = $e->getMessage();
    }

    fclose($fp);
    return $result;
}

function sellDayPasses($nexudus, $coworkerId, $passesNeeded, $log) {
    global $dayPassSingleId, $dayPass4PackId, $dayPass20PackId;

    $remaining = $passesNeeded;

    $packs20 = intdiv($remaining, 20);
    if ($packs20 > 0 && $dayPass20PackId) {
        for ($i = 0; $i < $packs20; $i++) {
            $nexudus->addCoworkerPasses($coworkerId, $dayPass20PackId, 20);
        }
        $log("Sold {$packs20}x 20-pack to $coworkerId");
        $remaining -= $packs20 * 20;
    }

    $packs4 = intdiv($remaining, 4);
    if ($packs4 > 0 && $dayPass4PackId) {
        for ($i = 0; $i < $packs4; $i++) {
            $nexudus->addCoworkerPasses($coworkerId, $dayPass4PackId, 4);
        }
        $log("Sold {$packs4}x 4-pack to $coworkerId");
        $remaining -= $packs4 * 4;
    }

    if ($remaining > 0) {
        $nexudus->addCoworkerPasses($coworkerId, $dayPassSingleId, $remaining);
        $log("Sold {$remaining}x single pass to $coworkerId");
    }
}
