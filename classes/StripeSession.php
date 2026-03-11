<?php

class StripeSession {
    private $secretKey;
    private $apiBase = 'https://api.stripe.com/v1/';

    public function __construct($secretKey) {
        $this->secretKey = $secretKey;
    }

    private function request($endpoint, $method = 'GET', $data = null) {
        $url = $this->apiBase . $endpoint;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $this->secretKey . ':');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            throw new Exception('Stripe cURL error: ' . curl_error($ch));
        }
        curl_close($ch);

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            $msg = $decoded['error']['message'] ?? 'Unknown Stripe error';
            throw new Exception("Stripe API error ($httpCode): $msg");
        }
        return $decoded;
    }

    public function createCheckoutSession($lineItems, $metadata, $successUrl, $cancelUrl, $customerEmail = null) {
        $data = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        if ($customerEmail) {
            $data['customer_email'] = $customerEmail;
        }

        foreach ($lineItems as $i => $item) {
            $data["line_items[$i][price_data][currency]"] = 'gbp';
            $data["line_items[$i][price_data][product_data][name]"] = $item['name'];
            $data["line_items[$i][price_data][unit_amount]"] = $item['amount'];
            $data["line_items[$i][quantity]"] = $item['quantity'];
        }

        foreach ($metadata as $key => $value) {
            $data["metadata[$key]"] = $value;
        }

        return $this->request('checkout/sessions', 'POST', $data);
    }

    public function retrieveSession($sessionId) {
        return $this->request('checkout/sessions/' . $sessionId);
    }

    public static function verifyWebhookSignature($payload, $sigHeader, $secret) {
        $elements = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$key, $value] = explode('=', trim($part), 2);
            $elements[$key] = $value;
        }

        $timestamp = $elements['t'] ?? '';
        $signature = $elements['v1'] ?? '';

        if (!$timestamp || !$signature) {
            return false;
        }

        // Reject if timestamp is more than 5 minutes old
        if (abs(time() - (int)$timestamp) > 300) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
