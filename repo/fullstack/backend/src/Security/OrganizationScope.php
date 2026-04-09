<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Exception\OrganizationScopeMismatchException;

class OrganizationScope
{
    public function getOrganizationId(User $user): string
    {
        return $user->getOrganizationId();
    }

    public function assertSameOrganization(User $user, string $entityOrgId): void
    {
        if ($user->getOrganizationId() !== $entityOrgId) {
            throw new OrganizationScopeMismatchException();
        }
    }

    public function scopeQuery(User $user): array
    {
        return ['organization_id' => $user->getOrganizationId()];
    }
}
