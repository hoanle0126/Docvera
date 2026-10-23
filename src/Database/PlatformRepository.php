<?php

declare(strict_types=1);

namespace Docvera\Database;

use Docvera\Core\Config;

/**
 * Unified repository for Phase 2 SaaS, Entitlements, Billing, API Keys, Tool Overrides,
 * System Settings, and CMS Pages across both PDO (mysql/sqlite) and atomic file storage.
 */
final class PlatformRepository
{
    private Connection $connection;
    private Config $config;

    public function __construct(Connection $connection, Config $config)
    {
        $this->connection = $connection;
        $this->config = $config;
        $this->ensureDefaultsSeeded();
    }

    public function ensureDefaultsSeeded(): void
    {
        $existingMode = $this->getSetting('deployment_mode', null);
        if ($existingMode === null) {
            $defaults = $this->defaultSettingsMap();
            foreach ($defaults as $k => $v) {
                if ($this->getSetting($k, null) === null) {
                    $this->setSetting($k, $v);
                }
            }
        }

        $plans = $this->listPlans(false);
        if ($plans === []) {
            foreach ($this->defaultPlans() as $plan) {
                $this->savePlan($plan);
            }
        }

        $pages = $this->listCmsPages(false);
        if ($pages === []) {
            foreach ($this->defaultCmsPages() as $page) {
                $this->saveCmsPage($page);
            }
        }
    }

    // =========================================================================
    // SYSTEM SETTINGS & MULTI-DEPLOYMENT MODES
    // =========================================================================

