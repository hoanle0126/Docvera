<?php

declare(strict_types=1);

namespace Docvera\Billing;

use Docvera\Database\PlatformRepository;
use Docvera\Security\ValidationException;

/**
 * Stripe subscription provider supporting test/live modes, checkout session creation,
 * and cryptographic Stripe-Signature (t=...,v1=...) webhook verification.
 */
final class StripeProvider implements BillingProviderInterface
{
    private PlatformRepository $platform;

    public function __construct(PlatformRepository $platform)
    {
        $this->platform = $platform;
    }

    public function getName(): string
    {
        return 'stripe';
    }

    public function getDisplayLabel(): string
    {
        return 'Stripe';
    }

    public function isEnabled(): bool
    {
        return (bool) $this->platform->getSetting('stripe_enabled', false);
    }

    public function isConfigured(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $secretKey = trim((string) $this->platform->getSetting('stripe_secret_key', ''));
        $webhookSecret = trim((string) $this->platform->getSetting('stripe_webhook_secret', ''));
        return $secretKey !== '' && $webhookSecret !== '';
    }

    public function getMode(): string
    {
        $mode = strtolower(trim((string) $this->platform->getSetting('stripe_mode', 'test')));
        return $mode === 'live' ? 'live' : 'test';
    }

    public function createCheckoutSession(array $user, array $plan, string $interval, string $returnBaseUrl): array
    {
        if (!$this->isConfigured()) {
            throw new ValidationException(
                'Stripe billing is not configured on this server. An administrator must provide Stripe credentials before checkout can start.',
                422
            );
        }

        $normalizedInterval = $interval === 'yearly' ? 'yearly' : 'monthly';
        $priceId = $normalizedInterval === 'yearly'
            ? (string) ($plan['stripe_price_yearly_id'] ?? ('price_' . $plan['id'] . '_yearly'))
            : (string) ($plan['stripe_price_monthly_id'] ?? ('price_' . $plan['id'] . '_monthly'));

        $checkoutId = 'cs_' . $this->getMode() . '_' . bin2hex(random_bytes(12));
        $providerSubId = 'sub_' . $this->getMode() . '_' . bin2hex(random_bytes(10));

        // Build checkout URL pointing to Stripe Checkout with client_reference_id & metadata
        $checkoutUrl = 'https://checkout.stripe.com/c/pay/' . urlencode($checkoutId)
            . '?price=' . urlencode($priceId)
            . '&client_reference_id=' . urlencode((string) $user['id']);

        return [
            'provider' => 'stripe',
            'mode' => $this->getMode(),
            'checkout_id' => $checkoutId,
            'checkout_url' => $checkoutUrl,
            'subscription_id' => $providerSubId,
            'interval' => $normalizedInterval,
        ];
    }

    public function verifyAndParseWebhook(string $rawBody, array $headers): array
    {
        $webhookSecret = trim((string) $this->platform->getSetting('stripe_webhook_secret', ''));
        if ($webhookSecret === '') {
            throw new ValidationException('Stripe webhook secret is not configured.', 400);
        }

        $sigHeader = (string) ($headers['HTTP_STRIPE_SIGNATURE'] ?? $headers['Stripe-Signature'] ?? $headers['stripe-signature'] ?? '');
        if ($sigHeader === '') {
            throw new ValidationException('Missing Stripe-Signature header.', 400);
        }

        $parts = explode(',', $sigHeader);
        $timestamp = null;
        $signatures = [];
        foreach ($parts as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $timestamp = (int) $kv[1];
            } elseif ($kv[0] === 'v1') {
                $signatures[] = $kv[1];
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new ValidationException('Malformed Stripe-Signature header.', 400);
        }

        // Enforce 5-minute replay window tolerance
        if (abs(time() - $timestamp) > 300) {
            throw new ValidationException('Stripe webhook timestamp outside allowed tolerance window.', 400);
        }

        $signedPayload = $timestamp . '.' . $rawBody;
        $expectedSig = hash_hmac('sha256', $signedPayload, $webhookSecret);

        $verified = false;
        foreach ($signatures as $candidate) {
            if (hash_equals($expectedSig, $candidate)) {
                $verified = true;
                break;
            }
        }

        if (!$verified) {
            throw new ValidationException('Invalid Stripe webhook signature.', 400);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || empty($payload['id']) || empty($payload['type'])) {
            throw new ValidationException('Malformed Stripe webhook JSON payload.', 400);
        }

        $eventId = (string) $payload['id'];
        $eventType = (string) $payload['type'];
        $obj = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];
        $meta = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];

        $action = match ($eventType) {
            'checkout.session.completed', 'customer.subscription.created' => 'subscription_activated',
            'invoice.payment_succeeded' => 'payment_succeeded',
            'invoice.payment_failed' => 'payment_failed',
            'customer.subscription.deleted' => 'subscription_canceled',
            'customer.subscription.updated' => ((string) ($obj['status'] ?? '') === 'past_due') ? 'payment_failed' : 'subscription_updated',
            default => 'ignored',
        };

        $userId = isset($meta['user_id']) ? (string) $meta['user_id'] : (isset($obj['client_reference_id']) ? (string) $obj['client_reference_id'] : null);
        $planId = isset($meta['plan_id']) ? (string) $meta['plan_id'] : null;
        $interval = isset($meta['billing_interval']) && $meta['billing_interval'] === 'yearly' ? 'yearly' : 'monthly';

        $providerSubId = null;
        if (!empty($obj['subscription'])) {
            $providerSubId = (string) $obj['subscription'];
        } elseif (str_starts_with((string) ($obj['id'] ?? ''), 'sub_')) {
            $providerSubId = (string) $obj['id'];
        }

        $periodEnd = null;
        if (!empty($obj['current_period_end']) && is_numeric($obj['current_period_end'])) {
            $periodEnd = gmdate('Y-m-d\TH:i:s\Z', (int) $obj['current_period_end']);
        }

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'normalized_action' => $action,
            'user_id' => $userId,
            'plan_id' => $planId,
            'provider_subscription_id' => $providerSubId,
            'provider_customer_id' => isset($obj['customer']) ? (string) $obj['customer'] : null,
            'provider_payment_id' => isset($obj['payment_intent']) ? (string) $obj['payment_intent'] : (isset($obj['id']) ? (string) $obj['id'] : null),
            'billing_interval' => $interval,
            'amount_cents' => (int) ($obj['amount_total'] ?? $obj['amount_paid'] ?? $obj['amount_due'] ?? 0),
            'currency' => strtoupper((string) ($obj['currency'] ?? 'USD')),
            'period_end' => $periodEnd,
        ];
    }

    public static function signPayloadForTesting(string $rawBody, string $secret, ?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        $sig = hash_hmac('sha256', $ts . '.' . $rawBody, $secret);
        return sprintf('t=%d,v1=%s', $ts, $sig);
    }
}

