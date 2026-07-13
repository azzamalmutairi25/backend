<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// صيانة يومية: كشف الغياب، تذكير جلسات الغد، تصعيد التقارير المتأخرة
Schedule::command('kafaat:daily')->dailyAt('06:00');
