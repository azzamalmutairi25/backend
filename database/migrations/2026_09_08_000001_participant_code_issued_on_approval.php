<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// رمز المشارك يُصدَر عند اعتماد الجدولة لا عند الإضافة.
//
// كان يُولَّد لحظة الإدراج، فيحمله كلُّ من دخل القاعدة ولو لم يُجدوَل قطّ.
// وصار يُصدَر عند اعتماد الفترة التي جُدولت فيها جلساته — أي حين يصير
// موعده أمراً واقعاً لا نيّة.
//
// وأثرُ ذلك على المخطّط واحد: العمودان يقبلان الفراغ. والقيد الفريد يبقى —
// postgres يسمح بتكرار NULL فيه، فينضبط الرمز الصادر ولا يُمنع غيرُ الصادر.
//
// ولا تُمسّ الرموز القائمة: صيغتها القديمة (DM-029) تبقى كما هي على تقارير
// معتمَدة وجداول ذهبية وتصاريح صدرت. الجديد وحده يأخذ الصيغة الجديدة،
// والاثنتان لا تتصادمان — ولا يتصادم مفتاحا عدّادهما.
return new class extends Migration
{
    public function up(): void
    {
        // `assessments.participant_code` كان varchar(255) بلا سببٍ ظاهر — يُضبط
        // على عشرين كنظيره، وهو ما يتّسع للصيغة الجديدة ببادئةٍ رباعية.
        DB::statement('ALTER TABLE assessments ALTER COLUMN participant_code TYPE varchar(20)');
        DB::statement('ALTER TABLE assessments ALTER COLUMN participant_code DROP NOT NULL');
        DB::statement('ALTER TABLE candidates ALTER COLUMN participant_code DROP NOT NULL');
    }

    public function down(): void
    {
        // الرجوع يوجب رمزاً لكل صفّ — ولا نخترع رموزاً هنا: من بقي بلا رمز
        // لم يُجدوَل، وإسنادُ رمزٍ له يكذب على ما جرى.
        $codeless = DB::table('candidates')->whereNull('participant_code')->count();
        if ($codeless > 0) {
            throw new RuntimeException(
                "تعذّر التراجع: {$codeless} مشاركاً بلا رمز (لم تُعتمد جدولتهم بعد)."
            );
        }

        DB::statement('ALTER TABLE candidates ALTER COLUMN participant_code SET NOT NULL');
        DB::statement('ALTER TABLE assessments ALTER COLUMN participant_code SET NOT NULL');
        DB::statement('ALTER TABLE assessments ALTER COLUMN participant_code TYPE varchar(255)');
    }
};
