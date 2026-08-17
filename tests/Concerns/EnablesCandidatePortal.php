<?php

namespace Tests\Concerns;

// ════════════════════════════════════════════════════════════
//  تشغيل بوّابة المرشّح لاختبارها وحدها.
//
//  البوّابة مُعطَّلة في التشغيل (config/features.php ⇒ candidate_portal)،
//  ومساراتها لا تُسجَّل أصلاً. لكنّ شيفرتها باقية عمداً لتُعاد لاحقاً —
//  وشيفرةٌ باقية بلا اختبارٍ يمرّ عليها تتعفّن بصمت: يتغيّر CvValidator أو
//  CvGuard أو شكل الوثيقة، فلا يكتشف أحدٌ الكسر إلا يوم إعادة التشغيل.
//
//  فبدل حذف الاختبارات أو تخطّيها، تُشغّل هي البوّابة لنفسها: الحارس يبقى
//  مغلقاً في الإنتاج، والاختبار يبقى شاهداً أن ما خلفه سليم.
//
//  المفتاح يُضبط قبل parent::setUp لأن المسارات تُسجَّل أثناء إقلاع التطبيق،
//  وضبطُه بعده يغيّر الإعداد ولا يُعيد تسجيل مسارٍ فات وقتُ تسجيله.
// ════════════════════════════════════════════════════════════
trait EnablesCandidatePortal
{
    protected function setUp(): void
    {
        $_ENV['CANDIDATE_PORTAL_ENABLED'] = 'true';
        $_SERVER['CANDIDATE_PORTAL_ENABLED'] = 'true';
        putenv('CANDIDATE_PORTAL_ENABLED=true');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // التنظيف إلزامي: مستودع البيئة يُقرأ من $_ENV حيّاً، فبقاء القيمة
        // يُشغّل البوّابة في اختبارٍ يفترض أنها مغلقة (وأحدُها يتحقّق من ذلك)
        unset($_ENV['CANDIDATE_PORTAL_ENABLED'], $_SERVER['CANDIDATE_PORTAL_ENABLED']);
        putenv('CANDIDATE_PORTAL_ENABLED');

        parent::tearDown();
    }
}
