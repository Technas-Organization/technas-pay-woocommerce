<?php

declare(strict_types=1);

namespace TechnasPay\Webhook;

/** Signature absente, mal formée, trop ancienne, fausse, ou corps illisible : répondez 400. */
final class WebhookVerificationException extends \RuntimeException
{
}
