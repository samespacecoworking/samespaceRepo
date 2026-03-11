<?php

/* Configuration for the public booking service */

date_default_timezone_set('Europe/London');

// Nexudus
$nexudusBusinessId = '1414914964';
$countryId = 1220;
$simpleTimeZoneId = 2023;

// Nexudus resource IDs (rooms and desks)
$focusSeatedDeskId = '1414944958';
$zoomRoomId = '1414928089';
$meetingRoomId = '1414931131';

// Nexudus product IDs
$dayPassSingleId = '1415031210';
$dayPass4PackId = '1415006468';
$dayPass20PackId = '1415017291';

// UniFi
$unifi_group_id = '51fd8fa6-2521-4960-8b65-817bd28121d3';

// Stripe
$stripeSecretKey = getenv('STRIPE_SECRET_KEY');
$stripeWebhookSecret = getenv('STRIPE_WEBHOOK_SECRET');

// Pricing (pence)
$pricing = [
    'day_pass_single' => 1900,    // £19
    'day_pass_4pack'  => 6500,    // £65
    'day_pass_20pack' => 26000,   // £260
    'room_standard'   => 1000,    // £10/hour
    'room_discount'   => 500,     // £5/hour (Business Address plan holders)
];

// Nexudus members portal URL
$portalUrl = 'https://samespace.spaces.nexudus.com';

// Nexudus public API base
$nexudusPublicApiBase = 'https://spaces.nexudus.com/publicapi/v1/';

// Business Address plan name (for room discount detection)
$businessAddressPlanName = 'Business Address';
