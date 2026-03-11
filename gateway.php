<?php

// Configuration
$validBearerToken = "aa0bca62c421f07655040825afca1ea1259e2cc5bf666e4aebf97efb2e1e9791";
$backendServiceBaseUrl = "https://api.samespace.work";

// Function to validate Bearer token
function isValidBearerToken($authHeader, $validToken)
{
    if (!$authHeader || !str_starts_with($authHeader, "Bearer ")) {
        return false;
    }
    $token = trim(substr($authHeader, 7)); // Extract the token part
    return $token === $validToken;
}

// Function to forward the request
function forwardRequest($backendBaseUrl)
{
    // Build the full URL for the backend request
    $path = $_SERVER['REQUEST_URI'];
    $scriptName = $_SERVER['SCRIPT_NAME'];

    // Remove the script name from the path
    if (strpos($path, $scriptName) === 0) {
        $path = substr($path, strlen($scriptName));
    }

    // Construct the backend URL
    $backendUrl = rtrim($backendBaseUrl, '/') . $path;

    // Forward headers
    $headers = getallheaders();
    $forwardHeaders = [];
    foreach ($headers as $key => $value) {
        if (strtolower($key) !== "authorization") {
            $forwardHeaders[] = "$key: $value";
        }
    }

    // Forward body
    $body = file_get_contents("php://input");

    // Initialize cURL
    $ch = curl_init($backendUrl);

    // Set cURL options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $forwardHeaders);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    // Execute cURL and get response
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    // Close cURL
    curl_close($ch);

    // Return response
    http_response_code($httpCode);
    if ($contentType) {
        header("Content-Type: $contentType");
    }
    echo $response;
}

// Main script
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

if (!isValidBearerToken($authHeader, $validBearerToken)) {
    http_response_code(401); // Unauthorized
    header('Content-Type: application/json');
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

// Forward the request if the token is valid
forwardRequest($backendServiceBaseUrl);
