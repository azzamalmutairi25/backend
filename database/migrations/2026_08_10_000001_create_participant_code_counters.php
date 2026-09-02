<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  عدّاد رموز المشاركين — صفٌّ لكل بادئة قطاع.
//
//  كان الترقيم يُحسب في ذاكرة PHP: تُجلب كل رموز القطاع، يُؤخذ أعلاها،
//  ويُضاف واحد. عيبان قاتلان:
//   • سباق — طلبان متزامنان يقرآن الحدّ الأقصى نفسه فيولّدان الرمز نفسه،
//     ويسقط أحدهما على القيد الفريد بخطأ 500. قياس الحمل: ٣٦٪ فشل تحت
//     ثمانية كتّاب متزامنين.
//   • كلفة O(n) لكل إدراج — عند ١٠٠٠٠ مشارك في قطاع، كل ترشيح جديد يجلب
//     ١٠٠٠٠ صفّاً. الكلفة تنمو مع نجاح النظام.
//
//  الحلّ: الترقيم في القاعدة بعبارة ذرّية واحدة (INSERT … ON CONFLICT DO
//  UPDATE … RETURNING). كلفة ثابتة، ولا سباق: القاعدة تسلسل المتزامنين.
//
//  ملاحظة على الفجوات: معاملةٌ تفشل بعد أخذ رقمها تترك فجوة في التسلسل.
//  مقبولٌ ومقصود — البديل (حجز الرقم حتى نهاية المعاملة) يُسلسل كل عمليات
//  الإضافة خلف قفلٍ واحد. الرمز معرّف لا عدّاد جرد.
// ════════════════════════════════════════════════════════════
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participant_code_counters', function (Blueprint $table) {
            // البادئة هي المفتاح: صفٌّ واحد لكل قطاع، فالتنافس محصور بقطاعه
            $table->string('prefix', 16)->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        $this->seedFromExistingCodes();
    }

    // ── البذر من الرموز القائمة ──
    // بدونه يبدأ العدّاد من واحد فيصطدم بكل رمز مولَّد قبل هذه الهجرة.
    // نقرأ من الجدولين معاً: الرمز يُكتب على الدورة وعلى المشارك، وقد يوجد
    // في أحدهما دون الآخر (بيانات مستوردة أو مبذورة).
    private function seedFromExistingCodes(): void
    {
        $max = [];

        foreach (['assessments', 'candidates'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'participant_code')) {
                continue;
            }
            // على دفعات: جدول إنتاج كبير لا يُحمَّل كاملاً في الذاكرة
            DB::table($table)
                ->whereNotNull('participant_code')
                ->orderBy('id')
                ->chunk(5000, function ($rows) use (&$max) {
                    foreach ($rows as $row) {
                        if (preg_match('/^(.*)-(\d+)$/', (string) $row->participant_code, $m)) {
                            $prefix = strtoupper($m[1]);
                            $max[$prefix] = max($max[$prefix] ?? 0, (int) $m[2]);
                        }
                    }
                });
        }

        if (! $max) {
            return;
        }

        $now = now();
        DB::table('participant_code_counters')->insert(
            array_map(fn ($prefix, $n) => [
                'prefix' => $prefix,
                'last_number' => $n,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_keys($max), array_values($max))
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_code_counters');
    }
};
