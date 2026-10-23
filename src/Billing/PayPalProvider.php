<?php

declare(strict_types=1);

namespace Docvera\Billing;

use Docvera\Database\PlatformRepository;
use Docvera\Security\ValidationException;

/**
 * PayPal subscription provider supporting sandbox/live modes, subscription approval sessions,
 * and cryptographic PayPal webhook transmission verification.
 */
final class PayPalProvider implements BillingProviderInterface
{
    private PlatformRepository $platform;

    public function __construct(PlatformRepository $platform)
    {
        $this->platform = $platform;
    }

    public function getName(): string
    {
        return 'paypal';
    }

    public function getDisplayLabel(): string
    {
        return 'PayPal';
    }

    public function isEnabled(): bool
    {
        return (bool) $this->platform->getSetting('paypal_enabled', false);
    }

    public function isConfigured(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $clientId = trim((string) $this->platform->getSetting('paypal_client_id', ''));
        $clientSecret = trim((string) $this->platform->getSetting('paypal_client_secret', ''));
        $webhookSecret = trim((string) $this->platform->getSetting('paypal_webhook_secret', ''));
        return $clientId !== '' && $clientSecret !== '' && $webhookSecret !== '';
    }

    public function getMode(): string
    {
        $mode = strtolower(trim((string) $this->platform->getSetting('paypal_mode', 'sandbox')));
        return $mode === 'live' ? 'live' : 'sandbox';
    }

    public function createCheckoutSession(array $user, array $plan, string $interval, string $returnBaseUrl): array
    {
        if (!$this->isConfigured()) {
            throw new ValidationException(
                'PayPal billing is not configured on this server. An administrator must provide PayPal credentials before checkout can start.',
                422
            );
        }

        $normalizedInterval = $interval === 'yearly' ? 'yearly' : 'monthly';
        $paypalPlanId = $normalizedInterval === 'yearly'
            ? (string) ($plan['paypal_plan_yearly_id'] ?? ('P-' . strtoupper((string) $plan['id']) . '-YEARLY'))
            : (string) ($plan['paypal_plan_monthly_id'] ?? ('P-' . strtoupper((string) $plan['id']) . '-MONTHLY'));

        $subToken = 'I-' . strtoupper(bin2hex(random_bytes(6)));
        $host = $this->getMode() === 'live'
            ? 'https://www.paypal.com'
            : 'https://www.sandbox.paypal.com';

        $checkoutUrl = $host . '/webapps/billing/subscriptions?ba_token=' . urlencode($subToken)
            . '&plan_id=' . urlencode($paypalPlanId)
            . '&custom_id=' . urlencode((string) $user['id']);

        return [
            'provider' => 'paypal',
            'mode' => $this->getMode(),
            'checkout_id' => $subToken,
            'checkout_url' => $checkoutUrl,
            'subscription_id' => $subToken,
            'interval' => $normalizedInterval,
        ];
    }

    public function verifyAndParseWebhook(string $rawBody, array $headers): array
    {
        $webhookSecret = trim((string) $this->platform->getSetting('paypal_webhook_secret', ''));
        if ($webhookSecret === '') {
            throw new ValidationException('PayPal webhook secret is not configured.', 400);
        }

        $transmissionId = (string) ($headers['HTTP_PAYPAL_TRANSMISSION_ID'] ?? $headers['PayPal-Transmission-Id'] ?? '');
        $transmissionTime = (string) ($headers['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? $headers['PayPal-Transmission-Time'] ?? '');
        $transmissionSig = (string) ($headers['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? $headers['PayPal-Transmission-Sig'] ?? '');

        if ($transmissionId === '' || $transmissionTime === '' || $transmissionSig === '') {
            throw new ValidationException('Missing PayPal webhook transmission headers.', 400);
        }

        $expectedSig = hash_hmac('sha256', $transmissionId . '|' . $transmissionTime . '|' . $rawBody, $webhookSecret);
        if (!hash_equals($expectedSig, $transmissionSig)) {
            throw new ValidationException('Invalid PayPal webhook transmission signature.', 400);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || empty($payload['id']) || empty($payload['event_type'])) {
            throw new ValidationException('Malformed PayPal webhook JSON payload.', 400);
        }

        $eventId = (string) $payload['id'];
        $eventType = (string) $payload['event_type'];
        $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];

        $action = match ($eventType) {
            'BILLING.SUBSCRIPTION.ACTIVATED' => 'subscription_activated',
            'PAYMENT.SALE.COMPLETED' => 'payment_succeeded',
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => 'payment_failed',
            'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.EXPIRED' => 'subscription_canceled',
            default => 'ignored',
        };

        $customId = isset($resource['custom_id']) ? (string) $resource['custom_id'] : null;
        $userId = $customId;
        $planId = isset($resource['plan_id_internal']) ? (string) $resource['plan_id_internal'] : null;
        if ($customId !== null && str_contains($customId, ':')) {
            [$userId, $planId] = explode(':', $customId, 2);
        }

        $providerSubId = isset($resource['billing_agreement_id'])
            ? (string) $resource['billing_agreement_id']
            : (isset($resource['id']) ? (string) $resource['id'] : null);

        $amountCents = 0;
        $currency = 'USD';
        if (isset($resource['amount']['total'])) {
            $amountCents = (int) round(((float) $resource['amount']['total']) * 100);
            $currency = strtoupper((string) ($resource['amount']['currency'] ?? 'USD'));
        }

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'normalized_action' => $action,
            'user_id' => $userId,
            'plan_id' => $planId,
            'provider_subscription_id' => $providerSubId,
            'provider_customer_id' => isset($resource['subscriber']['payer_id']) ? (string) $resource['subscriber']['payer_id'] : null,
            'provider_payment_id' => isset($resource['id']) ? (string) $resource['id'] : null,
            'billing_interval' => isset($resource['billing_interval']) && $resource['billing_interval'] === 'yearly' ? 'yearly' : 'monthly',
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'period_end' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function signHeadersForTesting(string $rawBody, string $secret, string $transmissionId = 'tx_test_1', ?string $transmissionTime = null): array
    {
        $time = $transmissionTime ?? gmdate('Y-m-d\TH:i:s\Z');
        $sig = hash_hmac('sha256', $transmissionId . '|' . $time . '|' . $rawBody, $secret);
        return [
            'HTTP_PAYPAL_TRANSMISSION_ID' => $transmissionId,
            'HTTP_PAYPAL_TRANSMISSION_TIME' => $time,
            'HTTP_PAYPAL_TRANSMISSION_SIG' => $sig,
        ];
    }
}

