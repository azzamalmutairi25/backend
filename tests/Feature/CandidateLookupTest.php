<?php

namespace Tests\Feature;

use App\Models\Candidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  فحص تكرار الهوية — يجيب، ولا يُعلّم أكثر ممّا يجيب
//
//  الباب يقول «مسجَّل / غير مسجَّل» لمن يملك الإضافة، ولا شيء غير ذلك لمن لا
//  يملك التعديل: لا رمز ولا اسم. وهذا هو موضع الانزلاق — إضافةُ حقلٍ «مفيد»
//  إلى الاستجابة غداً تُحوّل نموذجاً مساعداً إلى حاصدة رموزٍ بتجريب الهويات.
//  فالفحوص هنا على ما **لا** يُردّ بقدر ما هي على ما يُردّ.
// ════════════════════════════════════════════════════════════
class CandidateLookupTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_an_unregistered_id_reads_as_absent(): void
    {
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/candidates/lookup', ['nationalId' => $this->validNationalId()])
            ->assertOk()
            ->assertJson(['exists' => false])
            ->assertJsonMissingPath('addedAt');
    }

    public function test_a_registered_id_reads_as_duplicate_for_an_editor(): void
    {
        [$c] = $this->makeCandidate(['status' => 'completed', 'assessmentStatus' => 'completed']);
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/candidates/lookup', ['nationalId' => $c->national_id])
            ->assertOk()
            ->assertJson(['exists' => true, 'hasActiveCycle' => false, 'canEdit' => true]);
    }

    // الدورة النشطة تمنع دورةً جديدة في store — فتُعلَن هنا قبل ملء النموذج،
    // وإلا مُلئ كاملاً ليُردّ بما كان يمكن أن يُعرف من أول حقل
    public function test_an_active_cycle_is_announced_with_its_code_to_an_editor(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'draft', 'assessmentStatus' => 'draft']);
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/candidates/lookup', ['nationalId' => $c->national_id])
            ->assertOk()
            ->assertJson(['exists' => true, 'hasActiveCycle' => true, 'activeCode' => $a->participant_code]);
    }

    // ── ما لا يُردّ ──
    // المستخدم الخارجي يملك الإضافة فيملك السؤال، لكنه لا يملك القراءة:
    // يُخبَر بالتكرار ولا يُعطى الرمز — الرمز مفتاح الوصول إلى المشارك
    public function test_the_external_adder_learns_of_the_duplicate_but_never_the_code(): void
    {
        [$c] = $this->makeCandidate(['status' => 'draft', 'assessmentStatus' => 'draft']);
        $this->actingAsRole('EXTERNAL_ADD');

        $res = $this->postJson('/api/candidates/lookup', ['nationalId' => $c->national_id]);

        $res->assertOk()->assertJson(['exists' => true, 'canEdit' => false, 'activeCode' => null]);
        $this->assertStringNotContainsString($c->participant_code, $res->getContent());
        $this->assertStringNotContainsString($c->full_name, $res->getContent());
    }

    // سجلٌّ مصنَّف فوق درجة السائل يُردّ «غير موجود» كما في store: النفي الصادق
    // هنا إثباتٌ لوجوده، فيُعرف المصنَّفون بتجريب الهويات دون قراءة أي سجلّ
    public function test_a_classified_record_reads_as_absent_to_an_uncleared_asker(): void
    {
        [$c] = $this->makeCandidate(['classification' => 'secret']);
        $this->actingAsRole('EXTERNAL_ADD');

        $this->postJson('/api/candidates/lookup', ['nationalId' => $c->national_id])
            ->assertOk()
            ->assertJson(['exists' => false]);
    }

    public function test_a_user_without_create_permission_is_refused_before_validation(): void
    {
        $this->actingAsRole('MEASURE_SUPER');

        $this->postJson('/api/candidates/lookup', [])->assertStatus(403);
    }

    public function test_a_malformed_id_is_rejected(): void
    {
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/candidates/lookup', ['nationalId' => '123'])->assertStatus(422);
    }

    // إصابةُ التكرار قيدٌ في السجلّ: تجريبُ هوياتٍ متتابعة يجب أن يُرى
    public function test_a_duplicate_hit_is_audited(): void
    {
        [$c] = $this->makeCandidate();
        $user = $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/candidates/lookup', ['nationalId' => $c->national_id])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'LOOKUP_DUPLICATE_CANDIDATE',
            'entity_type' => 'candidate',
            'entity_id' => (string) $c->id,
        ]);
    }
}
