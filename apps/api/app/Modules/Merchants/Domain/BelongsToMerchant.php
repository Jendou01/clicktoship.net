<?php

namespace App\Modules\Merchants\Domain;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

trait BelongsToMerchant
{
    protected static function bootBelongsToMerchant(): void
    {
        static::addGlobalScope('merchant', function (Builder $query): void {
            $id = app(MerchantContext::class)->id();
            $id ? $query->where($query->getModel()->qualifyColumn('merchant_id'), $id) : $query->whereRaw('1 = 0');
        });
        static::creating(function ($model): void {
            $id = app(MerchantContext::class)->id();
            if (! $id || ($model->merchant_id && $model->merchant_id !== $id)) {
                throw new LogicException('An authorized merchant context is required.');
            }
            $model->merchant_id = $id;
        });
        static::updating(function ($model): void {
            if (app(MerchantContext::class)->id() !== $model->merchant_id || $model->isDirty('merchant_id') || $model->isDirty('id') || $model->isDirty('user_id')) {
                throw new LogicException('Tenant identity is immutable.');
            }
        });
    }
}
