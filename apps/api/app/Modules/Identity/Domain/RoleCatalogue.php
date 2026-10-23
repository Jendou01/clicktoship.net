<?php

namespace App\Modules\Identity\Domain;

final class RoleCatalogue
{
    public const MERCHANT_PERMISSIONS = [
        'merchant.view', 'merchant.manage', 'team.view', 'team.manage', 'roles.view', 'tokens.manage',
        'shipments.read', 'shipments.create', 'shipments.manage', 'pickups.read', 'pickups.manage',
        'wallet.read', 'wallet.manage', 'reports.read', 'integrations.read', 'integrations.manage',
    ];

    public static function definitions(): array
    {
        $read = ['merchant.view', 'team.view', 'roles.view', 'shipments.read', 'pickups.read', 'reports.read'];

        return [
            'merchant.owner' => ['Merchant Owner', self::MERCHANT_PERMISSIONS],
            'merchant.admin' => ['Merchant Admin', self::MERCHANT_PERMISSIONS],
            'merchant.shipping_manager' => ['Shipping Manager', [...$read, 'tokens.manage', 'shipments.create', 'shipments.manage', 'pickups.manage', 'integrations.read']],
            'merchant.shipping_operator' => ['Shipping Operator', ['merchant.view', 'roles.view', 'shipments.read', 'shipments.create', 'pickups.read']],
            'merchant.finance_manager' => ['Finance Manager', ['merchant.view', 'roles.view', 'team.view', 'tokens.manage', 'wallet.read', 'wallet.manage', 'reports.read']],
            'merchant.viewer' => ['Read-Only Viewer', $read],
            'platform.super_admin' => ['Super Admin', ['platform.merchants.manage', 'platform.users.manage', 'platform.operations', 'platform.finance', 'platform.support', 'platform.security']],
            'platform.operations' => ['Platform Operations', ['platform.operations']],
            'platform.finance' => ['Platform Finance', ['platform.finance']],
            'platform.support' => ['Platform Support', ['platform.support']],
        ];
    }
}
