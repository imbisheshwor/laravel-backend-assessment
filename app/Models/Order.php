<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $patient_id
 * @property 'unpaid'|'paid' $status
 * @property string $total
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Order extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['total' => 'decimal:2', 'paid_at' => 'datetime']; }
    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    /** @return HasOne<Payment, $this> */
    public function payment(): HasOne { return $this->hasOne(Payment::class); }
}
