<?php

/**
 * CartPanda Webhook Proxy
 *
 * Receives webhooks from CartPanda and forwards them to the hub-laravel API.
 * Deploy this file on any web server and point CartPanda's webhook URL to it.
 */

const HUB_WEBHOOK_URL = 'https://YOUR_HUB_DOMAIN/api/cartpanda-webhook';

$body = file_get_contents('php://input');
$contentType = $_SERVER['CONTENT_TYPE'] ?? 'application/json';

$ch = curl_init(HUB_WEBHOOK_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: '.$contentType,
        'Content-Length: '.strlen($body),
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'proxy_error']);
    exit;
}

http_response_code($httpCode ?: 200);
header('Content-Type: application/json');
echo $response ?: '{"ok":true}';
