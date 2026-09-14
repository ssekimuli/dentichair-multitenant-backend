<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CentralMonitoringService;

class MonitoringUsage extends Command
{
    protected $signature = 'monitoring:usage';

    protected $description = 'Send application usage statistics to central monitoring';

    public function handle(CentralMonitoringService $monitoring)
    {
        $monitoring->sendUsage();

        return self::SUCCESS;
    }
}