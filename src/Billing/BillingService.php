<?php

declare(strict_types=1);

namespace Docvera\Billing;

use Docvera\Core\Logger;
use Docvera\Database\PlatformRepository;
use Docvera\Security\ValidationException;

final class BillingService
{
    private PlatformRepository $platform;
    private Logger $logger;

    /** @var array<string, BillingProviderInterface> */
    private array $providers;

    public function __construct(PlatformRepository $platform, Logger $logger)
    {
        $this->platform = $platform;
        $this->logger = $logger;

        $this->providers = [
            'stripe' => new StripeProvider($platform),
            'paypal' => new PayPalProvider($platform),
        ];
    }

    /**
     * @return array<string, BillingProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    public function getProvider(string $name): ?BillingProviderInterface
    {
        return $this->providers[strtolower($name)] ?? null;
    }

    public function isAnyProviderConfigured(): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->isConfigured()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns safe status summary of billing providers without exposing secret keys.
     *
     * @return array<string, array{name: string, label: string, enabled: bool, configured: bool, mode: string}>
     */
    public function getProviderStatuses(): array
    {
        $out = [];
        foreach ($this->providers as $key => $provider) {
            $out[$key] = [
                'name' => $provider->getName(),
                'label' => $provider->getDisplayLabel(),
                'enabled' => $provider->isEnabled(),
                'configured' => $provider->isConfigured(),
                'mode' => $provider->getMode(),
            ];
        }
        return $out;
    }

    /**
     * Starts a checkout flow for a user and plan.
     * Creates an 'incomplete' subscription record that only transitions to 'active' after a verified webhook.
     *
     * @return array<string, mixed>
     */
    public function startCheckout(
        string $userId,
        string $planId,
        string $providerName,
        string $interval,
        string $returnBaseUrl
    ): array {
        $mode = (string) $this->platform->getSetting('deployment_mode', 'saas');
        if ($mode !== 'saas' || !(bool) $this->platform->getSetting('billing_enabled', true)) {
            throw new ValidationException('Subscription billing is not enabled in the current deployment mode.', 403);
        }

        $user = $this->platform->findUserById($userId);
        if ($user === null) {
            throw new ValidationException('User account not found.', 404);
        }

        $plan = $this->platform->findPlan($planId);
        if ($plan === null || in_array($planId, ['guest', 'free'], true)) {
            throw new ValidationException('Selected plan is not a billable subscription plan.', 422);
        }

        $provider = $this->getProvider($providerName);
        if ($provider === null) {
            throw new ValidationException(sprintf('Unsupported billing provider "%s".', $providerName), 422);
        }

        if (!$provider->isConfigured()) {
            throw new ValidationException(
                sprintf('%s billing is currently unconfigured on this server. Please configure %s credentials in the Admin Panel.', $provider->getDisplayLabel(), $provider->getDisplayLabel()),
                422
            );
        }

        $normalizedInterval = $interval === 'yearly' ? 'yearly' : 'monthly';
        $session = $provider->createCheckoutSession($user, $plan, $normalizedInterval, $returnBaseUrl);

        $now = time();
        $periodSeconds = $normalizedInterval === 'yearly' ? 31536000 : 2592000;
        $subRecord = [
            'id' => 'subrec_' . bin2hex(random_bytes(10)),
            'user_id' => $userId,
            'plan_id' => $planId,
            'provider' => $provider->getName(),
            'provider_subscription_id' => $session['subscription_id'],
            'provider_customer_id' => null,
            'billing_interval' => $normalizedInterval,
            'status' => 'incomplete',
            'cancel_at_period_end' => 0,
            'current_period_start' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'current_period_end' => gmdate('Y-m-d\TH:i:s\Z', $now + $periodSeconds),
            'canceled_at' => null,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
        ];

        $this->platform->saveSubscription($subRecord);

        return [
            'subscription_record' => $subRecord,
            'checkout' => $session,
        ];
    }

