<?php

namespace App\Modules\Merchants\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Merchant extends Model
{
    use HasUuids;

    protected $fillable = ['name'];
}
