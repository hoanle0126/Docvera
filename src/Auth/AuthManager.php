<?php

declare(strict_types=1);

namespace Docvera\Auth;

use Docvera\Database\PlatformRepository;
use Docvera\Http\Request;
use Docvera\Security\CsrfGuard;
use Docvera\Security\ValidationException;
use Docvera\Storage\TempStorageManager;

final class AuthManager
{
    private PlatformRepository $platform;
    private TempStorageManager $storage;

    public function __construct(PlatformRepository $platform, TempStorageManager $storage)
    {
        $this->platform = $platform;
        $this->storage = $storage;
    }

    public function resolveSessionPrincipal(?string $overrideSessionIdentity = null): Principal
    {
        CsrfGuard::ensureSession();
        $sessionIdentity = $overrideSessionIdentity ?? CsrfGuard::getSessionIdentity();
        $sessionHash = $this->storage->hashToken($sessionIdentity);

        $userId = isset($_SESSION['auth_user_id']) && is_string($_SESSION['auth_user_id'])
            ? $_SESSION['auth_user_id']
            : null;

        if ($userId !== null && $userId !== '') {
            $user = $this->platform->findUserById($userId);
            if ($user !== null && (string) ($user['status'] ?? 'active') === 'active') {
                return $this->buildUserPrincipal($user, $sessionIdentity);
            }
            unset($_SESSION['auth_user_id']);
        }

        return new Principal(
            mode: 'guest',
            userId: null,
            email: null,
            name: 'Guest',
            role: 'guest',
            planId: 'guest',
            subjectKey: 'guest:' . substr($sessionHash, 0, 32),
            sessionIdentity: $sessionIdentity
        );
    }

    public function resolveUserPrincipalById(string $userId, string $sessionIdentity = 'direct_session'): Principal
    {
        $user = $this->platform->findUserById($userId);
        if ($user === null || (string) ($user['status'] ?? 'active') !== 'active') {
            throw new ValidationException('User account is not active or does not exist.', 403);
        }
        return $this->buildUserPrincipal($user, $sessionIdentity);
    }

