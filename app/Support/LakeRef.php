<?php

namespace App\Support;

// ════════════════════════════════════════════════════════════════════════
//  سكُّ المعرّفات البديلة للبحيرة
//
//  لماذا HMAC لا تجزئة عادية: المنصّة تحتفظ بـ national_id_hash وهو
//  SHA-256 بلا ملح على فضاء هويةٍ سعوديّة من عشر خانات — أي أنه قابلٌ
//  للعكس بالبحث الشامل في ثوانٍ. نشرُه كان سيساوي نشرَ رقم الهوية نفسه.
//  المعرّف هنا مُملَّح بفلفلٍ لا يغادر خادم التطبيق، فلا يُعكس ولا يُطابَق
//  بقاموسٍ حتى لو سُرّبت البحيرةُ كاملة.
//
//  ولماذا على candidate_id لا على رقم الهوية: المعرّف الداخلي لا معنى له
//  خارج المنصّة، فحتى لو انكشف الفلفل لا يُشتقّ منه هوية — بينما اشتقاقُه
//  من رقم الهوية كان يجعل الفلفلَ وحده هو الفاصل بين المجهوليّة والانكشاف.
// ════════════════════════════════════════════════════════════════════════

class LakeRef
{
    // فشلٌ صريح لا مجهوليّةٌ زائفة: بفلفلٍ فارغ يصير المعرّف قابلاً
    // لإعادة الحساب من قبل أيّ أحد، وهي أسوأ من التعطّل لأنها لا تُرى.
    private static function pepper(): string
    {
        $p = config('lake.pepper');
        if (!is_string($p) || strlen($p) < 16) {
            throw new \RuntimeException(
                'LAKE_PEPPER غير مضبوط (٣٢ محرفاً فأكثر). ' .
                'بدونه يصير المعرّف البديل قابلاً لإعادة الحساب، فلا يُسكّ.'
            );
        }
        return $p;
    }

    // معرّفٌ ثابت للشخص عبر كل دوراته. تغييرُ الفلفل يقطع الاستمرارية
    // مع ما سبق — يُغيَّر بقرارٍ موثّق لا بالخطأ.
    public static function person(int $candidateId): string
    {
        return hash_hmac('sha256', 'candidate:' . $candidateId, self::pepper());
    }

    // معرّفُ فاعلٍ للمستخدم الإداري. لا يُنشر في العقد اليوم؛ يُسكّ
    // ليصير تتبّعُ «من فعل» ممكناً دون كشف من هو.
    public static function actor(?int $userId): ?string
    {
        return $userId === null ? null : hash_hmac('sha256', 'user:' . $userId, self::pepper());
    }

    // UUIDv5 — اشتقاقيّ من فضاء الأسماء ومن هويّة الانتقال نفسه.
    // هذا ما يجعل إعادةَ الإرسال بلا ضرر: المفتاح نفسه يُنتج المعرّف نفسه،
    // فيمتصّه ON CONFLICT في البحيرة بدل أن يُنشئ صفّاً ثانياً.
    public static function eventUuid(string $key): string
    {
        $ns = str_replace('-', '', (string) config('lake.uuid_namespace'));
        $hash = sha1(hex2bin($ns) . $key, true);

        $bytes = substr($hash, 0, 16);
        // إصدار ٥ ومتغيّر RFC 4122
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
