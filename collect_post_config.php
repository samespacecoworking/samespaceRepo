<?php
/**
 * Copyright (c) 2017, Art of WiFi
 *
 * This file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.md
 *
 */

/**
 * Controller configuration
 * ===============================
 * Copy this file to your working directory, rename it to config.php and update the section below with your UniFi
 * controller details and credentials
 */

// Nexudus credentials
$nexudus_username = getenv("NEXUDUS_USER");
$nexudus_password = getenv('NEXUDUS_PASSWORD');

// Business ID for Samespace
$business_id = '1414914964';

/**
 * set to true (without quotes) to enable debug output to the browser and the PHP error log
 */
$debug = false;

