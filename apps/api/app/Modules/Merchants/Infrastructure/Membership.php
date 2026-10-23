<?php

namespace App\Modules\Merchants\Infrastructure;

use App\Modules\Merchants\Domain\BelongsToMerchant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    use BelongsToMerchant, HasUuids;

    protected $table = 'merchant_users';

    protected $fillable = ['user_id', 'status', 'version'];
}
