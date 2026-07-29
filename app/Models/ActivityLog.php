<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'action', 'description', 'metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public static function log($action, $description = null, $metadata = [])
    {
        $setting = \App\Models\GlobalSetting::getSettings();
        if (!$setting->activity_log_enabled) {
            return;
        }

        $user = auth()->user() ?? auth('sanctum')->user() ?? request()->user();
        $userId = auth()->id() ?? auth('sanctum')->id() ?? ($user ? $user->id : null);
        $userName = 'System Action';

        if ($user) {
            $userName = $user->first_name ? trim($user->first_name . ' ' . $user->last_name) : ($user->name ?? 'Unknown User');
        }

        if (!is_array($metadata)) {
            $metadata = (array) $metadata;
        }
        $metadata['user_name'] = $userName;

        self::create([
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    public function user()
    {
        return $this->belongsTo(Employee::class, 'user_id');
    }
}
