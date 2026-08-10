<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وصفٌ سلوكيّ لكل مستوى في سلّم الكفاءة.
 *
 * المُقيّم كان يختار رقماً بين ١ و٥ بلا مرساة: «٣» عند مقيّمٍ هي «٤» عند
 * غيره، فيختلف التقدير باختلاف اليد لا باختلاف المرشّح. الوصف السلوكي
 * يُثبّت المعنى: لكل درجةٍ سلوكٌ يُرى ويُقاس، فيتقارب المقيّمون.
 *
 * JSON بصيغة { "1": "…", "2": "…" } — مفاتيحها أرقام المستويات.
 * فارغةً تسقط الشاشة إلى مراسٍ عامّة مشتقّة من طول السلّم، فلا تتعطّل
 * الجلسة بانتظار أن تُكتب أوصاف الكفاءات كلّها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competencies', function (Blueprint $table) {
            $table->json('level_descriptors')->nullable()->after('max_level');
        });
    }

    public function down(): void
    {
        Schema::table('competencies', function (Blueprint $table) {
            $table->dropColumn('level_descriptors');
        });
    }
};