    /**
     * Cancels a user's active subscription (either at period end or immediately).
     *
     * @return array<string, mixed>
     */
    public function cancelSubscription(string $subscriptionId, string $userId, bool $immediately = false): array
    {
        $sub = $this->platform->findSubscription($subscriptionId);
        if ($sub === null || (string) ($sub['user_id'] ?? '') !== $userId) {
            throw new ValidationException('Subscription not found.', 404);
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $sub['canceled_at'] = $now;
        $sub['updated_at'] = $now;

        if ($immediately) {
            $sub['status'] = 'canceled';
            $sub['cancel_at_period_end'] = 0;
            $sub['current_period_end'] = $now;

            $user = $this->platform->findUserById($userId);
            if ($user !== null) {
                $user['plan_id'] = 'free';
                $user['updated_at'] = $now;
                $this->platform->saveUser($user);
            }
        } else {
            $sub['cancel_at_period_end'] = 1;
        }

        $this->platform->saveSubscription($sub);

        $this->logger->info('billing.subscription_canceled', [
            'subscription_id' => $subscriptionId,
            'user_id' => $userId,
            'immediately' => $immediately,
        ]);

        return $sub;
    }

    /**
     * Verifies and processes an incoming billing webhook idempotently.
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public function handleWebhook(string $providerName, string $rawBody, array $headers): array
    {
        $provider = $this->getProvider($providerName);
        if ($provider === null) {
            throw new ValidationException(sprintf('Unknown billing provider "%s".', $providerName), 404);
        }

        $event = $provider->verifyAndParseWebhook($rawBody, $headers);
        $eventId = $event['event_id'];
        $payloadHash = hash('sha256', $rawBody);

        // Idempotency guard: if this event ID was already processed for this provider, return immediately
        if ($this->platform->hasProcessedWebhook($provider->getName(), $eventId)) {
            return [
                'ok' => true,
                'duplicate' => true,
                'provider' => $provider->getName(),
                'event_id' => $eventId,
                'action' => 'ignored_duplicate',
            ];
        }

        $action = $event['normalized_action'];
        $providerSubId = $event['provider_subscription_id'];
        $userId = $event['user_id'];
        $planId = $event['plan_id'];
        $now = time();
        $nowIso = gmdate('Y-m-d\TH:i:s\Z', $now);

        $subRecord = null;
        if ($providerSubId !== null && $providerSubId !== '') {
            $subRecord = $this->platform->findByProviderSubscriptionId($provider->getName(), $providerSubId);
        }
        if ($subRecord === null && $userId !== null && $userId !== '') {
            $subRecord = $this->platform->findLatestSubscriptionForUser($userId);
        }

        if ($subRecord !== null) {
            $userId = $userId ?? (string) $subRecord['user_id'];
            $planId = $planId ?? (string) $subRecord['plan_id'];
        }

        $planId = ($planId !== null && $planId !== '') ? $planId : 'pro';
        $interval = $event['billing_interval'];
        $periodSeconds = $interval === 'yearly' ? 31536000 : 2592000;
        $periodEnd = $event['period_end'] ?? gmdate('Y-m-d\TH:i:s\Z', $now + $periodSeconds);

        if ($action === 'subscription_activated' || $action === 'payment_succeeded') {
            if ($subRecord === null && $userId !== null) {
                $subRecord = [
                    'id' => 'subrec_' . bin2hex(random_bytes(10)),
                    'user_id' => $userId,
                    'plan_id' => $planId,
                    'provider' => $provider->getName(),
                    'provider_subscription_id' => $providerSubId ?? ('sub_' . bin2hex(random_bytes(8))),
                    'provider_customer_id' => $event['provider_customer_id'],
                    'billing_interval' => $interval,
                    'status' => 'active',
                    'cancel_at_period_end' => 0,
                    'current_period_start' => $nowIso,
                    'current_period_end' => $periodEnd,
                    'canceled_at' => null,
                    'created_at' => $nowIso,
                    'updated_at' => $nowIso,
                ];
            } elseif ($subRecord !== null) {
                $subRecord['status'] = 'active';
                $subRecord['plan_id'] = $planId;
                if ($providerSubId !== null) {
                    $subRecord['provider_subscription_id'] = $providerSubId;
                }
                if ($event['provider_customer_id'] !== null) {
                    $subRecord['provider_customer_id'] = $event['provider_customer_id'];
                }
                $subRecord['current_period_end'] = $periodEnd;
                $subRecord['updated_at'] = $nowIso;
            }

            if ($subRecord !== null) {
                $this->platform->saveSubscription($subRecord);
            }

            if ($userId !== null) {
                $user = $this->platform->findUserById($userId);
                if ($user !== null) {
                    $user['plan_id'] = $planId;
                    $user['updated_at'] = $nowIso;
                    $this->platform->saveUser($user);
                }

                $plan = $this->platform->findPlan($planId);
                $defaultAmount = $interval === 'yearly'
                    ? (int) ($plan['price_yearly_cents'] ?? 18000)
                    : (int) ($plan['price_monthly_cents'] ?? 1900);
                $amountCents = $event['amount_cents'] > 0 ? $event['amount_cents'] : $defaultAmount;

                $this->platform->savePayment([
                    'id' => 'pay_' . bin2hex(random_bytes(10)),
                    'user_id' => $userId,
                    'subscription_id' => $subRecord['id'] ?? null,
                    'plan_id' => $planId,
                    'provider' => $provider->getName(),
                    'provider_payment_id' => $event['provider_payment_id'] ?? $eventId,
                    'amount_cents' => $amountCents,
                    'currency' => $event['currency'],
                    'status' => 'succeeded',
                    'description' => sprintf('%s %s subscription (%s)', ucfirst($planId), $interval, $event['event_type']),
                    'created_at' => $nowIso,
                ]);
            }
        } elseif ($action === 'payment_failed') {
            if ($subRecord !== null) {
                $subRecord['status'] = 'past_due';
                $subRecord['updated_at'] = $nowIso;
                $this->platform->saveSubscription($subRecord);
            }
            if ($userId !== null) {
                $this->platform->savePayment([
                    'id' => 'pay_' . bin2hex(random_bytes(10)),
                    'user_id' => $userId,
                    'subscription_id' => $subRecord['id'] ?? null,
                    'plan_id' => $planId,
                    'provider' => $provider->getName(),
                    'provider_payment_id' => $event['provider_payment_id'] ?? $eventId,
                    'amount_cents' => $event['amount_cents'],
                    'currency' => $event['currency'],
                    'status' => 'failed',
                    'description' => sprintf('Failed payment (%s)', $event['event_type']),
                    'created_at' => $nowIso,
                ]);
            }
        } elseif ($action === 'subscription_canceled') {
            if ($subRecord !== null) {
                $subRecord['status'] = 'canceled';
                $subRecord['canceled_at'] = $nowIso;
                $subRecord['current_period_end'] = $nowIso;
                $subRecord['updated_at'] = $nowIso;
                $this->platform->saveSubscription($subRecord);
            }
            if ($userId !== null) {
                $user = $this->platform->findUserById($userId);
                if ($user !== null) {
                    $user['plan_id'] = 'free';
                    $user['updated_at'] = $nowIso;
                    $this->platform->saveUser($user);
                }
            }
        }

        $this->platform->recordWebhookEvent(
            $provider->getName(),
            $eventId,
            $event['event_type'],
            $payloadHash,
            'processed'
        );

        $this->logger->info('billing.webhook_processed', [
            'provider' => $provider->getName(),
            'event_id' => $eventId,
            'event_type' => $event['event_type'],
            'action' => $action,
        ]);

        return [
            'ok' => true,
            'duplicate' => false,
            'provider' => $provider->getName(),
            'event_id' => $eventId,
            'action' => $action,
        ];
    }
}

