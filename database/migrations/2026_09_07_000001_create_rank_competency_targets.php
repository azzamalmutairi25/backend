<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// المستوى المطلوب لكل كفاءة **حسب الرتبة** لا حسب الفئة القيادية.
//
// `competencies.target_upper|target_middle` عمودان لا غير: كل رتبة في الفئة
// العليا تُحاسَب بسقف واحد. والنموذج المعتمد في المركز ليس كذلك — الفريق
// يُتوقَّع منه ٥ في التفكير الاستراتيجي، واللواء ٤، والعميد ٣، والعقيد ٣،
// وكلهم «عليا». بجمعهم في رقم واحد يُحكم على اللواء بمعيار العقيد.
//
// الجدول مصفوفةٌ صريحة (رتبة × كفاءة → مستوى مطلوب). وعمودا الفئة يبقيان
// كما هما: رتبةٌ بلا صفٍّ هنا تسقط إليهما، فلا ينكسر شيء قائم ولا تتعطّل
// رتبةٌ لم تُضبط بعد.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_competency_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rank_id')->constrained('ranks')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('competencies')->cascadeOnDelete();
            $table->unsignedTinyInteger('target');
            $table->timestamps();

            // صفٌّ واحد لكل (رتبة، كفاءة) — لا مستويان مطلوبان لنفس الاثنين
            $table->unique(['rank_id', 'competency_id']);
            // الاستعلام دائماً «ما مطلوب هذه الرتبة؟» لا العكس
            $table->index('rank_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_competency_targets');
    }
};
