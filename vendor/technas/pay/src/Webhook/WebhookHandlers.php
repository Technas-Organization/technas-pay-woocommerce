<?php

declare(strict_types=1);

namespace TechnasPay\Webhook;

/**
 * Vos fonctions, toutes facultatives : `fn (Event $e) => …`. Une exception doit REMONTER (réponse
 * 5xx) : Technas relivre l'événement. Un événement peut arriver plusieurs fois : soyez idempotents.
 */
final class WebhookHandlers
{
    public function __construct(
        /** Marquez la commande payée (`$e->payment->reference`). */
        public readonly ?\Closure $onCompleted = null,
        /** PAS définitif : n'annulez rien, ne relancez rien. */
        public readonly ?\Closure $onFailed = null,
        /** Définitif : seul moment où relancer, avec une NOUVELLE référence. */
        public readonly ?\Closure $onExpired = null,
        public readonly ?\Closure $onPayoutDestination = null,
        /** Prévenez le client (Technas ne lui écrit pas). */
        public readonly ?\Closure $onRefund = null,
        public readonly ?\Closure $onTopUp = null,
        public readonly ?\Closure $onBalanceConverted = null,
        /** Si ce n'est pas vous qui avez changé d'option, prévenez Technas. */
        public readonly ?\Closure $onFxModeUpdated = null,
        public readonly ?\Closure $onCardAccountUpdated = null,
    ) {
    }

    public function forType(string $type): ?\Closure
    {
        return match (true) {
            $type === 'payment.completed' => $this->onCompleted,
            $type === 'payment.failed' => $this->onFailed,
            $type === 'payment.expired' => $this->onExpired,
            str_starts_with($type, 'payout_destination.') => $this->onPayoutDestination,
            str_starts_with($type, 'refund.') => $this->onRefund,
            $type === 'balance.topped_up' => $this->onTopUp,
            $type === 'balance.converted' => $this->onBalanceConverted,
            $type === 'fx_mode.updated' => $this->onFxModeUpdated,
            $type === 'card_account.updated' => $this->onCardAccountUpdated,
            // Type inconnu : acquitté sans rien appeler (sinon relivré pendant trois jours).
            default => null,
        };
    }
}
