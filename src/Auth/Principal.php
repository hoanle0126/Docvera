<?php

declare(strict_types=1);

namespace Docvera\Auth;

/**
 * Immutable identity and role descriptor for any Web or REST API caller.
 */
final class Principal
{
    /**
     * @param array<string, mixed>|null $apiKey
     */
    public function __construct(
        public readonly string $mode, // 'guest' | 'free_user' | 'paid_user' | 'admin'
        public readonly ?string $userId,
        public readonly ?string $email,
        public readonly string $name,
        public readonly string $role, // 'guest' | 'user' | 'admin'
        public readonly string $planId,
        public readonly string $subjectKey,
        public readonly string $sessionIdentity,
        public readonly ?array $apiKey = null,
        public readonly ?bool $apiEnabledOverride = null
    ) {
    }

    public function isGuest(): bool
    {
        return $this->userId === null || $this->role === 'guest';
    }

    public function isAuthenticated(): bool
    {
        return $this->userId !== null && $this->role !== 'guest';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isPaid(): bool
    {
        return $this->mode === 'paid_user' || $this->mode === 'admin';
    }

    public function isApiCall(): bool
    {
        return $this->apiKey !== null;
    }

    public function hasApiScope(string $requiredScope, ?string $toolId = null): bool
    {
        if ($this->apiKey === null) {
            return true;
        }
        $scopes = is_array($this->apiKey['scopes'] ?? null) ? $this->apiKey['scopes'] : [];
        if (in_array('*', $scopes, true) || in_array($requiredScope, $scopes, true)) {
            if ($toolId !== null) {
                $toolScopes = array_filter($scopes, static fn(string $s): bool => str_starts_with($s, 'tool:'));
                if ($toolScopes !== [] && !in_array('tool:' . $toolId, $toolScopes, true)) {
                    return false;
                }
            }
            return true;
        }
        if ($toolId !== null && in_array('tool:' . $toolId, $scopes, true) && $requiredScope === 'tools:execute') {
            return true;
        }
        return false;
    }
}

