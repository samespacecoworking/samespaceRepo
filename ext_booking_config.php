<?php

/* Configuration file for the external booking API service ext_booking.php */

// Set the default timezone
date_default_timezone_set('Europe/London');

// Unifi credentials
$unifi_user     = getenv('UNIFI_USER'); // the user name for access to the UniFi Controller
$unifi_password = getenv('UNIFI_PASSWORD'); // the password for access to the UniFi Controller
$unifi_url      = 'https://unifi.samespace.work'; // full url to the UniFi Controller, eg. 'https://22.22.11.11:8443'
$unifi_version  = '2.3.15'; // the version of the Controller software, eg. '4.6.6' (must be at least 4.0.0)
$unifi_site_id  = 'default';
$unifi_group_id = "51fd8fa6-2521-4960-8b65-817bd28121d3";

// Business ID for Samespace
$nexudusBusinessId = '1414914964';
$countryId = 1220;
$simpleTimeZoneId = 2023;
$focusSeatedDeskId = "1414944958";
$dayPassId = "1415031210";
$floePassId = "1415215638";
$billingEmail = "aidan.dunphy+billing@samespace.work";

// Arbitrary limit to prevent erroneous bookings
$maxBookingLength = 20;

// Set to true (without quotes) to enable debug output to the browser and the PHP error log
$debug = false;