    public function getSetting(string $key, mixed $default = null): mixed
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return $default;
            }
            $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :k LIMIT 1');
            $stmt->execute(['k' => $key]);
            $row = $stmt->fetch();
            if (!is_array($row) || !array_key_exists('setting_value', $row)) {
                return $default;
            }
            return $this->decodeSettingValue((string) $row['setting_value']);
        }

        $store = $this->connection->readFileStore();
        $row = $store['system_settings'][$key] ?? null;
        if (!is_array($row) || !array_key_exists('setting_value', $row)) {
            return $default;
        }
        return $this->decodeSettingValue((string) $row['setting_value']);
    }

    public function setSetting(string $key, mixed $value): void
    {
        $encoded = is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        $now = gmdate('Y-m-d\TH:i:s\Z');

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return;
            }
            $existing = $this->getSetting($key, '__NOT_SET__');
            if ($existing === '__NOT_SET__') {
                $stmt = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, :u)');
            } else {
                $stmt = $pdo->prepare('UPDATE system_settings SET setting_value = :v, updated_at = :u WHERE setting_key = :k');
            }
            $stmt->execute(['k' => $key, 'v' => $encoded, 'u' => $now]);
            return;
        }

        $this->connection->mutateFileStore(static function (array $store) use ($key, $encoded, $now): array {
            $store['system_settings'][$key] = [
                'setting_key' => $key,
                'setting_value' => $encoded,
                'updated_at' => $now,
            ];
            return $store;
        });
    }

    /**
     * @param array<string, mixed> $pairs
     */
    public function setSettings(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            $this->setSetting((string) $k, $v);
        }
    }

    /**
     * @param array<string, mixed> $pairs
     */
    public function saveSettings(array $pairs): void
    {
        $this->setSettings($pairs);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAllSettings(): array
    {
        $defaults = $this->defaultSettingsMap();

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return $defaults;
            }
            $rows = $pdo->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
            foreach ($rows as $row) {
                $defaults[(string) $row['setting_key']] = $this->decodeSettingValue((string) $row['setting_value']);
            }
            return $defaults;
        }

        $store = $this->connection->readFileStore();
        foreach (($store['system_settings'] ?? []) as $k => $row) {
            if (is_array($row) && isset($row['setting_value'])) {
                $defaults[(string) $k] = $this->decodeSettingValue((string) $row['setting_value']);
            }
        }
        return $defaults;
    }

    private function decodeSettingValue(string $raw): mixed
    {
        if ($raw === 'true') {
            return true;
        }
        if ($raw === 'false') {
            return false;
        }
        if (str_starts_with($raw, '{') || str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return $raw;
    }

    // =========================================================================
    // USERS
    // =========================================================================

    /**
     * @param array<string, mixed> $user
     */
    public function saveUser(array $user): void
    {
        $id = (string) $user['id'];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $record = [
            'id' => $id,
            'email' => strtolower(trim((string) $user['email'])),
            'name' => trim((string) ($user['name'] ?? 'User')),
            'password_hash' => (string) $user['password_hash'],
            'role' => (string) ($user['role'] ?? 'user'),
            'plan_id' => (string) ($user['plan_id'] ?? 'free'),
            'status' => (string) ($user['status'] ?? 'active'),
            'api_enabled_override' => isset($user['api_enabled_override']) && $user['api_enabled_override'] !== null
                ? (int) (bool) $user['api_enabled_override']
                : null,
            'created_at' => (string) ($user['created_at'] ?? $now),
            'updated_at' => (string) ($user['updated_at'] ?? $now),
            'last_login_at' => $user['last_login_at'] ?? null,
        ];

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return;
            }
            $existing = $this->findUserById($id);
            if ($existing === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (id, email, name, password_hash, role, plan_id, status, api_enabled_override, created_at, updated_at, last_login_at)
                     VALUES (:id, :email, :name, :password_hash, :role, :plan_id, :status, :api_enabled_override, :created_at, :updated_at, :last_login_at)'
                );
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE users SET email = :email, name = :name, password_hash = :password_hash, role = :role,
                     plan_id = :plan_id, status = :status, api_enabled_override = :api_enabled_override,
                     created_at = :created_at, updated_at = :updated_at, last_login_at = :last_login_at WHERE id = :id'
                );
            }
            $stmt->execute($record);
            return;
        }

        $this->connection->mutateFileStore(static function (array $store) use ($id, $record): array {
            $store['users'][$id] = $record;
            return $store;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserById(string $id): ?array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return null;
            }
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        }

        $store = $this->connection->readFileStore();
        $row = $store['users'][$id] ?? null;
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByEmail(string $email): ?array
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return null;
        }

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return null;
            }
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $normalized]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        }

        $store = $this->connection->readFileStore();
        foreach (($store['users'] ?? []) as $row) {
            if (is_array($row) && strtolower((string) ($row['email'] ?? '')) === $normalized) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUsers(): array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }
            $rows = $pdo->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();
            return array_values($rows);
        }

        $store = $this->connection->readFileStore();
        $users = array_values(array_filter($store['users'] ?? [], 'is_array'));
        usort($users, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $users;
    }

    // =========================================================================
    // PLANS & ENTITLEMENTS
    // =========================================================================

    /**
     * @param array<string, mixed> $plan
     */
    public function savePlan(array $plan): void
    {
        $id = (string) $plan['id'];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $entitlements = isset($plan['entitlements']) && is_array($plan['entitlements'])
            ? json_encode($plan['entitlements'], JSON_UNESCAPED_SLASHES)
            : (string) ($plan['entitlements_json'] ?? '{}');

        $record = [
            'id' => $id,
            'name' => (string) ($plan['name'] ?? ucfirst($id)),
            'slug' => (string) ($plan['slug'] ?? $id),
            'description' => (string) ($plan['description'] ?? ''),
            'is_public' => (int) (bool) ($plan['is_public'] ?? true),
            'is_default' => (int) (bool) ($plan['is_default'] ?? false),
            'price_monthly_cents' => (int) ($plan['price_monthly_cents'] ?? 0),
            'price_yearly_cents' => (int) ($plan['price_yearly_cents'] ?? 0),
            'currency' => strtoupper((string) ($plan['currency'] ?? 'USD')),
            'stripe_price_monthly_id' => $plan['stripe_price_monthly_id'] ?? null,
            'stripe_price_yearly_id' => $plan['stripe_price_yearly_id'] ?? null,
            'paypal_plan_monthly_id' => $plan['paypal_plan_monthly_id'] ?? null,
            'paypal_plan_yearly_id' => $plan['paypal_plan_yearly_id'] ?? null,
            'entitlements_json' => $entitlements,
            'sort_order' => (int) ($plan['sort_order'] ?? 0),
            'updated_at' => (string) ($plan['updated_at'] ?? $now),
        ];

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return;
            }
            $existing = $this->findPlan($id);
            if ($existing === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO plans (
                        id, name, slug, description, is_public, is_default,
                        price_monthly_cents, price_yearly_cents, currency,
                        stripe_price_monthly_id, stripe_price_yearly_id,
                        paypal_plan_monthly_id, paypal_plan_yearly_id,
                        entitlements_json, sort_order, updated_at
                    ) VALUES (
                        :id, :name, :slug, :description, :is_public, :is_default,
                        :price_monthly_cents, :price_yearly_cents, :currency,
                        :stripe_price_monthly_id, :stripe_price_yearly_id,
                        :paypal_plan_monthly_id, :paypal_plan_yearly_id,
                        :entitlements_json, :sort_order, :updated_at
                    )'
                );
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE plans SET
                        name = :name, slug = :slug, description = :description,
                        is_public = :is_public, is_default = :is_default,
                        price_monthly_cents = :price_monthly_cents, price_yearly_cents = :price_yearly_cents,
                        currency = :currency,
                        stripe_price_monthly_id = :stripe_price_monthly_id, stripe_price_yearly_id = :stripe_price_yearly_id,
                        paypal_plan_monthly_id = :paypal_plan_monthly_id, paypal_plan_yearly_id = :paypal_plan_yearly_id,
                        entitlements_json = :entitlements_json, sort_order = :sort_order, updated_at = :updated_at
                     WHERE id = :id'
                );
            }
            $stmt->execute($record);
            return;
        }

        $this->connection->mutateFileStore(static function (array $store) use ($id, $record): array {
            $store['plans'][$id] = $record;
            return $store;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPlan(string $id): ?array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return null;
            }
            $stmt = $pdo->prepare('SELECT * FROM plans WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            return is_array($row) ? $this->hydratePlan($row) : null;
        }

        $store = $this->connection->readFileStore();
        $row = $store['plans'][$id] ?? null;
        return is_array($row) ? $this->hydratePlan($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPlans(bool $publicOnly = false): array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }
            $sql = $publicOnly
                ? 'SELECT * FROM plans WHERE is_public = 1 ORDER BY sort_order ASC, price_monthly_cents ASC'
                : 'SELECT * FROM plans ORDER BY sort_order ASC, price_monthly_cents ASC';
            $rows = $pdo->query($sql)->fetchAll();
            return array_values(array_map([$this, 'hydratePlan'], $rows));
        }

        $store = $this->connection->readFileStore();
        $plans = [];
        foreach (($store['plans'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($publicOnly && (int) ($row['is_public'] ?? 0) !== 1) {
                continue;
            }
            $plans[] = $this->hydratePlan($row);
        }
        usort($plans, static fn(array $a, array $b): int => ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0)));
        return $plans;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydratePlan(array $row): array
    {
        $entitlements = [];
        if (!empty($row['entitlements_json']) && is_string($row['entitlements_json'])) {
            $decoded = json_decode($row['entitlements_json'], true);
            if (is_array($decoded)) {
                $entitlements = $decoded;
            }
        }
        $row['entitlements'] = array_merge($this->defaultEntitlementsSchema(), $entitlements);
        $row['is_public'] = (bool) ($row['is_public'] ?? true);
        $row['is_default'] = (bool) ($row['is_default'] ?? false);
        return $row;
    }

    // =========================================================================
    // SUBSCRIPTIONS, PAYMENTS & WEBHOOK EVENTS
    // =========================================================================

    /**
     * @param array<string, mixed> $sub
     */
    public function saveSubscription(array $sub): void
    {
        $id = (string) $sub['id'];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $record = [
            'id' => $id,
            'user_id' => (string) $sub['user_id'],
            'plan_id' => (string) $sub['plan_id'],
            'provider' => (string) ($sub['provider'] ?? 'stripe'),
            'provider_subscription_id' => $sub['provider_subscription_id'] ?? null,
            'provider_customer_id' => $sub['provider_customer_id'] ?? null,
            'billing_interval' => (string) ($sub['billing_interval'] ?? 'monthly'),
            'status' => (string) ($sub['status'] ?? 'active'),
            'cancel_at_period_end' => (int) (bool) ($sub['cancel_at_period_end'] ?? false),
            'current_period_start' => (string) ($sub['current_period_start'] ?? $now),
            'current_period_end' => (string) ($sub['current_period_end'] ?? gmdate('Y-m-d\TH:i:s\Z', time() + 2592000)),
            'canceled_at' => $sub['canceled_at'] ?? null,
            'created_at' => (string) ($sub['created_at'] ?? $now),
            'updated_at' => (string) ($sub['updated_at'] ?? $now),
        ];

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return;
            }
            $existing = $this->findSubscription($id);
            if ($existing === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO subscriptions (
                        id, user_id, plan_id, provider, provider_subscription_id, provider_customer_id,
                        billing_interval, status, cancel_at_period_end, current_period_start, current_period_end,
                        canceled_at, created_at, updated_at
                    ) VALUES (
                        :id, :user_id, :plan_id, :provider, :provider_subscription_id, :provider_customer_id,
                        :billing_interval, :status, :cancel_at_period_end, :current_period_start, :current_period_end,
                        :canceled_at, :created_at, :updated_at
                    )'
                );
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE subscriptions SET
                        user_id = :user_id, plan_id = :plan_id, provider = :provider,
                        provider_subscription_id = :provider_subscription_id, provider_customer_id = :provider_customer_id,
                        billing_interval = :billing_interval, status = :status, cancel_at_period_end = :cancel_at_period_end,
                        current_period_start = :current_period_start, current_period_end = :current_period_end,
                        canceled_at = :canceled_at, created_at = :created_at, updated_at = :updated_at
                     WHERE id = :id'
                );
            }
            $stmt->execute($record);
            return;
        }

        $this->connection->mutateFileStore(static function (array $store) use ($id, $record): array {
            $store['subscriptions'][$id] = $record;
            return $store;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSubscription(string $id): ?array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return null;
            }
            $stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        }

        $store = $this->connection->readFileStore();
        $row = $store['subscriptions'][$id] ?? null;
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByProviderSubscriptionId(string $provider, string $providerSubId): ?array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return null;
            }
            $stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE provider = :p AND provider_subscription_id = :s LIMIT 1');
            $stmt->execute(['p' => $provider, 's' => $providerSubId]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        }

        $store = $this->connection->readFileStore();
        foreach (($store['subscriptions'] ?? []) as $row) {
            if (is_array($row)
                && (string) ($row['provider'] ?? '') === $provider
                && (string) ($row['provider_subscription_id'] ?? '') === $providerSubId
            ) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestSubscriptionForUser(string $userId): ?array
    {
        $subs = $this->listSubscriptions($userId);
        return $subs[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSubscriptions(?string $userId = null): array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }
            if ($userId !== null) {
                $stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE user_id = :u ORDER BY created_at DESC');
                $stmt->execute(['u' => $userId]);
                return array_values($stmt->fetchAll());
            }
            $rows = $pdo->query('SELECT * FROM subscriptions ORDER BY created_at DESC')->fetchAll();
            return array_values($rows);
        }

        $store = $this->connection->readFileStore();
        $list = [];
        foreach (($store['subscriptions'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($userId !== null && (string) ($row['user_id'] ?? '') !== $userId) {
                continue;
            }
            $list[] = $row;
        }
        usort($list, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $list;
    }

    /**
     * @param array<string, mixed> $payment
     */
    public function savePayment(array $payment): void
    {
        $id = (string) ($payment['id'] ?? ('pay_' . bin2hex(random_bytes(12))));
        $record = [
            'id' => $id,
            'user_id' => (string) $payment['user_id'],
            'subscription_id' => $payment['subscription_id'] ?? null,
            'plan_id' => (string) ($payment['plan_id'] ?? 'pro'),
            'provider' => (string) ($payment['provider'] ?? 'stripe'),
            'provider_payment_id' => (string) ($payment['provider_payment_id'] ?? $id),
            'amount_cents' => (int) ($payment['amount_cents'] ?? 0),
            'currency' => strtoupper((string) ($payment['currency'] ?? 'USD')),
            'status' => (string) ($payment['status'] ?? 'succeeded'),
            'description' => $payment['description'] ?? null,
            'created_at' => (string) ($payment['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
        ];

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO payments (id, user_id, subscription_id, plan_id, provider, provider_payment_id, amount_cents, currency, status, description, created_at)
                 VALUES (:id, :user_id, :subscription_id, :plan_id, :provider, :provider_payment_id, :amount_cents, :currency, :status, :description, :created_at)'
            );
            $stmt->execute($record);
            return;
        }

        $this->connection->mutateFileStore(static function (array $store) use ($id, $record): array {
            $store['payments'][$id] = $record;
            return $store;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPayments(?string $userId = null): array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }
            if ($userId !== null) {
                $stmt = $pdo->prepare('SELECT * FROM payments WHERE user_id = :u ORDER BY created_at DESC');
                $stmt->execute(['u' => $userId]);
                return array_values($stmt->fetchAll());
            }
            $rows = $pdo->query('SELECT * FROM payments ORDER BY created_at DESC')->fetchAll();
            return array_values($rows);
        }

        $store = $this->connection->readFileStore();
        $list = [];
        foreach (($store['payments'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($userId !== null && (string) ($row['user_id'] ?? '') !== $userId) {
                continue;
            }
            $list[] = $row;
        }
        usort($list, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $list;
    }

    public function hasProcessedWebhook(string $provider, string $eventId): bool
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return false;
            }
            $stmt = $pdo->prepare('SELECT id FROM webhook_events WHERE provider = :p AND event_id = :e LIMIT 1');
            $stmt->execute(['p' => $provider, 'e' => $eventId]);
            return $stmt->fetch() !== false;
        }

        $store = $this->connection->readFileStore();
        $composite = $provider . ':' . $eventId;
        if (isset($store['webhook_events'][$composite])) {
            return true;
        }
        foreach (($store['webhook_events'] ?? []) as $row) {
            if (is_array($row) && (string) ($row['provider'] ?? '') === $provider && (string) ($row['event_id'] ?? '') === $eventId) {
                return true;
            }
        }
        return false;
    }

    public function recordWebhookEvent(string $provider, string $eventId, string $eventType, string $payloadHash, string $status = 'processed'): void
    {
        $id = $provider . ':' . $eventId;
        $record = [
            'id' => $id,
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payload_hash' => $payloadHash,
            'status' => $status,
            'processed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO webhook_events (id, provider, event_id, event_type, payload_hash, status, processed_at)
                 VALUES (:id, :provider, :event_id, :event_type, :payload_hash, :status, :processed_at)'
            );
            $stmt->execute($record);
            return;
        }

        $this->connection->mutateFileStore(static function (array $store) use ($id, $record): array {
            $store['webhook_events'][$id] = $record;
            return $store;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWebhookEvents(int $limit = 50): array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }
            $rows = $pdo->query('SELECT * FROM webhook_events ORDER BY processed_at DESC LIMIT ' . max(1, $limit))->fetchAll();
            return array_values($rows);
        }

        $store = $this->connection->readFileStore();
        $list = array_values(array_filter($store['webhook_events'] ?? [], 'is_array'));
        usort($list, static fn(array $a, array $b): int => strcmp((string) ($b['processed_at'] ?? ''), (string) ($a['processed_at'] ?? '')));
        return array_slice($list, 0, max(1, $limit));
    }

    // =========================================================================
    // CONCURRENCY-SAFE USAGE BUCKETS & QUOTAS
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    public function getUsageBucket(string $subjectKey, string $subjectType, string $periodType, string $periodKey): array
    {
        $bucketKey = $subjectKey . ':' . $periodType . ':' . $periodKey;
        $default = [
            'bucket_key' => $bucketKey,
            'subject_key' => $subjectKey,
            'subject_type' => $subjectType,
            'period_type' => $periodType,
            'period_key' => $periodKey,
            'ops_count' => 0,
            'api_requests_count' => 0,
            'bytes_processed' => 0,
            'ocr_ops_count' => 0,
            'active_concurrent_jobs' => 0,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return $default;
            }
            $stmt = $pdo->prepare('SELECT * FROM usage_buckets WHERE bucket_key = :k LIMIT 1');
            $stmt->execute(['k' => $bucketKey]);
            $row = $stmt->fetch();
            return is_array($row) ? array_merge($default, $row) : $default;
        }

        $store = $this->connection->readFileStore();
        $row = $store['usage_buckets'][$bucketKey] ?? null;
        return is_array($row) ? array_merge($default, $row) : $default;
    }

    /**
     * Atomically inspects and mutates a usage bucket under lock/transaction.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    public function mutateUsageBucket(
        string $subjectKey,
        string $subjectType,
        string $periodType,
        string $periodKey,
        callable $callback
    ): array {
        $bucketKey = $subjectKey . ':' . $periodType . ':' . $periodKey;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $default = [
            'bucket_key' => $bucketKey,
            'subject_key' => $subjectKey,
            'subject_type' => $subjectType,
            'period_type' => $periodType,
            'period_key' => $periodKey,
            'ops_count' => 0,
            'api_requests_count' => 0,
            'bytes_processed' => 0,
            'ocr_ops_count' => 0,
            'active_concurrent_jobs' => 0,
            'updated_at' => $now,
        ];

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return $default;
            }
            $inTx = $pdo->inTransaction();
            if (!$inTx) {
                $pdo->beginTransaction();
            }
            try {
                $stmt = $pdo->prepare('SELECT * FROM usage_buckets WHERE bucket_key = :k LIMIT 1');
                $stmt->execute(['k' => $bucketKey]);
                $existing = $stmt->fetch();
                $current = is_array($existing) ? array_merge($default, $existing) : $default;
                $updated = $callback($current);
                $updated['updated_at'] = $now;

                if (!is_array($existing)) {
                    $ins = $pdo->prepare(
                        'INSERT INTO usage_buckets (
                            bucket_key, subject_key, subject_type, period_type, period_key,
                            ops_count, api_requests_count, bytes_processed, ocr_ops_count, active_concurrent_jobs, updated_at
                        ) VALUES (
                            :bucket_key, :subject_key, :subject_type, :period_type, :period_key,
                            :ops_count, :api_requests_count, :bytes_processed, :ocr_ops_count, :active_concurrent_jobs, :updated_at
                        )'
                    );
                    $ins->execute([
                        'bucket_key' => $bucketKey,
                        'subject_key' => $subjectKey,
                        'subject_type' => $subjectType,
                        'period_type' => $periodType,
                        'period_key' => $periodKey,
                        'ops_count' => (int) ($updated['ops_count'] ?? 0),
                        'api_requests_count' => (int) ($updated['api_requests_count'] ?? 0),
                        'bytes_processed' => (int) ($updated['bytes_processed'] ?? 0),
                        'ocr_ops_count' => (int) ($updated['ocr_ops_count'] ?? 0),
                        'active_concurrent_jobs' => max(0, (int) ($updated['active_concurrent_jobs'] ?? 0)),
                        'updated_at' => $now,
                    ]);
                } else {
                    $upd = $pdo->prepare(
                        'UPDATE usage_buckets SET
                            ops_count = :ops_count,
                            api_requests_count = :api_requests_count,
                            bytes_processed = :bytes_processed,
                            ocr_ops_count = :ocr_ops_count,
                            active_concurrent_jobs = :active_concurrent_jobs,
                            updated_at = :updated_at
                         WHERE bucket_key = :bucket_key'
                    );
                    $upd->execute([
                        'bucket_key' => $bucketKey,
                        'ops_count' => (int) ($updated['ops_count'] ?? 0),
                        'api_requests_count' => (int) ($updated['api_requests_count'] ?? 0),
                        'bytes_processed' => (int) ($updated['bytes_processed'] ?? 0),
                        'ocr_ops_count' => (int) ($updated['ocr_ops_count'] ?? 0),
                        'active_concurrent_jobs' => max(0, (int) ($updated['active_concurrent_jobs'] ?? 0)),
                        'updated_at' => $now,
                    ]);
                }

                if (!$inTx) {
                    $pdo->commit();
                }
                return $updated;
            } catch (\Throwable $e) {
                if (!$inTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        $result = $default;
        $this->connection->mutateFileStore(static function (array $store) use ($bucketKey, $default, $callback, $now, &$result): array {
            $current = isset($store['usage_buckets'][$bucketKey]) && is_array($store['usage_buckets'][$bucketKey])
                ? array_merge($default, $store['usage_buckets'][$bucketKey])
                : $default;
            $updated = $callback($current);
            $updated['active_concurrent_jobs'] = max(0, (int) ($updated['active_concurrent_jobs'] ?? 0));
            $updated['updated_at'] = $now;
            $store['usage_buckets'][$bucketKey] = $updated;
            $result = $updated;
            return $store;
        });

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUsageBuckets(int $limit = 100): array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }
            $rows = $pdo->query('SELECT * FROM usage_buckets ORDER BY updated_at DESC LIMIT ' . max(1, $limit))->fetchAll();
            return array_values($rows);
        }

        $store = $this->connection->readFileStore();
        $list = array_values(array_filter($store['usage_buckets'] ?? [], 'is_array'));
        usort($list, static fn(array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
        return array_slice($list, 0, max(1, $limit));
    }

    // =========================================================================
    // API KEYS & API AUDIT LOGS
    // =========================================================================

    /**
     * @param array<string, mixed> $apiKey
     */
    public function saveApiKey(array $apiKey): void
    {
        $id = (string) $apiKey['id'];
        $scopes = isset($apiKey['scopes']) && is_array($apiKey['scopes'])
            ? json_encode(array_values($apiKey['scopes']), JSON_UNESCAPED_SLASHES)
            : (string) ($apiKey['scopes_json'] ?? '["tools:execute","jobs:read","jobs:download","jobs:delete"]');

        $record = [
            'id' => $id,
            'user_id' => (string) ($apiKey['user_id'] ?? ''),
            'name' => trim((string) ($apiKey['name'] ?? 'Default Key')),
            'key_prefix' => (string) ($apiKey['key_prefix'] ?? 'dcv_live_'),
            'key_hash' => (string) $apiKey['key_hash'],
            'scopes_json' => $scopes,
            'rate_limit_per_min' => (int) ($apiKey['rate_limit_per_min'] ?? 60),
            'is_active' => (int) (bool) ($apiKey['is_active'] ?? true),
            'last_used_at' => $apiKey['last_used_at'] ?? null,
            'last_used_ip' => $apiKey['last_used_ip'] ?? null,
            'expires_at' => $apiKey['expires_at'] ?? null,
            'revoked_at' => $apiKey['revoked_at'] ?? null,
            'created_at' => (string) ($apiKey['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
        ];

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return;
            }
            $existing = $this->findApiKeyById($id);
            if ($existing === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO api_keys (
                        id, user_id, name, key_prefix, key_hash, scopes_json,
                        rate_limit_per_min, is_active, last_used_at, last_used_ip, expires_at, revoked_at, created_at
                    ) VALUES (
                        :id, :user_id, :name, :key_prefix, :key_hash, :scopes_json,
                        :rate_limit_per_min, :is_active, :last_used_at, :last_used_ip, :expires_at, :revoked_at, :created_at
                    )'
                );
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE api_keys SET
                        user_id = :user_id, name = :name, key_prefix = :key_prefix, key_hash = :key_hash,
                        scopes_json = :scopes_json, rate_limit_per_min = :rate_limit_per_min, is_active = :is_active,
                        last_used_at = :last_used_at, last_used_ip = :last_used_ip, expires_at = :expires_at,
                        revoked_at = :revoked_at, created_at = :created_at
                     WHERE id = :id'
                );
            }
            $stmt->execute($record);
            return;
        }

        $this->connection->mutateFileStore(static function (array $store) use ($id, $record): array {
            $store['api_keys'][$id] = $record;
            return $store;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findApiKeyById(string $id): ?array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return null;
            }
            $stmt = $pdo->prepare('SELECT * FROM api_keys WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            return is_array($row) ? $this->hydrateApiKey($row) : null;
        }

        $store = $this->connection->readFileStore();
        $row = $store['api_keys'][$id] ?? null;
        return is_array($row) ? $this->hydrateApiKey($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findApiKeyByHash(string $keyHash): ?array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return null;
            }
            $stmt = $pdo->prepare('SELECT * FROM api_keys WHERE key_hash = :h LIMIT 1');
            $stmt->execute(['h' => $keyHash]);
            $row = $stmt->fetch();
            return is_array($row) ? $this->hydrateApiKey($row) : null;
        }

        $store = $this->connection->readFileStore();
        foreach (($store['api_keys'] ?? []) as $row) {
            if (is_array($row) && hash_equals((string) ($row['key_hash'] ?? ''), $keyHash)) {
                return $this->hydrateApiKey($row);
            }
        }
        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listApiKeys(?string $userId = null): array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }
            if ($userId !== null) {
                $stmt = $pdo->prepare('SELECT * FROM api_keys WHERE user_id = :u ORDER BY created_at DESC');
                $stmt->execute(['u' => $userId]);
                $rows = $stmt->fetchAll();
            } else {
                $rows = $pdo->query('SELECT * FROM api_keys ORDER BY created_at DESC')->fetchAll();
            }
            return array_values(array_map([$this, 'hydrateApiKey'], $rows));
        }

        $store = $this->connection->readFileStore();
        $list = [];
        foreach (($store['api_keys'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($userId !== null && (string) ($row['user_id'] ?? '') !== $userId) {
                continue;
            }
            $list[] = $this->hydrateApiKey($row);
        }
        usort($list, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $list;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateApiKey(array $row): array
    {
        $scopes = ['tools:execute', 'jobs:read', 'jobs:download', 'jobs:delete'];
        if (!empty($row['scopes_json']) && is_string($row['scopes_json'])) {
            $decoded = json_decode($row['scopes_json'], true);
            if (is_array($decoded)) {
                $scopes = array_values(array_map('strval', $decoded));
            }
        }
        $row['scopes'] = $scopes;
        $row['is_active'] = ((int) ($row['is_active'] ?? 1)) === 1 && ($row['revoked_at'] ?? null) === null;
        return $row;
    }

    /**
     * @param array<string, mixed> $log
     */
    public function recordApiAuditLog(array $log): void
    {
        $id = (string) ($log['id'] ?? ('aud_' . bin2hex(random_bytes(12))));
        $responseJson = isset($log['response']) && is_array($log['response'])
            ? json_encode($log['response'], JSON_UNESCAPED_SLASHES)
            : ($log['response_json'] ?? null);

        $record = [
            'id' => $id,
            'request_id' => (string) ($log['request_id'] ?? ('req_' . bin2hex(random_bytes(8)))),
            'api_key_id' => $log['api_key_id'] ?? null,
            'user_id' => $log['user_id'] ?? null,
            'endpoint' => (string) ($log['endpoint'] ?? ''),
            'method' => (string) ($log['method'] ?? 'POST'),
            'tool_id' => $log['tool_id'] ?? null,
            'status_code' => (int) ($log['status_code'] ?? 200),
            'ip_address' => $log['ip_address'] ?? null,
            'duration_ms' => (int) ($log['duration_ms'] ?? 0),
            'idempotency_key' => $log['idempotency_key'] ?? null,
            'response_json' => $responseJson,
            'created_at' => (string) ($log['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
        ];

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO api_audit_logs (
                    id, request_id, api_key_id, user_id, endpoint, method, tool_id,
                    status_code, ip_address, duration_ms, idempotency_key, response_json, created_at
                ) VALUES (
                    :id, :request_id, :api_key_id, :user_id, :endpoint, :method, :tool_id,
                    :status_code, :ip_address, :duration_ms, :idempotency_key, :response_json, :created_at
                )'
            );
            $stmt->execute($record);
            return;
        }

        $this->connection->mutateFileStore(static function (array $store) use ($id, $record): array {
            $store['api_audit_logs'][$id] = $record;
            return $store;
        });
    }

    /**
     * @return array{status_code: int, response: array<string, mixed>}|null
     */
    public function findIdempotentResponse(string $apiKeyId, string $endpoint, string $idempotencyKey): ?array
    {
        if ($idempotencyKey === '') {
            return null;
        }

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return null;
            }
            $stmt = $pdo->prepare(
                'SELECT status_code, response_json FROM api_audit_logs
                 WHERE api_key_id = :k AND endpoint = :e AND idempotency_key = :i AND response_json IS NOT NULL
                 ORDER BY created_at DESC LIMIT 1'
            );
            $stmt->execute(['k' => $apiKeyId, 'e' => $endpoint, 'i' => $idempotencyKey]);
            $row = $stmt->fetch();
            if (is_array($row) && !empty($row['response_json'])) {
                $decoded = json_decode((string) $row['response_json'], true);
                if (is_array($decoded)) {
                    return [
                        'status_code' => (int) $row['status_code'],
                        'response' => $decoded,
                    ];
                }
            }
            return null;
        }

        $store = $this->connection->readFileStore();
        foreach (array_reverse($store['api_audit_logs'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['api_key_id'] ?? '') === $apiKeyId
                && (string) ($row['endpoint'] ?? '') === $endpoint
                && (string) ($row['idempotency_key'] ?? '') === $idempotencyKey
                && !empty($row['response_json'])
            ) {
                $decoded = json_decode((string) $row['response_json'], true);
                if (is_array($decoded)) {
                    return [
                        'status_code' => (int) ($row['status_code'] ?? 200),
                        'response' => $decoded,
                    ];
                }
            }
        }
        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listApiAuditLogs(?string $userId = null, int $limit = 100): array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }
            if ($userId !== null) {
                $stmt = $pdo->prepare('SELECT * FROM api_audit_logs WHERE user_id = :u ORDER BY created_at DESC LIMIT ' . max(1, $limit));
                $stmt->execute(['u' => $userId]);
                return array_values($stmt->fetchAll());
            }
            $rows = $pdo->query('SELECT * FROM api_audit_logs ORDER BY created_at DESC LIMIT ' . max(1, $limit))->fetchAll();
            return array_values($rows);
        }

        $store = $this->connection->readFileStore();
        $list = [];
        foreach (($store['api_audit_logs'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($userId !== null && (string) ($row['user_id'] ?? '') !== $userId) {
                continue;
            }
            $list[] = $row;
        }
        usort($list, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return array_slice($list, 0, max(1, $limit));
    }

    // =========================================================================
    // TOOL OVERRIDES & SEO
    // =========================================================================

    /**
     * @param array<string, mixed> $override
     */
    public function saveToolOverride(array $override): void
    {
        $toolId = (string) $override['tool_id'];
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $allowedPlansJson = isset($override['allowed_plans']) && is_array($override['allowed_plans'])
            ? json_encode(array_values($override['allowed_plans']), JSON_UNESCAPED_SLASHES)
            : ($override['allowed_plans_json'] ?? null);

        $defaultOptsJson = isset($override['default_options']) && is_array($override['default_options'])
            ? json_encode($override['default_options'], JSON_UNESCAPED_SLASHES)
            : ($override['default_options_json'] ?? null);

        $record = [
            'tool_id' => $toolId,
            'is_enabled' => (int) (bool) ($override['is_enabled'] ?? true),
            'guest_allowed' => (int) (bool) ($override['guest_allowed'] ?? true),
            'allowed_plans_json' => $allowedPlansJson,
            'max_file_size_mb' => isset($override['max_file_size_mb']) && $override['max_file_size_mb'] !== '' && (int) $override['max_file_size_mb'] > 0
                ? (int) $override['max_file_size_mb']
                : null,
            'max_files' => isset($override['max_files']) && $override['max_files'] !== '' && (int) $override['max_files'] > 0
                ? (int) $override['max_files']
                : null,
            'api_enabled' => (int) (bool) ($override['api_enabled'] ?? true),
            'default_options_json' => $defaultOptsJson,
            'seo_title' => isset($override['seo_title']) && trim((string) $override['seo_title']) !== '' ? trim((string) $override['seo_title']) : null,
            'seo_description' => isset($override['seo_description']) && trim((string) $override['seo_description']) !== '' ? trim((string) $override['seo_description']) : null,
            'seo_canonical' => isset($override['seo_canonical']) && trim((string) $override['seo_canonical']) !== '' ? trim((string) $override['seo_canonical']) : null,
            'seo_indexable' => (int) (bool) ($override['seo_indexable'] ?? true),
            'seo_in_sitemap' => (int) (bool) ($override['seo_in_sitemap'] ?? true),
            'updated_at' => $now,
        ];

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return;
            }
            $existing = $this->getToolOverride($toolId);
            if ($existing === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO tool_overrides (
                        tool_id, is_enabled, guest_allowed, allowed_plans_json, max_file_size_mb, max_files,
                        api_enabled, default_options_json, seo_title, seo_description, seo_canonical,
                        seo_indexable, seo_in_sitemap, updated_at
                    ) VALUES (
                        :tool_id, :is_enabled, :guest_allowed, :allowed_plans_json, :max_file_size_mb, :max_files,
                        :api_enabled, :default_options_json, :seo_title, :seo_description, :seo_canonical,
                        :seo_indexable, :seo_in_sitemap, :updated_at
                    )'
                );
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE tool_overrides SET
                        is_enabled = :is_enabled, guest_allowed = :guest_allowed, allowed_plans_json = :allowed_plans_json,
                        max_file_size_mb = :max_file_size_mb, max_files = :max_files, api_enabled = :api_enabled,
                        default_options_json = :default_options_json, seo_title = :seo_title, seo_description = :seo_description,
                        seo_canonical = :seo_canonical, seo_indexable = :seo_indexable, seo_in_sitemap = :seo_in_sitemap,
                        updated_at = :updated_at
                     WHERE tool_id = :tool_id'
                );
            }
            $stmt->execute($record);
            return;
        }

        $this->connection->mutateFileStore(static function (array $store) use ($toolId, $record): array {
            $store['tool_overrides'][$toolId] = $record;
            return $store;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getToolOverride(string $toolId): ?array
    {
        $all = $this->getAllToolOverrides();
        return $all[$toolId] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAllToolOverrides(): array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }
            $rows = $pdo->query('SELECT * FROM tool_overrides')->fetchAll();
            $map = [];
            foreach ($rows as $row) {
                $map[(string) $row['tool_id']] = $this->hydrateToolOverride($row);
            }
            return $map;
        }

        $store = $this->connection->readFileStore();
        $map = [];
        foreach (($store['tool_overrides'] ?? []) as $id => $row) {
            if (is_array($row)) {
                $map[(string) $id] = $this->hydrateToolOverride($row);
            }
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateToolOverride(array $row): array
    {
        $row['is_enabled'] = ((int) ($row['is_enabled'] ?? 1)) === 1;
        $row['guest_allowed'] = ((int) ($row['guest_allowed'] ?? 1)) === 1;
        $row['api_enabled'] = ((int) ($row['api_enabled'] ?? 1)) === 1;
        $row['seo_indexable'] = ((int) ($row['seo_indexable'] ?? 1)) === 1;
        $row['seo_in_sitemap'] = ((int) ($row['seo_in_sitemap'] ?? 1)) === 1;
        $row['allowed_plans'] = !empty($row['allowed_plans_json']) && is_string($row['allowed_plans_json'])
            ? (json_decode($row['allowed_plans_json'], true) ?: [])
            : [];
        $row['default_options'] = !empty($row['default_options_json']) && is_string($row['default_options_json'])
            ? (json_decode($row['default_options_json'], true) ?: [])
            : [];
        return $row;
    }

    // =========================================================================
    // CMS PAGES
    // =========================================================================

    /**
     * @param array<string, mixed> $page
     */
    public function saveCmsPage(array $page): void
    {
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $page['slug'])) ?: 'page';
        $contentText = (string) ($page['content'] ?? $page['body'] ?? '');
        $record = [
            'slug' => $slug,
            'title' => trim((string) ($page['title'] ?? ucfirst($slug))),
            'meta_description' => isset($page['meta_description']) ? trim((string) $page['meta_description']) : null,
            'content' => $contentText,
            'is_published' => (int) (bool) ($page['is_published'] ?? true),
            'in_sitemap' => (int) (bool) ($page['in_sitemap'] ?? true),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return;
            }
            $existing = $this->getCmsPage($slug);
            if ($existing === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO cms_pages (slug, title, meta_description, content, is_published, in_sitemap, updated_at)
                     VALUES (:slug, :title, :meta_description, :content, :is_published, :in_sitemap, :updated_at)'
                );
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE cms_pages SET title = :title, meta_description = :meta_description, content = :content,
                     is_published = :is_published, in_sitemap = :in_sitemap, updated_at = :updated_at WHERE slug = :slug'
                );
            }
            $stmt->execute($record);
            return;
        }

        $this->connection->mutateFileStore(static function (array $store) use ($slug, $record): array {
            $store['cms_pages'][$slug] = $record;
            return $store;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCmsPage(string $slug): ?array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return null;
            }
            $stmt = $pdo->prepare('SELECT * FROM cms_pages WHERE slug = :s LIMIT 1');
            $stmt->execute(['s' => $slug]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return null;
            }
            $row['is_published'] = ((int) ($row['is_published'] ?? 1)) === 1;
            $row['in_sitemap'] = ((int) ($row['in_sitemap'] ?? 1)) === 1;
            $row['content'] = (string) ($row['content'] ?? $row['body'] ?? '');
            $row['body'] = $row['content'];
            return $row;
        }

        $store = $this->connection->readFileStore();
        $row = $store['cms_pages'][$slug] ?? null;
        if (!is_array($row)) {
            return null;
        }
        $row['is_published'] = ((int) ($row['is_published'] ?? 1)) === 1;
        $row['in_sitemap'] = ((int) ($row['in_sitemap'] ?? 1)) === 1;
        $row['content'] = (string) ($row['content'] ?? $row['body'] ?? '');
        $row['body'] = $row['content'];
        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCmsPages(bool $publishedOnly = false): array
    {
        if ($this->connection->isPdo()) {
            $pdo = $this->connection->getPdo();
            if ($pdo === null) {
                return [];
            }
            $sql = $publishedOnly
                ? 'SELECT * FROM cms_pages WHERE is_published = 1 ORDER BY slug ASC'
                : 'SELECT * FROM cms_pages ORDER BY slug ASC';
            $rows = $pdo->query($sql)->fetchAll();
            return array_values(array_map(static function (array $r): array {
                $r['is_published'] = ((int) ($r['is_published'] ?? 1)) === 1;
                $r['in_sitemap'] = ((int) ($r['in_sitemap'] ?? 1)) === 1;
                $r['content'] = (string) ($r['content'] ?? $r['body'] ?? '');
                $r['body'] = $r['content'];
                return $r;
            }, $rows));
        }

        $store = $this->connection->readFileStore();
        $list = [];
        foreach (($store['cms_pages'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['is_published'] = ((int) ($row['is_published'] ?? 1)) === 1;
            $row['in_sitemap'] = ((int) ($row['in_sitemap'] ?? 1)) === 1;
            $row['content'] = (string) ($row['content'] ?? $row['body'] ?? '');
            $row['body'] = $row['content'];
            if ($publishedOnly && !$row['is_published']) {
                continue;
            }
            $list[] = $row;
        }
        return $list;
    }

    // =========================================================================
    // SEED DEFAULTS
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    public function defaultEntitlementsSchema(): array
    {
        return [
            'ops_per_day' => 25,
            'ops_per_month' => 500,
            'max_file_size_mb' => (int) $this->config->get('limits.max_file_size_mb', 50),
            'max_files_per_op' => (int) $this->config->get('limits.max_files_per_job', 20),
            'max_pages_per_op' => 500,
            'concurrent_jobs' => 3,
            'ocr_allowed' => true,
            'batch_allowed' => true,
            'allowed_tools' => ['*'],
            'api_access' => false,
            'api_requests_per_day' => 0,
            'api_requests_per_month' => 0,
            'api_rate_limit_per_min' => 30,
            'retention_minutes' => (int) $this->config->get('limits.retention_ttl_minutes', 60),
            'ads_visible' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSettingsMap(): array
    {
        return [
            // Deployment Mode: 'saas' | 'free_ads' | 'private'
            'deployment_mode' => 'saas',
            'registration_mode' => 'open', // 'open' | 'invite_only' | 'closed'
            'guest_tools_enabled' => true,
            'billing_enabled' => true,
            'ads_enabled' => false,
            'ad_slot_header' => '',
            'ad_slot_tool_sidebar' => '',
            'ad_slot_result' => '',

            // Branding
            'site_name' => (string) $this->config->get('name', 'Docvera'),
            'site_tagline' => 'Self-hosted PDF and document tools',
            'accent_color' => '#c23b22',
            'support_email' => 'ops@localhost',
            'footer_notice' => 'Every document is processed on this server and never sent to a third-party cloud.',

            // Privacy & Retention
            'default_retention_minutes' => (int) $this->config->get('limits.retention_ttl_minutes', 60),
            'max_retention_minutes' => 1440,
            'allow_delete_now' => true,
            'processing_locality_notice' => 'Server-side local execution (isolated 0700 workspace; zero external cloud APIs)',

            // SEO
            'seo_default_title_suffix' => 'Docvera Document Workbench',
            'seo_default_description' => 'Self-hosted PDF and document tools for merging, splitting, compressing, encrypting, and converting files with strict local retention policies.',
            'seo_site_indexable' => true,
            'seo_sitemap_enabled' => true,

            // Billing Providers (Secret keys never exposed to client)
            'stripe_enabled' => false,
            'stripe_mode' => 'test', // 'test' | 'live'
            'stripe_publishable_key' => '',
            'stripe_secret_key' => '',
            'stripe_webhook_secret' => '',

            'paypal_enabled' => false,
            'paypal_mode' => 'sandbox', // 'sandbox' | 'live'
            'paypal_client_id' => '',
            'paypal_client_secret' => '',
            'paypal_webhook_id' => '',
            'paypal_webhook_secret' => '',

            // Security & Updates
            'api_global_enabled' => true,
            'schema_version' => '2.0.0',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultPlans(): array
    {
        $cfgMaxMb = (int) $this->config->get('limits.max_file_size_mb', 50);
        $cfgMaxFiles = (int) $this->config->get('limits.max_files_per_job', 20);
        $cfgRetention = (int) $this->config->get('limits.retention_ttl_minutes', 60);

        return [
            [
                'id' => 'guest',
                'name' => 'Guest Visitor',
                'slug' => 'guest',
                'description' => 'Anonymous browser session limits for quick single-document tasks.',
                'is_public' => false,
                'is_default' => false,
                'price_monthly_cents' => 0,
                'price_yearly_cents' => 0,
                'currency' => 'USD',
                'sort_order' => 0,
                'entitlements' => [
                    'ops_per_day' => 50,
                    'ops_per_month' => 500,
                    'max_file_size_mb' => $cfgMaxMb,
                    'max_files_per_op' => $cfgMaxFiles,
                    'max_pages_per_op' => 200,
                    'concurrent_jobs' => 2,
                    'ocr_allowed' => true,
                    'batch_allowed' => true,
                    'allowed_tools' => ['*'],
                    'api_access' => false,
                    'api_requests_per_day' => 0,
                    'api_requests_per_month' => 0,
                    'api_rate_limit_per_min' => 10,
                    'retention_minutes' => min(30, $cfgRetention),
                    'ads_visible' => true,
                ],
            ],
            [
                'id' => 'free',
                'name' => 'Starter (Free)',
                'slug' => 'free',
                'description' => 'Registered account with job history, immediate purge controls, and higher daily allowances.',
                'is_public' => true,
                'is_default' => true,
                'price_monthly_cents' => 0,
                'price_yearly_cents' => 0,
                'currency' => 'USD',
                'sort_order' => 1,
                'entitlements' => [
                    'ops_per_day' => 25,
                    'ops_per_month' => 300,
                    'max_file_size_mb' => max(25, $cfgMaxMb),
                    'max_files_per_op' => max(10, $cfgMaxFiles),
                    'max_pages_per_op' => 250,
                    'concurrent_jobs' => 2,
                    'ocr_allowed' => true,
                    'batch_allowed' => true,
                    'allowed_tools' => ['*'],
                    'api_access' => false,
                    'api_requests_per_day' => 0,
                    'api_requests_per_month' => 0,
                    'api_rate_limit_per_min' => 20,
                    'retention_minutes' => $cfgRetention,
                    'ads_visible' => true,
                ],
            ],
            [
                'id' => 'pro',
                'name' => 'Workbench Pro',
                'slug' => 'pro',
                'description' => 'High-volume document operations, full OCR & batch processing, extended retention, and REST API access.',
                'is_public' => true,
                'is_default' => false,
                'price_monthly_cents' => 1900,
                'price_yearly_cents' => 18000,
                'currency' => 'USD',
                'stripe_price_monthly_id' => 'price_pro_monthly',
                'stripe_price_yearly_id' => 'price_pro_yearly',
                'paypal_plan_monthly_id' => 'P-PRO-MONTHLY',
                'paypal_plan_yearly_id' => 'P-PRO-YEARLY',
                'sort_order' => 2,
                'entitlements' => [
                    'ops_per_day' => 500,
                    'ops_per_month' => 10000,
                    'max_file_size_mb' => 100,
                    'max_files_per_op' => 50,
                    'max_pages_per_op' => 1000,
                    'concurrent_jobs' => 5,
                    'ocr_allowed' => true,
                    'batch_allowed' => true,
                    'allowed_tools' => ['*'],
                    'api_access' => true,
                    'api_requests_per_day' => 1000,
                    'api_requests_per_month' => 25000,
                    'api_rate_limit_per_min' => 60,
                    'retention_minutes' => 180,
                    'ads_visible' => false,
                ],
            ],
            [
                'id' => 'business',
                'name' => 'Enterprise & Team',
                'slug' => 'business',
                'description' => 'Maximum throughput, priority concurrency, high-rate REST API automation, and custom retention.',
                'is_public' => true,
                'is_default' => false,
                'price_monthly_cents' => 4900,
                'price_yearly_cents' => 46800,
                'currency' => 'USD',
                'stripe_price_monthly_id' => 'price_business_monthly',
                'stripe_price_yearly_id' => 'price_business_yearly',
                'paypal_plan_monthly_id' => 'P-BUS-MONTHLY',
                'paypal_plan_yearly_id' => 'P-BUS-YEARLY',
                'sort_order' => 3,
                'entitlements' => [
                    'ops_per_day' => 5000,
                    'ops_per_month' => 100000,
                    'max_file_size_mb' => 250,
                    'max_files_per_op' => 100,
                    'max_pages_per_op' => 5000,
                    'concurrent_jobs' => 15,
                    'ocr_allowed' => true,
                    'batch_allowed' => true,
                    'allowed_tools' => ['*'],
                    'api_access' => true,
                    'api_requests_per_day' => 10000,
                    'api_requests_per_month' => 250000,
                    'api_rate_limit_per_min' => 240,
                    'retention_minutes' => 720,
                    'ads_visible' => false,
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultCmsPages(): array
    {
        return [
            [
                'slug' => 'privacy',
                'title' => 'Privacy and file retention',
                'meta_description' => 'How Docvera isolates uploaded files, executes local server-side processing, and permanently purges temporary documents.',
                'is_published' => true,
                'in_sitemap' => true,
                'content' => "Docvera processes every uploaded document locally on this host inside isolated 0700 temporary working directories.\n\n"
                    . "1. Processing Locality: No document files are transmitted to external third-party cloud document APIs. All transformations execute via local server binaries (Ghostscript, Poppler, QPDF, LibreOffice, Tesseract) and native PHP stream handlers.\n\n"
                    . "2. Ephemeral Input Purge: Uploaded source files and intermediate scratch files are deleted immediately as soon as your operation finishes.\n\n"
                    . "3. Configurable Output Retention: Generated output files are retained only for the active retention window configured for your plan, after which scheduled garbage collection permanently wipes the workspace.\n\n"
                    . "4. Immediate Deletion: You can trigger 'Delete File Now' from any job result screen, dashboard row, or via DELETE /api/v1/jobs/{id} to wipe output files on demand.",
            ],
            [
                'slug' => 'terms',
                'title' => 'Terms of service',
                'meta_description' => 'Terms governing account usage, plan quotas, subscription billing, and REST API automation on Docvera.',
                'is_published' => true,
                'in_sitemap' => true,
                'content' => "By using this Docvera instance, you agree to process only documents that you are legally authorized to inspect, modify, or convert.\n\n"
                    . "1. Quota & Rate Enforcement: Each guest session, registered account, and API key is governed by the operation limits, file-size caps, concurrency slots, and rate limits defined by its assigned plan.\n\n"
                    . "2. API Security: API keys are confidential bearer credentials. You may rotate or revoke keys at any time from your User Dashboard.\n\n"
                    . "3. Ephemeral Storage Notice: Docvera is a processing workbench, not a permanent archival vault. Always download your processed output before its scheduled expiration time.",
            ],
            [
                'slug' => 'security',
                'title' => 'How files are kept safe',
                'meta_description' => 'Technical overview of Docvera subprocess sandboxing, magic-byte verification, and cryptographic job tokens.',
                'is_published' => true,
                'in_sitemap' => true,
                'content' => "Docvera is architected with defense-in-depth controls for document processing:\n\n"
                    . "1. Zero-Shell Execution: External processing engines are invoked strictly via proc_open() argument arrays with bypass_shell enabled and strict wall-clock timeouts.\n\n"
                    . "2. Structural Signature Validation: Every uploaded file is inspected via finfo MIME detection and magic-byte header/trailer checks (%PDF- and %%EOF verification) prior to staging.\n\n"
                    . "3. Cryptographic Job Isolation: Every job is bound to its creator's session hash, user ID, or HMAC-SHA256 download token. Unauthorized cross-user access is strictly blocked.",
            ],
        ];
    }
}
