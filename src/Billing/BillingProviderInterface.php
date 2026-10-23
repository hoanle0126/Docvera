<?php

declare(strict_types=1);

namespace Docvera\Billing;

interface BillingProviderInterface
{
    public function getName(): string;

    public function getDisplayLabel(): string;

    public function isEnabled(): bool;

    public function isConfigured(): bool;

    public function getMode(): string;

    /**
     * Initiates a subscription checkout session for a user and plan.
     * Never fakes payment completion; returns a pending checkout descriptor that completes only via verified webhook/callback.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $plan
     * @return array{provider: string, mode: string, checkout_id: string, checkout_url: string, subscription_id: string, interval: string}
     */
    public function createCheckoutSession(array $user, array $plan, string $interval, string $returnBaseUrl): array;

    /**
     * Verifies the cryptographic signature of an incoming webhook payload and normalizes the event.
     *
     * @param array<string, mixed> $headers
     * @return array{
     *     event_id: string,
     *     event_type: string,
     *     normalized_action: string,
     *     user_id: ?string,
     *     plan_id: ?string,
     *     provider_subscription_id: ?string,
     *     provider_customer_id: ?string,
     *     provider_payment_id: ?string,
     *     billing_interval: string,
     *     amount_cents: int,
     *     currency: string,
     *     period_end: ?string
     * }
     */
    public function verifyAndParseWebhook(string $rawBody, array $headers): array;
}

