<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// بطاقات المشاركين — مستند الطباعة ونطاقه.
class ParticipantCardTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_requires_candidate_view(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        // EXTERNAL_ADD يملك الإضافة فقط، لا العرض
        $this->actingAsRole('EXTERNAL_ADD');

        $this->get('/api/candidates/cards?ids=' . $c->id)->assertStatus(403);
    }

    public function test_renders_a_card_per_participant(): void
    {
        [$a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW', 'code' => 'DW-101']);
        [$b] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW', 'code' => 'DW-102']);
        $this->actingAsRole('SCHEDULER');

        $res = $this->get("/api/candidates/cards?ids={$a->id},{$b->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $res->getContent();
        $this->assertSame(2, substr_count($html, 'class="card"'));
        $this->assertStringContainsString('DW-101', $html);
        $this->assertStringContainsString('DW-102', $html);
        $this->assertStringContainsString('مركز تمكين الكفاءات', $html);
    }

    // البطاقة تحمل الرمز وحده — الاسم والهوية لا يخرجان عليها لأحد
    public function test_card_never_carries_name_or_national_id(): void
    {
        [$c] = $this->makeCandidate([
            'status' => 'scheduled', 'sectorCode' => 'DW',
            'fullName' => 'مرشح ذو اسم صريح',
        ]);
        $nid = $c->national_id;

        // مسؤول الجدولة يملك candidate.view_names ومع ذلك لا يظهران
        $this->actingAsRole('SCHEDULER');
        $html = $this->get('/api/candidates/cards?ids=' . $c->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('مرشح ذو اسم صريح', $html);
        $this->assertStringNotContainsString($nid, $html);
        $this->assertStringContainsString($c->participant_code, $html);
    }

    // الشعار يُضمَّن مرة واحدة مهما كثرت البطاقات
    public function test_emblem_is_embedded_once(): void
    {
        $ids = [];
        foreach (range(1, 6) as $i) {
            [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
            $ids[] = $c->id;
        }
        $this->actingAsRole('SCHEDULER');

        $html = $this->get('/api/candidates/cards?ids=' . implode(',', $ids))->assertOk()->getContent();

        $this->assertSame(6, substr_count($html, 'class="card"'));
        $this->assertSame(1, substr_count($html, 'data:image/png;base64'));
    }

    // ── مشارك واحد: صفحة بمقاس البطاقة، لا ورقة Letter ببطاقة في زاويتها ──

    public function test_single_card_gets_a_card_sized_page(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW', 'code' => 'DW-777']);
        $this->actingAsRole('SCHEDULER');

        $html = $this->get('/api/candidates/cards?ids=' . $c->id)->assertOk()->getContent();

        $this->assertStringContainsString('@page { size:91.4mm 52.3mm; margin:0; }', $html);
        $this->assertStringNotContainsString('size:Letter', $html);
        $this->assertStringNotContainsString('display:grid', $html);
        $this->assertStringContainsString('<title>بطاقة المشارك — DW-777</title>', $html);
        $this->assertSame(1, substr_count($html, 'class="card"'));
    }

    public function test_multiple_cards_keep_the_letter_sheet(): void
    {
        $ids = [];
        foreach (range(1, 3) as $i) {
            [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
            $ids[] = $c->id;
        }
        $this->actingAsRole('SCHEDULER');

        $html = $this->get('/api/candidates/cards?ids=' . implode(',', $ids))->assertOk()->getContent();

        $this->assertStringContainsString('@page { size:Letter; margin:0; }', $html);
        $this->assertStringContainsString('display:grid', $html);
        $this->assertSame(3, substr_count($html, 'class="card"'));
    }

    // اختيار مشاركَين يسقط أحدهما خارج النطاق ينتهي ببطاقة واحدة —
    // فيجب أن تُطبع كبطاقة مفردة لا كورقة، والعبرة بما بقي لا بما طُلب
    public function test_scope_reduced_selection_renders_as_single(): void
    {
        [$mine] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        [$secret] = $this->makeCandidate([
            'status' => 'scheduled', 'sectorCode' => 'DW', 'classification' => 'secret',
        ]);
        $this->actingAsRole('SCHEDULER');

        $html = $this->get("/api/candidates/cards?ids={$mine->id},{$secret->id}")->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="card"'));
        $this->assertStringContainsString('@page { size:91.4mm 52.3mm; margin:0; }', $html);
    }

    public function test_ids_are_required(): void
    {
        $this->actingAsRole('SCHEDULER');
        $this->getJson('/api/candidates/cards')->assertStatus(422);
        $this->getJson('/api/candidates/cards?ids=,,')->assertStatus(422);
    }

    // خارج النطاق يسقط صامتاً — لا يُفرَّق بين «ليس لك» و«غير موجود»
    public function test_out_of_scope_ids_are_dropped(): void
    {
        [$mine] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        [$classified] = $this->makeCandidate([
            'status' => 'scheduled', 'sectorCode' => 'DW', 'classification' => 'secret',
        ]);

        $this->actingAsRole('SCHEDULER');   // بلا CANDIDATE_VIEW_CLASSIFIED
        $html = $this->get("/api/candidates/cards?ids={$mine->id},{$classified->id}")->assertOk()->getContent();

        $this->assertStringContainsString($mine->participant_code, $html);
        $this->assertStringNotContainsString($classified->participant_code, $html);
        $this->assertSame(1, substr_count($html, 'class="card"'));
    }

    public function test_all_out_of_scope_returns_422(): void
    {
        [$classified] = $this->makeCandidate([
            'status' => 'scheduled', 'sectorCode' => 'DW', 'classification' => 'top_secret',
        ]);
        $this->actingAsRole('SCHEDULER');

        $this->getJson('/api/candidates/cards?ids=' . $classified->id)->assertStatus(422);
    }

    public function test_printing_is_audited(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $this->actingAsRole('SCHEDULER');

        $this->get('/api/candidates/cards?ids=' . $c->id)->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'PRINT_PARTICIPANT_CARDS']);
    }
}
