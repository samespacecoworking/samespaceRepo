<?php
/**
 * Check whether someone is a current member
 */

// Set the default timezone
date_default_timezone_set('Europe/London');

// Check for config file
if (is_file('verify_membership_config.php') && is_readable('verify_membership_config.php')) {
    require_once 'verify_membership_config.php';
} else {
    echo "No config.file";
    exit;
}

// $fp = fopen("./verify_membership.log", 'a');

// get coworker Id
$coworker_id = $_GET["Coworker"];

// Get coworker record from Nexudus
$gch = curl_init();
curl_setopt($gch, CURLOPT_URL, 'https://spaces.nexudus.com/api/spaces/coworkers/' . $coworker_id);
curl_setopt($gch, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . base64_encode($nexudus_username . ':' . $nexudus_password)));
curl_setopt($gch, CURLOPT_HEADER, 0);
curl_setopt($gch, CURLOPT_RETURNTRANSFER, true);
$get_response = curl_exec($gch);
curl_close ($gch);

$coworker = json_decode($get_response, false);

$image_path = 'https://samespace.spaces.nexudus.com/en/coworker/getavatar/' . $coworker_id . '?w=1024';
$fullname = $coworker->FullName;

$currentmember = !is_null($coworker->TariffName);

if (!$currentmember) {
  // Get last checkin
  $gch = curl_init();
  curl_setopt($gch, CURLOPT_URL, 'https://spaces.nexudus.com/api/spaces/checkins?page=1&size=1&orderBy=ToTime&dir=Descending&Checkin_Coworker=' . $coworker_id);
  curl_setopt($gch, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . base64_encode($nexudus_username . ':' . $nexudus_password)));
  curl_setopt($gch, CURLOPT_HEADER, 0);
  curl_setopt($gch, CURLOPT_RETURNTRANSFER, true);
  $get_response = curl_exec($gch);
  curl_close ($gch);
  $checkin = json_decode($get_response, false);

  $last_checkin = new datetime($checkin->Records[0]->ToTime);
  $now = new datetime();
  $days_since_last_checkin = $now->diff($last_checkin)->d;
  $currentmember = ($days_since_last_checkin < 32); 
}

?>

<html>
  <head>
    <link rel="stylesheet" href="verify_membership.css">
  </head>
  <body>
    <div class="image_container">
      <img src="https://samespace.spaces.nexudus.com/en/coworker/getavatar/<?= $coworker_id ?>?w=1024" alt="Picture of <?= $fullname ?>">

<?php if ($currentmember) { ?>

      <div class="overlay_text"><h3><?= $fullname ?> is a current Samespace member.</h3></div>
      <div class="checkmark-icon"></div>

<?php } else { ?>

      <div class="overlay_text"><h3><?= $fullname ?> is <font color="red">NOT</font> currently a Samespace member.</h3></div>
      <div class="cross-icon"></div>

<?php } ?>

    </div>
  </body>
</html>

