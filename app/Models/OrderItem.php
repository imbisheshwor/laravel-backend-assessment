<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $order_id
 * @property int $service_id
 * @property int $department_id
 * @property string $service_code
 * @property string $service_name
 * @property string $unit_price
 * @property int $quantity
 * @property string $line_total
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class OrderItem extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['unit_price' => 'decimal:2', 'line_total' => 'decimal:2']; }
}
