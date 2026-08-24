<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | مسار لوحة Horizon
    |--------------------------------------------------------------------------
    | اللوحة تعرض حمولات المهامّ — أسماء مشاركين ونصوص رسائل. لذا تُؤمَّن
    | ببوّابة في HorizonServiceProvider (مدير النظام وحده)، لا بالمسار.
    */

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | اتصال Redis الذي يخزّن بيانات Horizon
    |--------------------------------------------------------------------------
    | بيانات الإشراف والمقاييس (لا المهامّ نفسها). يستعمل اتصال redis
    | الافتراضي مع سابقةٍ خاصّة، فلا يختلط بمفاتيح الطابور.
    */

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'kafaat')).'-horizon:'
    ),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | حدود الانتظار قبل التنبيه
    |--------------------------------------------------------------------------
    | إن تجاوز طابورٌ هذا الانتظار (ثانية) عُدّ متعثّراً في اللوحة. طابور
    | imports طويلٌ بطبعه فله حدٌّ أوسع كي لا يُنبَّه زوراً على كل رفعة.
    */

    'waits' => [
        'redis:default' => 60,
        'redis-imports:imports' => 3900,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | المُشرِفون
    |--------------------------------------------------------------------------
    | مُشرفان لطابورين مفصولين — القرار نفسه الذي بُني في systemd سابقاً،
    | منقولٌ إلى Horizon:
    |
    |  • default  : الرسائل القصيرة المتقطّعة (SendSmsJob). موازنةٌ تلقائية،
    |               عدّة عمّال، مهلةٌ قصيرة ٩٠ث.
    |  • imports  : رفعةُ المشاركين (ProcessCandidateImport) — ربع ساعةٍ
    |               للرفعة. عاملٌ واحد لا يُتساهل فيه: رفعتان متوازيتان
    |               تتنافسان على القاعدة فتُبطئان، والتسلسل أسرع. مهلةٌ
    |               ٣٦٠٠ث مطابقةٌ لمهلة الوظيفة، على اتصال redis-imports
    |               الذي مهلةُ إعادته (retry_after=3900) أطولُ من الوظيفة
    |               عمداً كي لا تُطلَق ثانيةً وهي تعمل.
    */

    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 3600,
            'maxJobs' => 1000,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 90,
            'nice' => 0,
        ],

        'supervisor-imports' => [
            'connection' => 'redis-imports',
            'queue' => ['imports'],
            'balance' => 'false',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 3600,
            'nice' => 5,
        ],
    ],

    'environments' => [

        'production' => [
            'supervisor-default' => [
                'minProcesses' => 1,
                'maxProcesses' => 6,
                'balanceMaxShift' => 2,
                'balanceCooldown' => 3,
            ],
            'supervisor-imports' => [
                'maxProcesses' => 1,
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'maxProcesses' => 3,
            ],
            'supervisor-imports' => [
                'maxProcesses' => 1,
            ],
        ],

    ],

];
