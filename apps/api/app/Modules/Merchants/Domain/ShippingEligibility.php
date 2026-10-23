<?php

namespace App\Modules\Merchants\Domain;

final class ShippingEligibility
{
    public static function evaluate(string $accountStatus, string $verificationStatus): array
    {
        $reasons = [];
        if ($accountStatus !== 'active') {
            $reasons[] = 'account_not_active';
        }
        if ($verificationStatus !== 'verified') {
            $reasons[] = 'business_verification_required';
        }

        // No positive decision until every future business evaluator actually exists.
        return ['eligible' => false, 'reasons' => [...$reasons, 'phase_3_shipping_unavailable', 'wallet_balance_not_evaluated', 'carrier_permissions_not_evaluated', 'contract_not_evaluated', 'administrative_restrictions_not_evaluated']];
    }
}
