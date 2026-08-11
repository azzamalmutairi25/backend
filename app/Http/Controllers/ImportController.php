<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Assessment;
use App\Models\Sector;
use App\Models\AuditLog;
use App\Security\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportController extends Controller
{
    public function import(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية الاستيراد'], 403);
        }

        // سقفٌ صريح: `min:1` بلا `max` يقبل مصفوفةً بمئة ألف عنصر، فتُفتح دورة
        // معاملات بعددها في طلبٍ واحد — إنهاكٌ للخدمة بطلبٍ مصرَّحٍ به.
        $request->validate(
            ['rows' => 'required|array|min:1|max:' . self::MAX_ROWS],
            ['rows.max' => 'الحدّ الأقصى ' . self::MAX_ROWS . ' صفّاً في المرّة الواحدة'],
        );

        // القطاع يُقبل برمزه الداخلي أو ببادئته أو باسمه العربي.
        // الرمز الداخلي لا يظهر في أي شاشة، فاشتراطه وحده يُلزم مَن يعبّئ الملفّ
        // أن يعرف حقلاً لا يراه — وهو أكثر ما كان يُسقط صفوفاً صحيحة.
        $byKey = [];
        foreach (Sector::all() as $s) {
            $byKey[mb_strtoupper(trim((string) $s->code))] = $s;
            if ($s->participant_prefix) {
                $byKey[mb_strtoupper(trim((string) $s->participant_prefix))] = $s;
            }
            if ($s->name_ar) {
                $byKey[self::normalizeAr($s->name_ar)] = $s;
            }
        }

        $success = [];
        $errors = [];
        $failures = [];
        $seenIds = [];   // الهويات الواردة في الدفعة نفسها
        $userId = $request->user()->id;

        foreach ($request->rows as $i => $row) {
            $lineNum = $i + 1;
            // سطر ليس كائناً (نصّ/رقم) يرمي TypeError قبل الحماية فيُسقط الدفعة نصفها —
            // يُحوَّل لخطأ سطر ويُتابَع كبقية الأخطاء
            if (!is_array($row)) {
                $this->reject($errors, $failures, $lineNum, null, null, ['تنسيق السطر غير صحيح']);
                continue;
            }
            $nationalId = trim((string) ($row['nationalId'] ?? ''));
            $fullName = trim((string) ($row['fullName'] ?? ''));
            $mobile = trim((string) ($row['mobile'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));
            $sectorKey = trim((string) ($row['sectorCode'] ?? ''));
            $rankLabel = trim((string) ($row['rankLabel'] ?? ''));

            // ── الفحص: كل الأسباب تُجمع ولا يُكتفى بأوّلها ──
            // الرفض عند أوّل خطأ يجعل تصحيح الملفّ رحلاتٍ متكرّرة: يُصحّح
            // المستخدم الهوية فيظهر خطأ القطاع، ثم الرتبة. تُقال كلّها مرّة.
            $reasons = [];

            // الفحص هنا هو فحص إضافة المرشّح المفرد نفسه (SaudiNationalId):
            // كان الاستيراد يكتفي بطول عشرة، فهويةٌ يرفضها النموذج تدخل القاعدة
            // من هذا الباب — ثمّ تُرفض في التحقّق من البوّابة العامة فيتعطّل مرشّح.
            $idError = self::nationalIdError($nationalId);
            if ($idError !== null) $reasons[] = $idError;

            if ($fullName === '') $reasons[] = 'الاسم مفقود';
            elseif (mb_strlen($fullName) > 200) $reasons[] = 'الاسم أطول من ٢٠٠ حرف';

            $sector = null;
            if ($sectorKey === '') {
                $reasons[] = 'القطاع مفقود';
            } else {
                $sector = $byKey[mb_strtoupper($sectorKey)] ?? $byKey[self::normalizeAr($sectorKey)] ?? null;
                if (!$sector) $reasons[] = "قطاع غير معروف ({$sectorKey})";
            }

            if ($rankLabel === '') $reasons[] = 'الرتبة / المرتبة مفقودة';

            // الجوال والبريد لم يكونا يُفحصان هنا إطلاقاً بينما يفرضهما store:
            // جوّالٌ بصيغة 9665… يُقبل ثمّ لا تصل صاحبَه رسالة الدعوة أبداً.
            if ($mobile !== '' && !preg_match('/^05\d{8}$/', $mobile)) {
                $reasons[] = 'الجوال يجب أن يبدأ بـ05 ويكون ١٠ أرقام';
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $reasons[] = 'صيغة البريد الإلكتروني غير صحيحة';
            }

            // التكرار داخل الملفّ: الخادم كان يكشف المسجَّل في القاعدة فقط، فيُنشئ
            // الأول ويرفض الثاني بـ«مسجّلة مسبقاً» — رسالةٌ تتّهم القاعدة لا الملفّ.
            if ($idError === null) {
                if (isset($seenIds[$nationalId])) {
                    $reasons[] = "مكرّر — ورد في الصفّ {$seenIds[$nationalId]}";
                } elseif (Candidate::nationalIdExists($nationalId)) {
                    // لا نُعيد رقم الهوية في الرسالة (تفادي كشف الوجود عبر السجل/الرد)
                    $reasons[] = 'هذه الهوية مسجّلة مسبقاً في المنصّة';
                } else {
                    $seenIds[$nationalId] = $lineNum;
                }
            }

            if ($reasons) {
                $this->reject($errors, $failures, $lineNum, $nationalId, $fullName, $reasons);
                continue;
            }

            try {
                $tier = Candidate::classifyTier($rankLabel, $sector->is_military);
                // نفس مولّد بقية المسارات (يقرأ من جدول الدورات) — وإلا انجرف التسلسل عن store/reassess فصادم لاحقاً
                $code = Assessment::generateParticipantCode($sector);

                // مرشح + دورة تقييم معاً (كما في store) — وإلا بقي المرشح بلا دورة فكسر ثابت المزامنة و/confirm
                DB::transaction(function () use ($code, $nationalId, $fullName, $mobile, $email, $sector, $rankLabel, $tier, $userId) {
                    $c = new Candidate();
                    $c->participant_code = $code;
                    $c->national_id = $nationalId;
                    $c->full_name = $fullName;
                    $c->mobile = $mobile ?: null;
                    $c->email = $email ?: null;
                    $c->sector_id = $sector->id;
                    $c->rank_label = $rankLabel;
                    $c->tier = $tier;
                    $c->assessment_type = 'comprehensive';
                    $c->status = 'draft';
                    $c->save();

                    Assessment::create([
                        'candidate_id' => $c->id,
                        'participant_code' => $code,
                        'assessment_type' => 'comprehensive',
                        'status' => 'draft',
                        'created_by' => $userId,
                        'confirm_token' => Assessment::generateConfirmToken(),
                    ]);
                });

                $success[] = ['line' => $lineNum, 'code' => $code, 'name' => $fullName];
            } catch (\Illuminate\Database\QueryException $e) {
                // مَيّز تكرار الهوية الحقيقي عن تصادم رمز متزامن (سباق) — لا تُسمِّ التصادم «هوية مكرّرة» فتُسقِط مرشحاً صالحاً بسبب مضلّل
                $why = Candidate::nationalIdExists($nationalId)
                    ? 'هذه الهوية مسجّلة مسبقاً في المنصّة'
                    : 'تعذّر توليد رمز فريد (تعارض متزامن) — أعد المحاولة';
                $this->reject($errors, $failures, $lineNum, $nationalId, $fullName, [$why]);
            } catch (\Throwable $e) {
                // لا نُسرّب نص الاستثناء الخام للعميل
                \Illuminate\Support\Facades\Log::warning('candidate import row failed', ['line' => $lineNum, 'error' => $e->getMessage()]);
                $this->reject($errors, $failures, $lineNum, $nationalId, $fullName, ['تعذّر استيراد الصفّ']);
            }
        }

        AuditLog::create([
            'user_id' => $userId,
            'action' => 'IMPORT_CANDIDATES',
            'details' => ['imported' => count($success), 'failed' => count($failures)],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'اكتمل الاستيراد',
            'imported' => count($success),
            'failed' => count($failures),
            'successList' => $success,
            // `errors` نصوصٌ مسطّحة تبقى للتوافق مع أي مستهلك قديم،
            // و`failures` هي المبنيّة: الواجهة تعرض منها الصفّ وأسبابه معاً.
            'errors' => $errors,
            'failures' => $failures,
        ]);
    }

    // ── مُعينات ──

    private const MAX_ROWS = 500;

    /** يسجّل رفض صفّ في الشكلين معاً: نصّاً مسطّحاً ومبنيّاً */
    private function reject(array &$errors, array &$failures, int $line, ?string $nationalId, ?string $name, array $reasons): void
    {
        $failures[] = [
            'row' => $line,
            // الهوية تُعاد كما أرسلها العميل ليجد صفّه في ملفّه — وهي بياناته
            // التي رفعها قبل قليل، لا كشفٌ عن محتوى القاعدة.
            'nationalId' => $nationalId ?: null,
            'name' => $name ?: null,
            'reasons' => array_values($reasons),
        ];
        $errors[] = "الصفّ {$line}: " . implode(' · ', $reasons);
    }

    /**
     * فحص رقم الهوية السعودي — نفس منطق App\Rules\SaudiNationalId.
     * يُرجع سبب الرفض أو null إن صحّ.
     */
    private static function nationalIdError(string $id): ?string
    {
        if (!preg_match('/^\d{10}$/', $id)) return 'رقم الهوية يجب أن يكون ١٠ أرقام';
        if ($id[0] !== '1' && $id[0] !== '2') return 'رقم الهوية يجب أن يبدأ بـ١ (مواطن) أو ٢ (مقيم)';

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $d = (int) $id[$i];
            if ($i % 2 === 0) {
                $x = $d * 2;
                $sum += $x > 9 ? $x - 9 : $x;
            } else {
                $sum += $d;
            }
        }
        return $sum % 10 === 0 ? null : 'رقم الهوية غير صحيح (فشل التحقّق)';
    }

    /**
     * تطبيع نصّ عربي للمطابقة: تُزال التشكيلات والمسافات و«ال» التعريف،
     * وتُوحَّد الألف والهمزات والتاء المربوطة والياء. فيُطابَق «الاتصالات»
     * و«اتصالات» و«الإتصالات» على قطاعٍ واحد.
     */
    private static function normalizeAr(string $s): string
    {
        $s = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $s);   // تشكيل وتطويل
        $s = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $s);
        $s = str_replace(['ة'], 'ه', $s);
        $s = str_replace(['ى'], 'ي', $s);
        $s = preg_replace('/\s+/u', '', $s);
        $s = preg_replace('/^ال/u', '', $s);
        return $s;
    }
}
