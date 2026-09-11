<?php

namespace Tests\Feature;

use App\Models\SchedulingPeriod;
use App\Services\SchedulingWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// اعتمادُ الموجة عند مسؤول الجدولة — والنصوص تقول ذلك.
//
// نُقل الاعتماد من مدير المركز في ٨ سبتمبر، وبقيت التسميةُ وإشعارُ الإرسال
// وعنوانُ خطوة الإجراء تقول «مدير المركز». ونصٌّ يسمّي غيرَ صاحب القرار يُرسِل
// الناس إلى الباب الخطأ.
class WaveApproverWordingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const OLD = 'إرسال الجدولة إلى مدير المركز للاعتماد';

    private const NEW = 'إرسال الجدولة إلى مسؤول الجدولة للاعتماد';

    private function migration(): object
    {
        return require database_path('migrations/2026_09_11_000001_wave_approval_step_names_the_scheduler.php');
    }

    private function stepTitle(): ?string
    {
        return DB::table('scheduling_workflow_steps')->where('auto_key', 'period.approved')->value('title_ar');
    }

    public function test_the_pending_label_names_the_scheduler(): void
    {
        $this->assertSame('بانتظار اعتماد مسؤول الجدولة', SchedulingPeriod::label('pending_center'));
    }

    public function test_the_automatic_check_names_the_scheduler(): void
    {
        $this->assertSame('اعتمد مسؤول الجدولة الموجة', SchedulingWorkflowService::CHECKS['period.approved']);
    }

    public function test_the_seeded_step_title_is_renamed(): void
    {
        DB::table('scheduling_workflow_steps')->where('auto_key', 'period.approved')->update(['title_ar' => self::OLD]);

        $this->migration()->up();

        $this->assertSame(self::NEW, $this->stepTitle());
    }

    // الإعدادات تحرّر العنوان — وتعديلُ المالك لا يُمحى
    public function test_an_owner_edited_title_is_left_alone(): void
    {
        DB::table('scheduling_workflow_steps')->where('auto_key', 'period.approved')
            ->update(['title_ar' => 'اعتماد الموجة — بصيغة المركز']);

        $this->migration()->up();

        $this->assertSame('اعتماد الموجة — بصيغة المركز', $this->stepTitle());
    }
}
