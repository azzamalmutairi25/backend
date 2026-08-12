<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  كل مسار مسجَّل موثَّق — والعكس
//
//  الوثيقة تنحرف عن الشيفرة بصمت: مسارٌ يُضاف ولا يُكتب، فيصير المرجع يكذب
//  على من يبني تكاملاً. حدث ذلك مرّتين في هذه المنصّة — ثلاثون مساراً غابت
//  عن المرجع، ثمّ **ملفّ مسارات كامل** (`routes/config.php`) لم يكن أحد
//  يعلم به لأنه لا يُحمَّل من حيث تُحمَّل البقيّة.
//
//  ولا حاجة إلى الانتباه ما دام الفحص آلياً: من أضاف مساراً بلا سطرٍ في
//  المرجع يعرف ذلك قبل الدمج لا بعد شهور.
// ════════════════════════════════════════════════════════════
class ApiDocumentationCoverageTest extends TestCase
{
    private const DOC = 'docs/API.md';

    /** الوسائط تُطبَّع: {id} و{candidateId} سواء — الوثيقة تسمّي بحسب السياق */
    private function normalize(string $s): string
    {
        return preg_replace('/\{\w+\??\}/', '{}', $s);
    }

    private function registeredPaths(): array
    {
        $paths = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (!str_starts_with($uri, 'api/')) {
                continue;
            }
            $paths[substr($uri, strlen('api'))] = true;   // يبقى '/'
        }
        ksort($paths);
        return array_keys($paths);
    }

    public function test_every_registered_route_appears_in_the_reference(): void
    {
        $doc = $this->normalize(file_get_contents(base_path(self::DOC)));

        $missing = [];
        foreach ($this->registeredPaths() as $path) {
            if (!str_contains($doc, '`' . $this->normalize($path) . '`')) {
                $missing[] = $path;
            }
        }

        $this->assertSame([], $missing,
            "مسارات مسجَّلة لا سطر لها في " . self::DOC . " — أضفها قبل الدمج:\n  "
            . implode("\n  ", $missing));
    }

    // الوجه الآخر: مسارٌ يُحذف من الشيفرة ويبقى في المرجع يقود المتكامِل إلى ٤٠٤
    public function test_the_reference_documents_no_route_that_no_longer_exists(): void
    {
        $doc = file_get_contents(base_path(self::DOC));
        $registered = array_map(fn ($p) => $this->normalize($p), $this->registeredPaths());

        // كل ما بين علامتَي اقتباس مائلة ويبدأ بشرطة مائلة — أي مسارٍ في المرجع
        preg_match_all('/`(\/[a-z0-9\-_\/{}\.]+)`/i', $doc, $m);

        $ghosts = [];
        foreach (array_unique($m[1]) as $cited) {
            // تُستثنى المسارات الملفّية (docs/…، app/…) والبادئة `/api` نفسها
            if (str_contains($cited, '.md') || str_contains($cited, '.php') || $cited === '/api') {
                continue;
            }
            if (!in_array($this->normalize($cited), $registered, true)) {
                $ghosts[] = $cited;
            }
        }

        $this->assertSame([], $ghosts,
            "مسارات في " . self::DOC . " لا وجود لها في الشيفرة — تقود المتكامِل إلى ٤٠٤:\n  "
            . implode("\n  ", $ghosts));
    }
}
