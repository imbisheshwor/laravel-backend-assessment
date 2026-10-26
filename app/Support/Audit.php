<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Audit
{
    public static function record(string $event, string $type, int $id, ?array $old = null, ?array $new = null): void
    {
        $request = app()->bound('request') ? request() : null;
        DB::table('audit_logs')->insert([
            'event' => $event, 'entity_type' => $type, 'entity_id' => $id,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR),
            'request_id' => $request?->attributes->get('request_id') ?? (string) Str::uuid(),
            'actor' => 'anonymous',
            'ip_address' => $request?->ip(), 'created_at' => now(),
        ]);
    }
}
