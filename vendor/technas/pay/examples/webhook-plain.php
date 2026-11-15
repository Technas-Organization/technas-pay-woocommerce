<?php
// PHP sans framework : webhook.php (adresse à déclarer à Technas).
require __DIR__ . '/vendor/autoload.php';

use TechnasPay\TechnasPay;
use TechnasPay\Webhook\Event;
use TechnasPay\Webhook\WebhookHandlers;

$pay = new TechnasPay(); // lit TECHNAS_PAY_SECRET
$raw = file_get_contents('php://input'); // corps BRUT : la signature porte dessus
$result = $pay->handleWebhook($raw, $_SERVER['HTTP_X_TECHNAS_PAYMENT_SIGNATURE'] ?? null, new WebhookHandlers(
    onCompleted: fn (Event $e) => marquer_payee($e->payment->reference), // idempotent
    onExpired: fn (Event $e) => essai_suivant($e->payment->reference),   // seul moment où relancer
));
// Une exception de vos fonctions remonte : PHP répond 500 et Technas relivre.
http_response_code($result['status']);
header('Content-Type: application/json');
echo json_encode($result['body']);
