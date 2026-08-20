<?php

namespace App\Services;

use App\Exceptions\CvTooLargeException;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateCv;
use App\Models\Rank;
use App\Models\Sector;
use App\Models\TechnicalArea;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// ════════════════════════════════════════════════════════════
//  استيراد المشاركين — منطقٌ واحد يخدم البابين.
//
//  كان هذا كلّه في `ImportController::import`: ثلاثمئة سطرٍ في دالّة واحدة
//  تقرأ من `$request` وتردّ استجابة. فلمّا صار الملفّ يبلغ عشرة آلاف صفّ
//  ولم يعد يُعالَج في نداءٍ واحد، لم يكن ثمّة ما يُستدعى من وظيفة الطابور
//  إلا أن يُنسخ الثلاثمئة — ونسختان تتباعدان عند أوّل قاعدة تُشدَّد في
//  إحداهما.
//
//  فصار المنطق هنا بلا `$request` ولا استجابة: يأخذ صفوفاً ويردّ نتيجة.
//  يستدعيه المسار المتزامن للملفّات الصغيرة، والوظيفة الخلفية للكبيرة.
//
//  ── التكرار عبر الدفعات ──
//  كشف «مكرّر داخل الملفّ» كان يعتمد على خريطةٍ تُبنى وتموت مع النداء. وهي
//  في المعالجة المُقطَّعة تموت مع كل دفعة، فتمرّ هويةٌ وردت في الدفعة الأولى
//  ثانيةً في الثالثة — يُنشأ لها مشاركان. فصارت الخريطة تدخل وتخرج، ويحملها
//  المُستدعي بين الدفعات.
// ════════════════════════════════════════════════════════════
class CandidateImporter
{
    /**
     * @param  array  $rows        صفوف الدفعة
     * @param  int    $userId      من ينسب إليه الإنشاء
     * @param  int    $lineOffset  رقم أوّل صفّ في الملفّ كلّه (للتقطيع)
     * @param  array  $seenIds     [الهوية => رقم الصفّ] من الدفعات السابقة، تُعدَّل في مكانها
     * @return array{success: array, errors: array, failures: array}
     */
    public function import(array $rows, int $userId, int $lineOffset = 0, array &$seenIds = []): array
    {
        $success = [];
        $errors = [];
        $failures = [];

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

        // القائمة المُدارة للرتب، مفهرسةً بالفئة ثم بالتسمية المطبَّعة —
        // استعلامٌ واحد لا استعلامٌ لكل صفّ.
        $ranksByCat = ['military' => [], 'civilian' => []];
        foreach (Rank::where('is_active', true)->get() as $r) {
            $ranksByCat[$r->category][self::normalizeAr($r->label)] = true;
        }

        // المجالات الفنية مفهرسةً بتسميتها المطبَّعة — استعلامٌ واحد لا لكل صفّ.
        // المعطَّلة تُقبل في الاستيراد: ملفٌّ أُعِدّ الأسبوع الماضي بمجالٍ عُطِّل
        // اليوم يُردّ كلّه، والتعطيل قصده إخفاؤه عن النماذج الجديدة لا إبطال ما مضى.
        $areasByKey = [];
        foreach (TechnicalArea::all() as $a) {
            $areasByKey[self::normalizeAr($a->label_ar)] = $a->id;
        }

        $success = [];
        $errors = [];
        $failures = [];
        
        foreach ($rows as $i => $row) {
            $lineNum = $lineOffset + $i + 1;
            // سطر ليس كائناً (نصّ/رقم) يرمي TypeError قبل الحماية فيُسقط الدفعة نصفها —
            // يُحوَّل لخطأ سطر ويُتابَع كبقية الأخطاء
            if (!is_array($row)) {
                self::reject($errors, $failures, $lineNum, null, null, ['تنسيق السطر غير صحيح']);
                continue;
            }
            $nationalId = trim((string) ($row['nationalId'] ?? ''));
            $fullName = trim((string) ($row['fullName'] ?? ''));
            $mobile = trim((string) ($row['mobile'] ?? ''));
            $sectorKey = trim((string) ($row['sectorCode'] ?? ''));
            $rankLabel = trim((string) ($row['rankLabel'] ?? ''));
            $categoryRaw = trim((string) ($row['personnelCategory'] ?? ''));
            $genderRaw = trim((string) ($row['gender'] ?? ''));
            $areasRaw = is_array($row['technicalAreas'] ?? null) ? $row['technicalAreas'] : [];
            $cvRaw = is_array($row['cv'] ?? null) ? $row['cv'] : [];

            // ── الفحص: كل الأسباب تُجمع ولا يُكتفى بأوّلها ──
            // الرفض عند أوّل خطأ يجعل تصحيح الملفّ رحلاتٍ متكرّرة: يُصحّح
            // المستخدم الهوية فيظهر خطأ القطاع، ثم الرتبة. تُقال كلّها مرّة.
            $reasons = [];

            // الفحص هنا هو فحص إضافة المشارك المفرد نفسه (SaudiNationalId):
            // كان الاستيراد يكتفي بطول عشرة، فهويةٌ يرفضها النموذج تدخل القاعدة
            // من هذا الباب — ثمّ تُرفض في التحقّق من البوّابة العامة فيتعطّل مشارك.
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

            // الرتبة تُطابَق على القائمة المُدارة لفئة القطاع. غير المُدرَجة
            // تدخل وتُصنَّف «وسطى» صامتةً، فيُقيَّم لواءٌ بمعايير القيادة الوسطى
            // ولا يظهر الخطأ في أي شاشة. ولا يُفحص إن كانت القائمة فارغة لتلك
            // الفئة: التجاوز التدريجي مقصود، والصرامة على جدولٍ لم يُملأ بعد
            // تُوقف الاستيراد كلّه بلا سبب.
            // الفئة عمودٌ اختياري في الملفّ: ملفّات الوزارة القائمة لا تحمله،
            // ورفضُها كلَّها لعمودٍ استُحدث اليوم يوقف الاستيراد بلا ذنب. فإن
            // غاب استُنتجت الفئة من الرتبة نفسها (مطابقتها على القائمة العسكرية)،
            // وإن كانت الرتبة غير معروفة في القائمتين قيلت الحاجةُ إلى العمود صراحةً.
            $category = self::categoryFromInput($categoryRaw);
            if ($categoryRaw !== '' && $category === null) {
                $reasons[] = "الفئة «{$categoryRaw}» غير معروفة — مدني أو عسكري أو متعاقد";
            }
            if ($category === null && $rankLabel !== '') {
                $category = self::inferCategory($rankLabel, $ranksByCat);
            }

            $rankWord = Candidate::rankWord($category ?? 'civilian');
            if ($rankLabel === '') {
                $reasons[] = "{$rankWord} مفقود" . ($category === 'contractor' ? '' : 'ة');
            } elseif ($category === null) {
                $reasons[] = "«{$rankLabel}» ليست في الرتب العسكرية ولا المراتب المدنية — أضف عمود «الفئة»";
            } elseif ($category !== 'contractor') {
                // المتعاقد مسمّاه حرّ فلا قائمة تُطابَق عليها؛ وغير المُدرَج من
                // رتب الصنفين يدخل ويُصنَّف «وسطى» صامتةً، فيُقيَّم لواءٌ بمعايير
                // القيادة الوسطى ولا يظهر الخطأ في أي شاشة.
                $known = $ranksByCat[$category] ?? [];
                if ($known && !isset($known[self::normalizeAr($rankLabel)])) {
                    $list = $category === 'military' ? 'الرتب العسكرية' : 'المراتب المدنية';
                    $reasons[] = "{$rankWord} «{$rankLabel}» ليست في قائمة {$list}";
                }
            }

            // الجوال والبريد لم يكونا يُفحصان هنا إطلاقاً بينما يفرضهما store:
            // جوّالٌ بصيغة 9665… يُقبل ثمّ لا تصل صاحبَه رسالة الدعوة أبداً.
            if ($mobile !== '' && !preg_match('/^05\d{8}$/', $mobile)) {
                $reasons[] = 'الجوال يجب أن يبدأ بـ05 ويكون ١٠ أرقام';
            }

            // ── الجنس ──
            $gender = self::genderFromInput($genderRaw);
            if ($genderRaw === '') {
                $reasons[] = 'الجنس مفقود';
            } elseif ($gender === null) {
                $reasons[] = "الجنس «{$genderRaw}» غير معروف — ذكر أو أنثى";
            }

            // ── المجالات الفنية ──
            // تصل بأسمائها كما في ترويسة الملفّ لا بمعرّفاتها: الملفّ يكتبه
            // إنسانٌ في القطاع لا يعرف معرّفات جدولٍ في قاعدة بيانات.
            $areaIds = [];
            foreach ($areasRaw as $label) {
                $key = self::normalizeAr((string) $label);
                if ($key === '') {
                    continue;
                }
                if (isset($areasByKey[$key])) {
                    $areaIds[] = $areasByKey[$key];
                } else {
                    $reasons[] = "مجال فنّي غير معروف ({$label})";
                }
            }
            $areaIds = array_values(array_unique($areaIds));
            if (!$areaIds && !array_filter($areasRaw)) {
                $reasons[] = 'لم يُحدَّد أي مجال فنّي — عليها يُبنى الترشيح';
            }

            // ── السيرة الذاتية — إلزامية كما في الإضافة اليدوية ──
            // يُعاد استعمال CvValidator نفسه: مسارُ تحقّقٍ واحد لكل الأبواب،
            // فلا تدخل من الاستيراد وثيقةٌ يردّها النموذج اليدوي.
            $cleanCv = null;
            if (!$cvRaw) {
                $reasons[] = 'بيانات السيرة الذاتية مفقودة';
            } else {
                $cvRaw = self::mapCvDegrees($cvRaw, $reasons);
                try {
                    $cleanCv = app(CvValidator::class)->clean($cvRaw);
                } catch (CvTooLargeException $e) {
                    $reasons[] = 'بيانات السيرة أكثر من المسموح';
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $messages) {
                        $reasons[] = 'السيرة: ' . $messages[0];
                    }
                }
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
                self::reject($errors, $failures, $lineNum, $nationalId, $fullName, $reasons);
                continue;
            }

            try {
                // المتعاقد المستورَد «وسطى» افتراضاً — الطبقة اختيارٌ صريح لا يحمله
                // الملفّ، وتُصحَّح من شاشة المشاركين
                $tier = Candidate::resolveTier($category, $rankLabel, null);
                // نفس مولّد بقية المسارات (يقرأ من جدول الدورات) — وإلا انجرف التسلسل عن store/reassess فصادم لاحقاً
                $code = Assessment::generateParticipantCode($sector);

                // مشارك + دورة تقييم + سيرة + مجالات معاً (كما في store) — وإلا
                // بقي المشارك بلا دورة فكسر ثابت المزامنة و/confirm، أو بلا سيرة
                // فدخل من هذا الباب ما يردّه النموذج اليدوي
                $leak = null;
                DB::transaction(function () use ($code, $nationalId, $fullName, $mobile, $sector, $gender, $rankLabel, $category, $tier, $userId, $cleanCv, $areaIds, &$leak) {
                    $c = new Candidate();
                    $c->participant_code = $code;
                    $c->national_id = $nationalId;
                    $c->full_name = $fullName;
                    $c->mobile = $mobile ?: null;
                    $c->sector_id = $sector->id;
                    $c->gender = $gender;
                    $c->rank_label = $rankLabel;
                    $c->personnel_category = $category;
                    $c->tier = $tier;
                    $c->assessment_type = 'comprehensive';
                    $c->status = 'draft';
                    $c->save();

                    // تسرّب اسم المشارك داخل سيرته — الفحص يحتاج بياناته فيقع
                    // بعد حفظه، والرمي يُرجِع المعاملة كلّها فلا يبقى صفٌّ ناقص.
                    // السيرة تصل المقيّم بلا اسم، فالمستورَد ليس معفىً منه.
                    if ($hit = CvGuard::directIdentifierHit($cleanCv, $c)) {
                        $leak = $hit;
                        throw new \RuntimeException('cv_identifier_leak');
                    }

                    $cv = new CandidateCv();
                    $cv->candidate_id = $c->id;
                    $cv->data = $cleanCv;
                    $cv->version = 1;
                    $cv->source = 'admin';
                    $cv->updated_by = $userId;
                    $cv->save();

                    $c->technicalAreas()->sync($areaIds);

                    Assessment::create([
                        'candidate_id' => $c->id,
                        'participant_code' => $code,
                        'assessment_type' => 'comprehensive',
                        'status' => 'draft',
                        'created_by' => $userId,
                        'confirm_token' => Assessment::generateConfirmToken(),
                    ]);

                    // قيدٌ لكل مشارك على حدة — القيد المجمَّع للاستيراد بلا
                    // entity_id، فكان درجُ المستورَد يُفتح بلا جوابٍ عن «من
                    // أضاف هذا؟» بينما يجيب عنه درجُ من أُدخل يدوياً
                    AuditLog::create([
                        'user_id' => $userId,
                        'action' => 'IMPORT_CANDIDATE',
                        'entity_type' => 'candidate',
                        'entity_id' => (string) $c->id,
                        'details' => ['code' => $code],
                        'created_at' => now(),
                    ]);
                });

                $success[] = ['line' => $lineNum, 'code' => $code, 'name' => $fullName];
            } catch (\Illuminate\Database\QueryException $e) {
                // مَيّز تكرار الهوية الحقيقي عن تصادم رمز متزامن (سباق) — لا تُسمِّ التصادم «هوية مكرّرة» فتُسقِط مشاركاً صالحاً بسبب مضلّل
                $why = Candidate::nationalIdExists($nationalId)
                    ? 'هذه الهوية مسجّلة مسبقاً في المنصّة'
                    : 'تعذّر توليد رمز فريد (تعارض متزامن) — أعد المحاولة';
                self::reject($errors, $failures, $lineNum, $nationalId, $fullName, [$why]);
            } catch (\Throwable $e) {
                // تسرّب الاسم سببٌ يُقال بعينه: صاحب الملفّ يستطيع إصلاحه،
                // و«تعذّر استيراد الصفّ» تتركه يعيد المحاولة بالملفّ نفسه
                if ($leak !== null) {
                    self::reject($errors, $failures, $lineNum, $nationalId, $fullName, [
                        "السيرة تحوي اسم المشارك أو معرّفاً ({$leak}) — أزِله",
                    ]);
                    continue;
                }
                // لا نُسرّب نص الاستثناء الخام للعميل
                \Illuminate\Support\Facades\Log::warning('candidate import row failed', ['line' => $lineNum, 'error' => $e->getMessage()]);
                self::reject($errors, $failures, $lineNum, $nationalId, $fullName, ['تعذّر استيراد الصفّ']);
            }
        }


