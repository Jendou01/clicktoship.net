<?php

namespace App\Modules\WalletBilling\Infrastructure;

use App\Modules\Merchants\Domain\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Tenant-scoped read model. All writes belong to Ledger/controlled PostgreSQL entry points. */
class Wallet extends Model
{
    use BelongsToMerchant;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::saving(fn () => throw new LogicException('Use the financial application service.'));
        static::deleting(fn () => throw new LogicException('Financial history cannot be deleted.'));
    }
}
