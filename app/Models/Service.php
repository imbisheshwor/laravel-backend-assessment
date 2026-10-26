<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $service_code
 * @property string $name
 * @property int $department_id
 * @property string $unit_price
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Service extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['is_active' => 'boolean', 'unit_price' => 'decimal:2']; }
    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
}
