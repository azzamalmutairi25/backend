<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// صيانة البحيرة: تهيئة الأقسام مُقدَّماً، وتقليم صندوق الصادر المشحون،
// والتنبيه على ما استقرّ في قسم الاستقبال البعيد.
class LakeMaintain extends Command
{
    protected $signature = 'kafaat:lake:maintain {--purge-days=30 : عمر الصفوف المشحونة قبل تقليمها}';
    protected $description = 'صيانة بحيرة التقارير: الأقسام، التقليم، فحص الصحّة';

    public function handle(): int
    {
        if (!config('lake.enabled')) {
            $this->line('البحيرة معطّلة — لا صيانة.');
            return self::SUCCESS;
        }

        $lake = DB::connection(config('lake.connection'));

        // (١) الأقسام مُقدَّماً — قبل أن يحتاجها حدث، لا بعده.
        $made = $lake->selectOne('SELECT lake.ensure_partitions(?) AS n',
            [(int) config('lake.partition_months_ahead', 15)])->n;
        $this->info("أقسام أُنشئت: {$made}");

        // (٢) تقليم المشحون — الحقيقة في البحيرة، والصندوق جسرٌ لا أرشيف.
        $cut = now()->subDays((int) $this->option('purge-days'));
        $purged = DB::table('report_lake_outbox')
            ->whereNotNull('shipped_at')->where('shipped_at', '<', $cut)->delete();
        $this->info("صفوف مُقلَّمة من صندوق الصادر: {$purged}");

        // (٣) قسم الاستقبال البعيد يجب أن يبقى فارغاً. أيُّ صفٍّ فيه يعني
        //     حدثاً بتاريخٍ خارج النطاق المُهيّأ — ولا يمكن نقلُه لاحقاً،
        //     لأن إنشاء القسم المُغطّي يفشل ما دام فيه صفٌّ يخصّه.
        $stray = (int) $lake->selectOne('SELECT count(*) AS n FROM raw.report_events_default')->n;
        if ($stray > 0) {
            $this->error("تحذير: {$stray} صفّاً في قسم الاستقبال البعيد — تواريخ خارج النطاق المُهيّأ.");
        }

        // (٤) الحجر الصحّي: صفوفٌ عُزلت وتنتظر قراراً بشرياً.
        $held = DB::table('report_lake_outbox')->whereNotNull('failed_at')->count();
        if ($held > 0) {
            $this->error("في الحجر الصحّي: {$held} صفّاً — تحتاج فحصاً (last_error).");
        }

        $f = $lake->table('contract_v1.freshness')->first();
        $this->line('آخر حدثٍ هبط: ' . ($f->last_landed_at ?? '—')
            . '   تقارير مُتابَعة: ' . ($f->reports_tracked ?? 0));

        return self::SUCCESS;
    }
}
