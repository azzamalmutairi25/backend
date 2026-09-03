<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// سير عمل الجدولة — الخطوات الاثنتا عشرة في مخطّط «إجراءات الجدولة» **بياناتٍ
// لا شيفرة**: تُحرَّر وتُرتَّب وتُفعَّل وتُطفأ من شاشة الإعدادات، وتُضاف إليها
// خطوة أو تُحذف، بلا نشرٍ ولا مبرمج.
//
// السبب أنّ الإجراء ورقةٌ إدارية تتغيّر: تُدمج خطوتان، أو تُضاف خطوة يطلبها
// تعميم. تثبيتها في `if` يجعل كل تعديلٍ إصداراً.
//
// `auto_key` هو ما يفصل الخطوة التي **يتحقّق منها النظام بنفسه** عن الخطوة التي
// يؤشّرها الإنسان: مفتاحٌ معروف في `SchedulingWorkflowService::CHECKS` يُحسب
// حيّاً من حالة الموجة، وفارغٌ يعني خانةَ تأشيرٍ يدوية. وخطوةٌ يدوية اليوم تصير
// آليةً غداً بكتابة مفتاحها — بلا هجرة ولا تغيير في الشاشة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('position');
            $table->string('title_ar', 150);
            $table->string('description', 500)->nullable();
            // مفتاح تحقّق آلي من قائمة معروفة — فارغ = تأشير يدوي
            $table->string('auto_key', 40)->nullable();
            // خطوة غير إلزامية لا تمنع اكتمال الموجة ولا تُحسب في النسبة
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // لا قيد فريد على الترتيب: إعادة الترتيب تكتب القيم دفعةً واحدة،
            // وقيدٌ فريد يوجب تمريرةً وسيطة بقيم مؤقّتة بلا فائدة.
            $table->index(['is_active', 'position']);
        });

        // الخطوات الاثنتا عشرة كما في المخطّط، بترتيبه وبنصّه.
        // بذرٌ في الهجرة نفسها — كما في `distribution_proposals` و`ranks`:
        // منصّةٌ جديدة تجد الإجراء جاهزاً لا شاشةً فارغة.
        $now = now();
        $steps = [
            ['تحديد تواريخ الجدولة', 'مدى الدورة: تاريخ البداية وتاريخ النهاية وأوقات الجلسات', 'period.dates'],
            ['اختيار المقيمين والمساعدين وتحديد نصاب كل مقيم', 'لوحة الموجة: الأسماء ومقاعدها وأنشطتها ونصاب كلٍّ منها', 'period.assessors'],
            ['تحديد المقابلات وحلقة النقاش للمقيمين', 'إسناد كل مقيّم إلى نشاطه: المقابلة الشخصية أو حلقة النقاش', 'period.activities'],
            ['اختيار مشاركين من قاعدة البيانات حسب العدد المطلوب جدولته', 'اختيار المشاركين وجدولة جلساتهم داخل مدى الموجة', 'period.participants'],
            ['إرسال الجدولة إلى مدير المركز للاعتماد', 'الإرسال ثم الاعتماد — من يبني الجدول لا يعتمده', 'period.approved'],
            ['إضافة التاريخ ورموز المشاركين في الجدول الذهبي', 'الجدول الذهبي: صفوف التواريخ ورموز المشاركين مجمّعةً بالقطاع', 'period.golden_synced'],
            ['ربط كل مشارك مع المستشار حسب الخبرات', 'مطابقة خبرة المستشار بسيرة المشارك قبل التعيين', 'period.evaluators_linked'],
            ['توزيع المستشارين على الجدول حسب العدد المطلوب يومياً', 'توزيع الأسماء على أيام الموجة بحسب الطاقة اليومية', 'period.daily_spread'],
            ['طباعة السيرة الذاتية والبطاقات للمشاركين وتصاريح الدخول', 'مستندات اليوم: السيرة الذاتية وبطاقة المشارك وتصريح الدخول', null],
            ['إضافة رموز المشاركين والتاريخ بقاعدة البيانات الأساسية', 'ترحيل الرموز والتواريخ إلى القاعدة الأساسية', 'period.dates_written'],
            ['إرسال الجدولة للوزارة (العسكريين/وكالة الشؤون العسكرية)(المدنيين/الموارد البشرية)', 'تسليم الجدولة لكل جهة بحسب فئة المشارك', 'period.dispatched'],
            ['توزيع كل قطاع على حدة على ملف PDF', 'ملفّ مستقل لكل قطاع', null],
        ];

        $rows = [];
        foreach ($steps as $i => [$title, $desc, $auto]) {
            $rows[] = [
                'position' => $i + 1,
                'title_ar' => $title,
                'description' => $desc,
                'auto_key' => $auto,
                'is_required' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('scheduling_workflow_steps')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_workflow_steps');
    }
};
