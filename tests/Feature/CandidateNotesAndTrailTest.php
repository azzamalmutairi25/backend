<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  الملاحظات، وسجلّ من فعل ماذا بالمشارك
//
//  الملاحظة ليست بياناً شخصياً، فلا تُحرَس بحارسه: مسارُها مستقلّ عن التعديل
//  الكامل لأن ذاك يشترط الهوية والاسم — وهما محجوبان عمّن لا يملك
//  CANDIDATE_VIEW_NAMES، فكان يُردّ بـ٤٢٢ على حقلٍ لا يراه أصلاً.
//
//  والرحلة تحمل الآن أفعال **الكتابة** من سجلّ التدقيق: من أضاف ومن عدّل.
//  وأفعال **القراءة** تبقى خارجها — «فلانٌ اطّلع على البيانات الشخصية» سجلٌّ
//  رقابي موضعُه شاشة التدقيق بحارسها، لا رحلةٌ يفتحها كل من يرى المشارك.
// ════════════════════════════════════════════════════════════
class CandidateNotesAndTrailTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_notes_are_saved_and_returned(): void
    {
        [$c] = $this->makeCandidate();
        $this->actingAsRole('SCHEDULER');

        $this->patchJson("/api/candidates/{$c->id}/notes", ['notes' => 'يحتاج متابعة'])
            ->assertOk()->assertJson(['notes' => 'يحتاج متابعة']);

        $this->assertSame('يحتاج متابعة', $c->fresh()->notes);
        $this->getJson("/api/candidates/{$c->id}")->assertOk()->assertJsonPath('candidate.notes', 'يحتاج متابعة');
    }

    // الملاحظة اختيارية: تفريغها فعلٌ صحيح لا خطأ تحقّق
    public function test_notes_can_be_cleared(): void
    {
        [$c] = $this->makeCandidate();
        $c->notes = 'قديمة';
        $c->save();
        $this->actingAsRole('SCHEDULER');

        $this->patchJson("/api/candidates/{$c->id}/notes", ['notes' => null])->assertOk();
        $this->assertNull($c->fresh()->notes);
    }

    public function test_saving_notes_requires_edit_permission(): void
    {
        [$c] = $this->makeCandidate();
        $this->actingAsRole('EXTERNAL_ADD');

        $this->patchJson("/api/candidates/{$c->id}/notes", ['notes' => 'x'])->assertStatus(403);
    }

    // ── سجلّ من فعل ماذا ──
    public function test_the_journey_names_who_created_and_who_edited(): void
    {
        [$c] = $this->makeCandidate();
        $user = $this->actingAsRole('SCHEDULER');

        foreach (['CREATE_CANDIDATE', 'UPDATE_CANDIDATE'] as $action) {
            AuditLog::create([
                'user_id' => $user->id, 'action' => $action,
                'entity_type' => 'candidate', 'entity_id' => (string) $c->id,
                'ip_address' => '127.0.0.1', 'created_at' => now(),
            ]);
        }

        // يُقرأ من درج التفاصيل — بصلاحية عرض المشارك لا بصلاحية الرحلة:
        // مسؤول الجدولة يُدخل المشاركين ويعدّلهم ولا يملك candidate.journey
        $trail = collect($this->getJson("/api/candidates/{$c->id}")->assertOk()->json('candidate.trail'));

        $this->assertNotNull($trail->firstWhere('title', 'أُضيف المشارك إلى النظام'));
        $this->assertSame($user->full_name, $trail->firstWhere('title', 'عُدّلت بيانات المشارك')['actor']);
        // كل حدثٍ يحمل وقته — وعليه يُبنى العرض بالتاريخ الميلادي
        $trail->each(fn ($e) => $this->assertNotNull($e['at']));
    }

    // الاطّلاع على البيانات الشخصية سجلٌّ رقابي — لا يُسرَّب عبر الرحلة
    public function test_read_actions_never_leak_into_the_journey(): void
    {
        [$c] = $this->makeCandidate();
        $user = $this->actingAsRole('SCHEDULER');
        AuditLog::create([
            'user_id' => $user->id, 'action' => 'VIEW_CANDIDATE_PII',
            'entity_type' => 'candidate', 'entity_id' => (string) $c->id,
            'ip_address' => '127.0.0.1', 'created_at' => now(),
        ]);

        $res = $this->getJson("/api/candidates/{$c->id}")->assertOk();

        $this->assertStringNotContainsString('VIEW_CANDIDATE_PII', json_encode($res->json('candidate.trail')));
        $this->assertStringNotContainsString('اطّلاع', json_encode($res->json('candidate.trail')));
    }
}
