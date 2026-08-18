<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['service_markup_bps', 'gateway_fee_bps', 'minimum_order_irr', 'is_active', 'effective_from', 'effective_to', 'created_by'])]
class PricingRule extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'effective_from' => 'datetime', 'effective_to' => 'datetime'];
    }
}
