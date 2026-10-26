<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $full_name
 * @property string $date_of_birth
 * @property 'female'|'male'|'other'|'unknown' $sex
 * @property string|null $phone
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Patient extends Model
{
    protected $guarded = ['id'];
}
