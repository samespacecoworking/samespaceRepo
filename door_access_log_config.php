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
$controller_user     = getenv('UNIFI_USER');
$controller_password = getenv('UNIFI_PASSWORD');
$controller_url      = 'https://unifi.samespace.work';
$controller_version  = '2.3.15';
$site_id             = 'default';

$group_ids           = [ "51fd8fa6-2521-4960-8b65-817bd28121d3" ];

// Nexudus credentials
$nexudus_username = getenv('NEXUDUS_USERNAME');
$nexudus_password = getenv('NEXUDUS_PASSWORD');

/**
 * set to true (without quotes) to enable debug output to the browser and the PHP error log
 */
$debug = false;

