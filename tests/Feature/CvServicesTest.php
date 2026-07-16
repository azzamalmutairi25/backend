<?php

namespace Tests\Feature;

use App\Exceptions\CvTooLargeException;
use App\Models\CandidateCv;
use App\Services\CvGuard;
use App\Services\CvValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// خدمتا التحقّق والحجب — نواة الصحّة. تُختبر قبل أي مسار.
class CvServicesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function candidate(string $name = 'محمد عبدالله الشهري', array $extra = [])
    {
        [$c] = $this->makeCandidate(array_merge(['fullName' => $name, 'status' => 'scheduled'], $extra));
        return $c;
    }

    private function doc(array $over = []): array
    {
        return array_merge([
            'currentPosition' => 'مدير إدارة',
            'totalYearsExperience' => 12,
            'briefBio' => 'قيادي بخبرة واسعة في القطاع',
            'qualifications' => [['degree' => 'master', 'major' => 'إدارة', 'institution' => 'جامعة الملك سعود', 'gradYear' => 2010]],
            'experiences' => [['position' => 'مدير', 'organization' => 'وزارة', 'fromYear' => 2011, 'toYear' => null, 'current' => true, 'summary' => 'قيادة فريق']],
            'certifications' => [['name' => 'شهادة القيادة', 'issuer' => 'المعهد', 'year' => 2015]],
        ], $over);
    }

    // ── المُحقّق ──

    public function test_validator_accepts_a_clean_document(): void
    {
        $clean = (new CvValidator())->clean($this->doc());
        $this->assertSame('master', $clean['qualifications'][0]['degree']);
        $this->assertSame(12, $clean['totalYearsExperience']);
        $this->assertTrue($clean['experiences'][0]['current']);
        $this->assertNull($clean['experiences'][0]['toYear']);
    }

    public function test_validator_drops_unknown_keys(): void
    {
        $clean = (new CvValidator())->clean($this->doc(['status' => 'approved', 'candidate_id' => 999, 'evil' => 1]));
        $this->assertArrayNotHasKey('status', $clean);
        $this->assertArrayNotHasKey('candidate_id', $clean);
        $this->assertArrayNotHasKey('evil', $clean);
    }

    public function test_validator_rejects_bad_degree_and_years(): void
    {
        $this->expectException(ValidationException::class);
        (new CvValidator())->clean($this->doc(['qualifications' => [['degree' => 'phd', 'institution' => 'x', 'gradYear' => 3000]]]));
    }

    public function test_validator_cross_field_current_with_toyear(): void
    {
        $this->expectException(ValidationException::class);
        (new CvValidator())->clean($this->doc(['experiences' => [['position' => 'a', 'organization' => 'b', 'fromYear' => 2010, 'toYear' => 2012, 'current' => true, 'summary' => '']]]));
    }

    public function test_validator_array_bomb_is_413(): void
    {
        $this->expectException(CvTooLargeException::class);
        (new CvValidator())->clean($this->doc(['experiences' => array_fill(0, 5000, ['position' => 'a', 'organization' => 'b', 'fromYear' => 2010, 'current' => true])]));
    }

    public function test_validator_rejects_latin_run_in_bio(): void
    {
        $this->expectException(ValidationException::class);
        (new CvValidator())->clean($this->doc(['briefBio' => 'قيادي Mohammed في القطاع']));
    }

    // ── التنظيف ──

    public function test_sanitize_strips_tags_and_bidi(): void
    {
        // strip_tags يزيل الوسوم ويُبقي النصّ بينها (نصّ خامل — Vue يهرّبه عند العرض)
        $this->assertSame('xنص', CvGuard::sanitize("<script>x</script>نص"));
        $this->assertStringNotContainsString('<', CvGuard::sanitize("<b>نص</b>"));
        $this->assertSame('اب', CvGuard::sanitize("ا\u{202E}ب")); // تجاوز الاتجاه محذوف
        $this->assertNull(CvGuard::sanitize("\u{200B}  "));
    }

    // ── حجب تسرّب الاسم ──

    public function test_blocks_arabic_family_name_alone(): void
    {
        $c = $this->candidate('محمد عبدالله الشهري');
        $doc = $this->doc(['currentPosition' => 'مدير في مكتب الشهري']);
        $this->assertSame('currentPosition', CvGuard::directIdentifierHit($doc, $c));
    }

    public function test_blocks_arabic_given_name_alone(): void
    {
        $c = $this->candidate('محمد عبدالله الشهري');
        $doc = $this->doc(['briefBio' => 'أنا محمد قيادي متمرّس']);
        $this->assertSame('briefBio', CvGuard::directIdentifierHit($doc, $c));
    }

    public function test_blocks_latin_transliteration_of_name(): void
    {
        $c = $this->candidate('محمد عبدالله الشهري');
        // اسم لاتيني في حقل منظّم (يُسمح فيه باللاتيني)
        $doc = $this->doc(['certifications' => [['name' => 'Award to Mohammed', 'year' => 2018]]]);
        $this->assertNotNull(CvGuard::directIdentifierHit($doc, $c));
    }

    public function test_blocks_national_id_and_mobile_and_email(): void
    {
        $c = $this->candidate('سعد الغامدي', ['nationalId' => '1010101010', 'mobile' => '0512345678']);
        $this->assertNotNull(CvGuard::directIdentifierHit($this->doc(['briefBio' => 'رقمي ١٠١٠١٠١٠١٠ للتواصل']), $c));
        $this->assertNotNull(CvGuard::directIdentifierHit($this->doc(['currentPosition' => 'جوال 0512345678']), $c));
        $this->assertNotNull(CvGuard::directIdentifierHit($this->doc(['experiences' => [['position' => 'x', 'organization' => 'a@b.com', 'fromYear' => 2010, 'current' => true]]]), $c));
    }

    public function test_clean_document_passes_identifier_check(): void
    {
        $c = $this->candidate('محمد عبدالله الشهري');
        $this->assertNull(CvGuard::directIdentifierHit($this->doc(), $c));
    }

    // ── الطمس عند العرض ──

    public function test_scrub_redacts_name_but_keeps_content(): void
    {
        $c = $this->candidate('محمد عبدالله الشهري');
        $scrubbed = CvGuard::scrub($this->doc(['currentPosition' => 'مدير مكتب الشهري للاستشارات']), $c);
        $this->assertStringContainsString('«•••»', $scrubbed['currentPosition']);
        $this->assertStringContainsString('مكتب', $scrubbed['currentPosition']);
        $this->assertStringNotContainsString('الشهري', $scrubbed['currentPosition']);
    }

    public function test_scrub_redacts_long_digits_and_email(): void
    {
        $c = $this->candidate('سعد الغامدي');
        $scrubbed = CvGuard::scrub($this->doc(['briefBio' => 'تواصل a@b.com أو ١٢٣٤٥٦٧٨٩٠']), $c);
        $this->assertStringNotContainsString('a@b.com', $scrubbed['briefBio']);
        $this->assertStringContainsString('«•••»', $scrubbed['briefBio']);
    }

    // ── نموذج السيرة ──

    public function test_cv_model_round_trips_encrypted(): void
    {
        $c = $this->candidate();
        $cv = CandidateCv::create(['candidate_id' => $c->id, 'data' => $this->doc(), 'version' => 1, 'source' => 'portal']);
        $this->assertSame('master', $cv->fresh()->data['qualifications'][0]['degree']);
        // العمود مشفّر، لا يظهر في JSON
        $this->assertArrayNotHasKey('cv_data_enc', $cv->toArray());
    }

    public function test_is_empty_doc(): void
    {
        $this->assertTrue(CandidateCv::isEmptyDoc(CandidateCv::emptyDoc()));
        $this->assertFalse(CandidateCv::isEmptyDoc($this->doc()));
    }
}
