<?php

namespace App\Traits;

use App\Models\ActivityLog;

trait LogsActivity
{
    public static function bootLogsActivity()
    {
        static::created(function ($model) {
            $model->logActivity('created');
        });

        static::updated(function ($model) {
            if ($model->isDirty()) {
                $model->logActivity('updated');
            }
        });

        static::deleted(function ($model) {
            $model->logActivity('deleted');
        });
    }

    protected function logActivity($action)
    {
        // Don't log if the action is triggered by the ActivityLog itself
        if ($this instanceof ActivityLog) {
            return;
        }

        $modelName = class_basename($this);
        
        $changes = [];
        if ($action === 'updated') {
            $changes = [
                'before' => array_intersect_key($this->getOriginal(), $this->getDirty()),
                'after' => $this->getDirty()
            ];
        } else {
            $changes = $this->toArray();
        }

        $description = "{$modelName} {$action}";

        ActivityLog::log(
            strtolower($action),
            $description,
            [
                'model' => get_class($this),
                'model_id' => $this->id ?? null,
                'changes' => $changes
            ]
        );
    }
}
