<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AccessDeniedException;

class RbacEnforcer
{
    public const ACTION_VIEW_OWN = 'VIEW_OWN';
    public const ACTION_VIEW_ORG = 'VIEW_ORG';
    public const ACTION_MANAGE_INVENTORY = 'MANAGE_INVENTORY';
    public const ACTION_MANAGE_BOOKINGS = 'MANAGE_BOOKINGS';
    public const ACTION_MANAGE_BILLING = 'MANAGE_BILLING';
    public const ACTION_MANAGE_USERS = 'MANAGE_USERS';
    public const ACTION_VIEW_FINANCE = 'VIEW_FINANCE';
    public const ACTION_EXPORT_FINANCE = 'EXPORT_FINANCE';
    public const ACTION_MANAGE_TERMINALS = 'MANAGE_TERMINALS';
    public const ACTION_MANAGE_SETTINGS = 'MANAGE_SETTINGS';
    public const ACTION_VIEW_AUDIT = 'VIEW_AUDIT';
    public const ACTION_MANAGE_BACKUPS = 'MANAGE_BACKUPS';
    public const ACTION_PROCESS_REFUND = 'PROCESS_REFUND';
    public const ACTION_MARK_NOSHOW = 'MARK_NOSHOW';
    public const ACTION_CHECK_IN = 'CHECK_IN';
    public const ACTION_VIEW_SETTINGS = 'VIEW_SETTINGS';

    public const ROLE_PERMISSIONS = [
        'administrator' => [
            self::ACTION_VIEW_OWN,
            self::ACTION_VIEW_ORG,
            self::ACTION_MANAGE_INVENTORY,
            self::ACTION_MANAGE_BOOKINGS,
            self::ACTION_MANAGE_BILLING,
            self::ACTION_MANAGE_USERS,
            self::ACTION_VIEW_FINANCE,
            self::ACTION_EXPORT_FINANCE,
            self::ACTION_MANAGE_TERMINALS,
            self::ACTION_MANAGE_SETTINGS,
            self::ACTION_VIEW_SETTINGS,
            self::ACTION_VIEW_AUDIT,
            self::ACTION_MANAGE_BACKUPS,
            self::ACTION_PROCESS_REFUND,
            self::ACTION_MARK_NOSHOW,
            self::ACTION_CHECK_IN,
        ],
        'property_manager' => [
            self::ACTION_VIEW_ORG,
            self::ACTION_MANAGE_INVENTORY,
            self::ACTION_MANAGE_BOOKINGS,
            self::ACTION_MANAGE_BILLING,
            self::ACTION_MANAGE_TERMINALS,
            self::ACTION_VIEW_SETTINGS,
            self::ACTION_PROCESS_REFUND,
            self::ACTION_MARK_NOSHOW,
            self::ACTION_CHECK_IN,
        ],
        'tenant' => [
            self::ACTION_VIEW_OWN,
        ],
        'finance_clerk' => [
            self::ACTION_VIEW_ORG,
            self::ACTION_VIEW_FINANCE,
            self::ACTION_EXPORT_FINANCE,
            self::ACTION_VIEW_SETTINGS,
            self::ACTION_PROCESS_REFUND,
        ],
    ];

    public function enforce(User $user, string $action, ?string $resourceOwnerId = null): void
    {
        $role = $user->getRole()->value;
        $allowedActions = self::ROLE_PERMISSIONS[$role] ?? [];

        if (!in_array($action, $allowedActions, true)) {
            throw new AccessDeniedException();
        }

        if ($role === UserRole::TENANT->value && $action === self::ACTION_VIEW_OWN && $resourceOwnerId !== null) {
            if ($user->getId() !== $resourceOwnerId) {
                throw new AccessDeniedException();
            }
        }
    }
}
