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
        $user = auth()->user();
        $userName = 'System Action';

        if ($user) {
            $userName = $user->first_name ? trim($user->first_name . ' ' . $user->last_name) : ($user->name ?? 'Unknown User');
        }

        if (!is_array($metadata)) {
            $metadata = (array) $metadata;
        }
        $metadata['user_name'] = $userName;

        self::create([
            'user_id' => auth()->id(),
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