        return ['success' => $success, 'errors' => $errors, 'failures' => $failures];
    }

    // ── مُعينات ──

    /** يسجّل رفض صفّ في الشكلين معاً: نصّاً مسطّحاً ومبنيّاً */
    private static function reject(array &$errors, array &$failures, int $line, ?string $nationalId, ?string $name, array $reasons): void
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
    // الفئة كما تُكتب في الملفّ — بالعربية أو بمفتاحها اللاتيني
    /**
     * الجنس من نصّه العربي أو الإنجليزي.
     *
     * الملفّات الواردة تكتبه بصيغٍ شتّى («ذكر»، «رجل»، «م»، «male»)، ورفضُ
     * الصيغة لا القيمة يوقف ملفّاً صحيحاً على اختلاف كاتبٍ في التعبير.
     */
    private static function genderFromInput(string $raw): ?string
    {
        $v = self::normalizeAr(mb_strtolower(trim($raw)));
        foreach ([
            'male' => ['ذكر', 'ذكر ', 'رجل', 'م', 'male', 'm'],
            'female' => ['انثي', 'انثى', 'امراه', 'سيده', 'ا', 'female', 'f'],
        ] as $key => $forms) {
            foreach ($forms as $form) {
                if ($v === self::normalizeAr(mb_strtolower($form))) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * تحويل المؤهلات من نصّها العربي إلى القائمة المغلقة قبل التحقّق.
     *
     * ما لا يُعرف **يُترك كما هو** ليردّه المحقّق برسالته، ويُضاف هنا سببٌ
     * يقول القيم المقبولة — فصاحب الملفّ يعرف ماذا يكتب لا أنّ شيئاً خطأ.
     */
    private static function mapCvDegrees(array $cv, array &$reasons): array
    {
        foreach (($cv['qualifications'] ?? []) as $i => $q) {
            $raw = (string) ($q['degree'] ?? '');
            if ($raw === '') {
                continue;
            }
            $mapped = CandidateCv::degreeFromArabic($raw);
            if ($mapped === null) {
                $reasons[] = "المؤهل «{$raw}» غير معروف — المقبول: " . CandidateCv::degreeChoices();
                continue;
            }
            $cv['qualifications'][$i]['degree'] = $mapped;
        }

        return $cv;
    }

    private static function categoryFromInput(string $raw): ?string
    {
        $v = self::normalizeAr(mb_strtolower(trim($raw)));
        foreach ([
            'civilian' => ['مدني', 'مدنيه', 'مدنية', 'civilian', 'civil'],
            'military' => ['عسكري', 'عسكريه', 'عسكرية', 'military'],
            'contractor' => ['متعاقد', 'متعاقده', 'متعاقدة', 'contractor', 'contract'],
        ] as $key => $spellings) {
            foreach ($spellings as $spelling) {
                if ($v === self::normalizeAr($spelling)) return $key;
            }
        }
        return null;
    }

    // بلا عمود «الفئة»: تُستنتج من الرتبة نفسها — المُدرَجة في الرتب العسكرية
    // عسكريّة، وفي المراتب المدنية مدنيّة، وما ليس في القائمتين لا يُخمَّن
    private static function inferCategory(string $rankLabel, array $ranksByCat): ?string
    {
        $key = self::normalizeAr($rankLabel);
        if (isset($ranksByCat['military'][$key])) return 'military';
        if (isset($ranksByCat['civilian'][$key])) return 'civilian';
        // قائمةٌ لم تُملأ بعدُ لا توقف الاستيراد (التجاوز التدريجي نفسه)
        if (!$ranksByCat['military'] && !$ranksByCat['civilian']) return 'civilian';
        return null;
    }

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
