<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// إصلاحات مراجعة المحادثات/الإشعارات:
//  - notifyRole عن «تقرير» لا يُذيع رمز المشارك خارج قطاع المرشّح ولا للمصنّفين بلا صلاحية.
//  - notify يقصّ العنوان إلى حدّ العمود (varchar 200) فلا يُسقط الإرسال بـ500.
class ChatNotifScopeFixesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function userWith(string $roleCode, ?string $sectorCode = null): User
    {
        return User::create([
            'username' => 'u_' . strtolower($roleCode) . '_' . substr(md5(uniqid('', true)), 0, 6),
            'full_name' => "مستخدم {$roleCode}",
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', $roleCode)->value('id'),
            'sector_id' => $sectorCode ? Sector::where('code', $sectorCode)->value('id') : null,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function reportFor(string $sectorCode, string $classification): FinalReport
    {
        [$c, $a] = $this->makeCandidate([
            'sectorCode' => $sectorCode,
            'classification' => $classification,
            'status' => 'assessed',
        ]);
        return FinalReport::create([
            'candidate_id' => $c->id,
            'assessment_id' => $a->id,
            'status' => 'pending_evaluator',
            'created_by' => null,
        ]);
    }

    private function svc(): NotificationService
    {
        return app(NotificationService::class);
    }

    // ── #1: مقيّم خارج القطاع لا يُشعَر بتقرير مرشّح قطاع آخر ──
    public function test_report_role_notification_skips_out_of_sector_evaluator(): void
    {
        $report = $this->reportFor('ED', 'normal');
        $inSector = $this->userWith('EVALUATOR', 'ED');
        $otherSector = $this->userWith('EVALUATOR', 'HI');

        $this->svc()->notifyRole('EVALUATOR', 'approval', 'عنوان',
            'تقرير المرشح ' . $report->candidate->participant_code . ' وصل مرحلة اعتمادك',
            'report', (string) $report->id, null);

        $this->assertSame(1, Notification::where('recipient_id', $inSector->id)->count());
        $this->assertSame(0, Notification::where('recipient_id', $otherSector->id)->count());
    }

    // ── #2: مرشّح مصنّف لا يصل رمزه لمقيّم بلا صلاحية رؤية المصنّفين (ولو في قطاعه) ──
    public function test_classified_report_notification_skips_uncleared_evaluator(): void
    {
        $report = $this->reportFor('ED', 'secret');
        $evaluator = $this->userWith('EVALUATOR', 'ED'); // لا يملك CANDIDATE_VIEW_CLASSIFIED

        $this->svc()->notifyRole('EVALUATOR', 'approval', 'عنوان',
            'تقرير المرشح ' . $report->candidate->participant_code . ' وصل مرحلة اعتمادك',
            'report', (string) $report->id, null);

        $this->assertSame(0, Notification::where('recipient_id', $evaluator->id)->count());
    }

    // ── #3: الدور المركزي المصرَّح له يبقى يُشعَر بأي قطاع/تصنيف ──
    public function test_central_cleared_role_still_notified_regardless_of_sector(): void
    {
        $report = $this->reportFor('ED', 'secret');
        $manager = $this->userWith('ASSESS_MANAGER'); // مركزي + CANDIDATE_VIEW_CLASSIFIED

        $this->svc()->notifyRole('ASSESS_MANAGER', 'approval', 'عنوان',
            'تقرير المرشح ' . $report->candidate->participant_code . ' وصل مرحلة اعتمادك',
            'report', (string) $report->id, null);

        $this->assertSame(1, Notification::where('recipient_id', $manager->id)->count());
    }

    // ── #4: إشعار بلا كيان تقرير يبقى يُذاع لكل الدور (سلوك عام كما كان) ──
    public function test_non_report_role_notification_is_not_scoped(): void
    {
        $a = $this->userWith('EVALUATOR', 'ED');
        $b = $this->userWith('EVALUATOR', 'HI');

        $this->svc()->notifyRole('EVALUATOR', 'info', 'تذكير عام', 'رسالة', null, null, null);

        $this->assertSame(1, Notification::where('recipient_id', $a->id)->count());
        $this->assertSame(1, Notification::where('recipient_id', $b->id)->count());
    }

    // ── #5: عنوان أطول من عمود varchar(200) يُقصّ فلا يُسقط الإنشاء بـ500 ──
    public function test_notification_title_is_capped_to_column_length(): void
    {
        $u = $this->userWith('SCHEDULER');
        $longTitle = str_repeat('ن', 260);

        $this->svc()->notify($u->id, 'info', $longTitle, 'متن');

        $notif = Notification::where('recipient_id', $u->id)->firstOrFail();
        $this->assertLessThanOrEqual(200, mb_strlen($notif->title));
    }
}
