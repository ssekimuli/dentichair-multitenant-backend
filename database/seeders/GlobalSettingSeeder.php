<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\GlobalSetting;

class GlobalSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $setting = GlobalSetting::first();
        if ($setting) {
            if ($setting->sms_balance == 0) {
                $setting->sms_balance = 600;
                $setting->save();
            }
        } else {
            GlobalSetting::create([
                'sms_balance' => 600,
                'whatsapp_balance' => 0,
            ]);
        }
    }
}
