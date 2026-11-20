<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    protected $fillable = [
        'display_name',
        'status',
        'sms_client_id',
        'app_url',
        'email_from_name',
        'email_reply_to',
        'logo_url',
        'brand_color',
        'support_email',
        'support_phone',
        'migration_status',
        'last_migrated_at',
        'migration_error',
        'tenancy_db_name',
        'tenancy_db_host',
        'tenancy_db_port',
        'tenancy_db_username',
        'tenancy_db_password',
        'tenancy_db_connection',
    ];

    protected function casts(): array
    {
        return [
            'sms_client_id' => 'encrypted',
            'tenancy_db_password' => 'encrypted',
            'last_migrated_at' => 'datetime',
        ];
    }

    public static function getCustomColumns(): array
    {
        return array_merge(parent::getCustomColumns(), [
            'display_name',
            'status',
            'sms_client_id',
            'app_url',
            'email_from_name',
            'email_reply_to',
            'logo_url',
            'brand_color',
            'support_email',
            'support_phone',
            'migration_status',
            'last_migrated_at',
            'migration_error',
            'tenancy_db_name',
            'tenancy_db_host',
            'tenancy_db_port',
            'tenancy_db_username',
            'tenancy_db_password',
            'tenancy_db_connection',
        ]);
    }

    public function getApplicationUrlAttribute(): string
    {
        if ($this->app_url) {
            return $this->app_url;
        }

        $domain = $this->domains()->value('domain');
        $scheme = app()->environment('local', 'testing') ? 'http' : 'https';

        return $domain ? $scheme.'://'.$domain : config('app.url');
    }

    public function getBrandColorAttribute(?string $value): string
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value)
            ? $value
            : '#2563eb';
    }
}