    /**
     * Authenticates a REST API request via Authorization: Bearer <secret> or X-Api-Key: <secret>.
     */
    public function authenticateApiKey(Request $request): Principal
    {
        $rawKey = $this->extractRawApiKey($request);
        if ($rawKey === '') {
            throw new ValidationException('Missing API key. Provide Authorization: Bearer <api_key> or X-Api-Key header.', 401);
        }

        if (!str_starts_with($rawKey, 'dcv_')) {
            throw new ValidationException('Malformed API key format.', 401);
        }

        $keyHash = $this->storage->hashToken($rawKey);
        $apiKey = $this->platform->findApiKeyByHash($keyHash);
        if ($apiKey === null) {
            throw new ValidationException('Invalid API key.', 401);
        }

        if (!$apiKey['is_active'] || ($apiKey['revoked_at'] ?? null) !== null) {
            throw new ValidationException('This API key has been revoked.', 401);
        }

        if (!empty($apiKey['expires_at']) && (string) $apiKey['expires_at'] <= gmdate('Y-m-d\TH:i:s\Z')) {
            throw new ValidationException('This API key has expired.', 401);
        }

        $userId = (string) ($apiKey['user_id'] ?? '');
        $user = $this->platform->findUserById($userId);
        if ($user === null || (string) ($user['status'] ?? 'active') !== 'active') {
            throw new ValidationException('The user account owning this API key is suspended or inactive.', 403);
        }

        // Update last_used_at & last_used_ip without exposing secret
        $apiKey['last_used_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $apiKey['last_used_ip'] = (string) ($request->server['REMOTE_ADDR'] ?? '127.0.0.1');
        $this->platform->saveApiKey($apiKey);

        return $this->buildUserPrincipal($user, 'apikey_' . (string) $apiKey['id'], $apiKey);
    }

    public function isRegistrationOpen(): bool
    {
        $deploymentMode = (string) $this->platform->getSetting('deployment_mode', 'saas');
        if ($deploymentMode === 'private') {
            return false;
        }
        $regEnabledRaw = $this->platform->getSetting('registration_enabled', null);
        if ($regEnabledRaw !== null && $regEnabledRaw !== '') {
            if (in_array(strtolower((string) $regEnabledRaw), ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }
        $regMode = (string) $this->platform->getSetting('registration_mode', 'open');
        return $regMode === 'open';
    }

    /**
     * @return array<string, mixed>
     */
    public function register(
        string $name,
        string $email,
        string $password,
        string $role = 'user',
        ?string $planId = null,
        bool $bypassModeLock = false,
        ?string $ipAddress = null
    ): array {
        if (!$bypassModeLock && !$this->isRegistrationOpen()) {
            throw new ValidationException('Public account registration is disabled on this workspace.', 403);
        }

        if (!$bypassModeLock && $ipAddress !== null && $ipAddress !== '') {
            $this->enforceAuthRateLimit('auth_reg:' . substr($this->storage->hashToken($ipAddress), 0, 24), 10);
        }

        $cleanName = trim($name);
        $cleanEmail = strtolower(trim($email));

        if ($cleanName === '' || mb_strlen($cleanName) > 100) {
            throw new ValidationException('Please provide a valid display name (1–100 characters).', 422);
        }
        if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Please provide a valid email address.', 422);
        }
        if (strlen($password) < 8) {
            throw new ValidationException('Password must be at least 8 characters long.', 422);
        }

        if ($this->platform->findUserByEmail($cleanEmail) !== null) {
            throw new ValidationException('An account with this email address already exists.', 422);
        }

        // If no users exist yet in the instance, promote the very first registered user to admin
        $existingUsers = $this->platform->listUsers();
        $assignedRole = $existingUsers === [] ? 'admin' : $role;

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $user = [
            'id' => 'usr_' . bin2hex(random_bytes(12)),
            'email' => $cleanEmail,
            'name' => $cleanName,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $assignedRole,
            'plan_id' => $planId ?? 'free',
            'status' => 'active',
            'api_enabled_override' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'last_login_at' => $now,
        ];

        $this->platform->saveUser($user);
        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function login(string $email, string $password, string $ipAddress = '127.0.0.1'): array
    {
        $cleanEmail = strtolower(trim($email));
        $bucketKey = 'auth_login:' . substr($this->storage->hashToken($ipAddress . '|' . $cleanEmail), 0, 28);
        $windowKey = (string) intdiv(time(), 900);

        $currentBucket = $this->platform->getUsageBucket($bucketKey, 'auth_guard', 'w15m', $windowKey);
        if ((int) ($currentBucket['ops_count'] ?? 0) >= 10) {
            throw new ValidationException('Too many failed sign-in attempts. Please wait 15 minutes before trying again.', 429);
        }

        $user = $this->platform->findUserByEmail($cleanEmail);
        if ($user === null || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            $this->platform->mutateUsageBucket(
                $bucketKey,
                'auth_guard',
                'w15m',
                $windowKey,
                static function (array $bucket): array {
                    $bucket['ops_count'] = ((int) ($bucket['ops_count'] ?? 0)) + 1;
                    return $bucket;
                }
            );
            throw new ValidationException('Invalid email address or password.', 401);
        }

        if ((string) ($user['status'] ?? 'active') !== 'active') {
            throw new ValidationException('This account has been suspended by an administrator.', 403);
        }

        // Clear failed login counter upon successful authentication
        if ((int) ($currentBucket['ops_count'] ?? 0) > 0) {
            $this->platform->mutateUsageBucket(
                $bucketKey,
                'auth_guard',
                'w15m',
                $windowKey,
                static function (array $bucket): array {
                    $bucket['ops_count'] = 0;
                    return $bucket;
                }
            );
        }

        $user['last_login_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $user['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->platform->saveUser($user);

        CsrfGuard::ensureSession();
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }
        CsrfGuard::rotateToken();
        $_SESSION['auth_user_id'] = (string) $user['id'];

        return $user;
    }

    public function logout(): void
    {
        CsrfGuard::ensureSession();
        unset($_SESSION['auth_user_id']);
        CsrfGuard::rotateToken();
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }
    }

    private function enforceAuthRateLimit(string $subjectKey, int $maxRequestsPer15Min): void
    {
        $windowKey = (string) intdiv(time(), 900);
        $allowed = false;
        $this->platform->mutateUsageBucket(
            $subjectKey,
            'auth_guard',
            'w15m',
            $windowKey,
            static function (array $bucket) use ($maxRequestsPer15Min, &$allowed): array {
                $count = (int) ($bucket['ops_count'] ?? 0);
                if ($count >= $maxRequestsPer15Min) {
                    $allowed = false;
                    return $bucket;
                }
                $allowed = true;
                $bucket['ops_count'] = $count + 1;
                return $bucket;
            }
        );
        if (!$allowed) {
            throw new ValidationException('Too many registration attempts from this IP address. Please try again in 15 minutes.', 429);
        }
    }

    public function updatePassword(string $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->platform->findUserById($userId);
        if ($user === null) {
            throw new ValidationException('User not found.', 404);
        }
        if (!password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
            throw new ValidationException('Current password is incorrect.', 422);
        }
        if (strlen($newPassword) < 8) {
            throw new ValidationException('New password must be at least 8 characters long.', 422);
        }
        $user['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $user['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->platform->saveUser($user);
    }

    /**
     * Generates a new API key and returns both the stored record and the one-time plain secret.
     *
     * @param list<string> $scopes
     * @return array{record: array<string, mixed>, plain_secret: string}
     */
    public function createApiKey(
        string $userId,
        string $name,
        array $scopes = ['tools:execute', 'jobs:read', 'jobs:download', 'jobs:delete'],
        int $rateLimitPerMin = 60
    ): array {
        $cleanName = trim($name) !== '' ? trim($name) : 'API Key';
        $randomHex = bin2hex(random_bytes(24));
        $plainSecret = 'dcv_live_' . $randomHex;
        $prefix = substr($plainSecret, 0, 15);
        $keyHash = $this->storage->hashToken($plainSecret);

        $validScopes = array_values(array_filter(array_map('trim', $scopes), static fn(string $s): bool => $s !== ''));
        if ($validScopes === []) {
            $validScopes = ['tools:execute', 'jobs:read', 'jobs:download', 'jobs:delete'];
        }

        $id = 'key_' . bin2hex(random_bytes(10));
        $record = [
            'id' => $id,
            'user_id' => $userId,
            'name' => $cleanName,
            'key_prefix' => $prefix,
            'key_hash' => $keyHash,
            'scopes' => $validScopes,
            'rate_limit_per_min' => max(1, $rateLimitPerMin),
            'is_active' => true,
            'last_used_at' => null,
            'last_used_ip' => null,
            'expires_at' => null,
            'revoked_at' => null,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $this->platform->saveApiKey($record);

        return [
            'record' => $record,
            'plain_secret' => $plainSecret,
        ];
    }

    /**
     * Rotates an existing API key by revoking the old key and issuing a new secret with the same name & scopes.
     *
     * @return array{record: array<string, mixed>, plain_secret: string}
     */
    public function rotateApiKey(string $keyId, string $userId): array
    {
        $existing = $this->platform->findApiKeyById($keyId);
        if ($existing === null || (string) ($existing['user_id'] ?? '') !== $userId) {
            throw new ValidationException('API key not found.', 404);
        }

        $this->revokeApiKey($keyId, $userId);

        $scopes = is_array($existing['scopes'] ?? null)
            ? $existing['scopes']
            : ['tools:execute', 'jobs:read', 'jobs:download', 'jobs:delete'];

        return $this->createApiKey(
            $userId,
            (string) ($existing['name'] ?? 'Rotated Key'),
            $scopes,
            (int) ($existing['rate_limit_per_min'] ?? 60)
        );
    }

    public function revokeApiKey(string $keyId, ?string $userId = null): bool
    {
        $existing = $this->platform->findApiKeyById($keyId);
        if ($existing === null) {
            return false;
        }
        if ($userId !== null && (string) ($existing['user_id'] ?? '') !== $userId) {
            return false;
        }

        $existing['is_active'] = false;
        $existing['revoked_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->platform->saveApiKey($existing);
        return true;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $apiKey
     */
    private function buildUserPrincipal(array $user, string $sessionIdentity, ?array $apiKey = null): Principal
    {
        $userId = (string) $user['id'];
        $role = (string) ($user['role'] ?? 'user');
        $planId = (string) ($user['plan_id'] ?? 'free');

        // Check if user has an active or trialing subscription that grants a paid plan
        $sub = $this->platform->findLatestSubscriptionForUser($userId);
        if ($sub !== null) {
            $subStatus = (string) ($sub['status'] ?? '');
            $periodEnd = (string) ($sub['current_period_end'] ?? '');
            $nowIso = gmdate('Y-m-d\TH:i:s\Z');
            if (in_array($subStatus, ['active', 'trialing'], true) && ($periodEnd === '' || $periodEnd > $nowIso)) {
                $planId = (string) ($sub['plan_id'] ?? $planId);
            } elseif ($subStatus === 'canceled' && (int) ($sub['cancel_at_period_end'] ?? 0) === 1 && $periodEnd > $nowIso) {
                $planId = (string) ($sub['plan_id'] ?? $planId);
            } elseif (in_array($subStatus, ['expired', 'canceled'], true) && $periodEnd !== '' && $periodEnd <= $nowIso) {
                if ($planId === (string) ($sub['plan_id'] ?? '')) {
                    $planId = 'free';
                }
            }
        }

        $plan = $this->platform->findPlan($planId);
        $isPaidPlan = $plan !== null && ((int) ($plan['price_monthly_cents'] ?? 0) > 0 || (int) ($plan['price_yearly_cents'] ?? 0) > 0 || !in_array($planId, ['guest', 'free'], true));

        $mode = 'free_user';
        if ($role === 'admin') {
            $mode = 'admin';
        } elseif ($isPaidPlan) {
            $mode = 'paid_user';
        }

        $apiOverride = null;
        if (isset($user['api_enabled_override']) && $user['api_enabled_override'] !== null) {
            $apiOverride = ((int) $user['api_enabled_override']) === 1;
        }

        return new Principal(
            mode: $mode,
            userId: $userId,
            email: (string) ($user['email'] ?? ''),
            name: (string) ($user['name'] ?? 'User'),
            role: $role,
            planId: $planId,
            subjectKey: 'user:' . $userId,
            sessionIdentity: $sessionIdentity,
            apiKey: $apiKey,
            apiEnabledOverride: $apiOverride
        );
    }

    private function extractRawApiKey(Request $request): string
    {
        $authHeader = trim((string) ($request->server['HTTP_AUTHORIZATION'] ?? $request->server['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (stripos($authHeader, 'Bearer ') === 0) {
            return trim(substr($authHeader, 7));
        }

        $xApiKey = trim((string) ($request->server['HTTP_X_API_KEY'] ?? ''));
        if ($xApiKey !== '') {
            return $xApiKey;
        }

        return '';
    }
}

