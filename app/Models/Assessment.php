<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// دورة تقييم واحدة للمشارك (رمز + حالة + تقييمات + تقرير)
class Assessment extends Model
{
    protected $fillable = [
        'candidate_id', 'participant_code', 'assessment_type', 'status', 'created_by',
        'confirm_token', 'confirmed_at', 'arrived_at',
    ];

    // ── نوع التقييم ──
    // اثنان لا ثلاثة: الشامل هو المسار المعتاد، و«طلب خاص» ما يخرج عنه.
    // وحلَّ «طلبٌ خاص» محلّ «تنفيذي» لأن ذاك كان يصف صاحب التقييم لا التقييم،
    // فيلتبس بطبقة القيادة (tier) وهي المكان الذي يوصف فيه الشخص فعلاً.
    //
    // والتسمية العربية هنا لا في المتحكّمات: كانت منسوخة في أربعة مواضع
    // (البوّابة، الاستقبال، الكشك مرّتين)، فكانت إضافة نوعٍ ثالثٍ يوماً تعني
    // تذكّر أربعة مواضع — ونسيانُ واحدٍ يُظهر «شامل» على ما ليس شاملاً.
    public const TYPES = ['comprehensive', 'special_request'];

    public const TYPE_LABELS = [
        'comprehensive' => 'شامل',
        'special_request' => 'طلب خاص',
    ];

    public static function typeLabel(?string $type): string
    {
        return self::TYPE_LABELS[$type] ?? self::TYPE_LABELS['comprehensive'];
    }

    /**
     * كل دورةٍ تُولَد بمحطّاتها الثلاث.
     *
     * في النموذج لا في المتحكّم: الدورة تُنشأ من ستّة مواضع (الإضافة،
     * والاستيراد، والبوّابة، والكشك، والاختبارات…)، وافتراضٌ يُكتب في كلٍّ
     * منها يُنسى في أحدها — فتخرج دورةٌ بلا محطّة، وهي **لا تكتمل أبداً**.
     */
    protected static function booted(): void
    {
        static::created(function (Assessment $assessment) {
            $now = now();
            DB::table('assessment_stations')->insertOrIgnore(
                collect(self::STATIONS)->map(fn ($s, $i) => [
                    'assessment_id' => $assessment->id,
                    'station' => $s,
                    'sort_order' => $i,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        });
    }

    protected $casts = [
        'confirmed_at' => 'datetime',
        'arrived_at' => 'datetime',
        'cv_snapshotted_at' => 'datetime',
        'first_session_date' => 'date',
        'last_session_date' => 'date',
    ];

    /**
     * إعادة حساب تاريخَي الدورة من جلساتها.
     *
     * تُستدعى من كل كاتبٍ للجلسات — إنشاءً وتعديلاً وحذفاً وإعادةَ جدولة
     * واعتماداً — فالتاريخ حقلٌ يُصدَّر ويُفلتَر، وحقلٌ لا يتبع مصدره يكذب.
     * الحساب من القاعدة لا من ذاكرة العلاقة: صفٌّ حُذف للتوّ يبقى محمَّلاً.
     */
    public function refreshSessionDates(): void
    {
        $b = DB::table('schedules')->where('assessment_id', $this->id)
            ->selectRaw('MIN(schedule_date) as first_d, MAX(schedule_date) as last_d')
            ->first();

        $this->forceFill([
            'first_session_date' => $b?->first_d,
            'last_session_date' => $b?->last_d,
        ])->save();
    }

    /** نظيرها حين لا يكون الكائن محمّلاً — تُستدعى بالمعرّف من المتحكّمات */
    public static function refreshDatesFor(?int $assessmentId): void
    {
        if ($assessmentId && ($a = self::find($assessmentId))) {
            $a->refreshSessionDates();
        }
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(FinalReport::class);
    }

    // قراءة منطقية للوثيقة المجمَّدة (السيرة كما كانت لحظة بدء التقييم)
    protected function cvSnapshot(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cv_snapshot_enc
                ? json_decode(Crypt::decryptString($this->cv_snapshot_enc), true)
                : null,
        );
    }

    // ══════════════════════════════════════════════════════
    //  المحطّات — والاكتمال يُقاس على ما اختير لا على ثلاثٍ محفورة
    // ══════════════════════════════════════════════════════

    public const STATIONS = ['interview', 'discussion', 'measurement'];

    public const STATION_LABELS = [
        'interview' => 'المقابلة الشخصية',
        'discussion' => 'حلقة النقاش',
        'measurement' => 'أدوات القياس',
    ];

    public static function stationLabel(?string $station): string
    {
        return self::STATION_LABELS[$station] ?? (string) $station;
    }

    public function stations(): HasMany
    {
        return $this->hasMany(AssessmentStation::class)->orderBy('sort_order')->orderBy('id');
    }

    /** المحطّات المطلوبة لهذه الدورة — بترتيب المرور */
    public function chosenStations(): array
    {
        return $this->relationLoaded('stations')
            ? $this->stations->pluck('station')->all()
            : $this->stations()->pluck('station')->all();
    }

    /**
     * المحطّات المُنجَزة — **من مصدرين لا من واحد**.
     *
     * المقابلة وحلقة النقاش تُنجَزان بتقييمٍ مُرسَل. أمّا أدوات القياس فلا
     * تمرّ بالرصد أصلاً: نتيجتها تُدخَل في `measurement_results`، ولا تُكتب
     * لها `evaluation` قطّ. فقياسُ الاكتمال من التقييمات وحدها كان يجعل
     * محطّةَ القياس مستحيلةً على الدوام، ودورةً تحملها لا تكتمل أبداً.
     */
    public function completedStations(): array
    {
        // «مُرسَل فما فوق» لا «مُرسَل» وحدها: التقييم يمضي draft ← submitted ←
        // approved، فحصرُه في الوسط يُسقط كلَّ ما اعتمده المدير — وهو أتمُّ
        // الحالات. القياس على «خرج من المسوّدة»، لا على وقوفه في محطّة بعينها.
        $done = DB::table('evaluations')
            ->where('assessment_id', $this->id)
            ->whereIn('status', ['submitted', 'approved'])
            ->whereIn('activity', self::STATIONS)
            ->pluck('activity')
            ->all();

        if (DB::table('measurement_results')->where('assessment_id', $this->id)->exists()) {
            $done[] = 'measurement';
        }

        return array_values(array_unique($done));
    }

    /** ما بقي من محطّاته — يُرفع إنذاراً للاستقبال باسمه */
    public function missingStations(): array
    {
        return array_values(array_diff($this->chosenStations(), $this->completedStations()));
    }

    /**
     * أتمّ كلَّ محطّاته؟
     *
     * ودورةٌ بلا محطّةٍ واحدة **ليست مكتملة**: صفٌّ ناقصٌ في البيانات لا
     * يُقرأ كإنجازٍ تامّ. والافتراضي يُكتب عند الإنشاء فلا تقع هذه الحال إلا
     * بحذفٍ يدويّ.
     */
    public function stationsComplete(): bool
    {
        $chosen = $this->chosenStations();

        return $chosen !== [] && $this->missingStations() === [];
    }

    /**
     * يقلب المشارك إلى «تمّ تقييمه» إن أتمّ محطّاته — وإلا يتركه.
     *
     * يُستدعى من كل ما يُنجز محطّة: إرسالُ تقييم، وحفظُ نتيجة قياس، وتغييرُ
     * قائمة المحطّات نفسها. ونقطةٌ واحدة لا ثلاث: القاعدة تتفرّع عند أوّل
     * تعديل، فيقلب أحدُ المسارات ما لا يقلبه الآخر.
     *
     * **ولا يحطّ أحداً عن حالته**: من صار «تمّ تقييمه» يبقى. الإرجاع يُبطلها
     * في مساره وحده، حيث يُقرأ السبب ويُكتب.
     */
    public function syncAssessedStatus(): bool
    {
        if (! $this->stationsComplete()) {
            return false;
        }

        $candidate = $this->candidate ?? Candidate::find($this->candidate_id);
        if ($candidate && $candidate->status === 'scheduled') {
            $candidate->setStatus('assessed');

            return true;
        }
        // الدورة قد تتقدّم وحدها إن كان المشارك في دورةٍ أحدث
        if ($this->status === 'scheduled') {
            $this->update(['status' => 'assessed']);

            return true;
        }

        return false;
    }

    // مجمَّدة = التُقِطت لقطة فعلاً أو تجاوزت الدورة مرحلة الرصد. لا نقفل لمجرّد
    // وجود مسودّة تقييم: لو بدأ المقيّم قبل أن يملأ المشارك سيرته لظلّ محبوساً بلقطة
    // فارغة. التجميد يحدث عند البدء إن كانت السيرة غير فارغة، وحتماً عند الإرسال.
    public function cvFrozen(): bool
    {
        return $this->cv_snapshot_enc !== null
            || in_array($this->status, ['assessed', 'approved', 'completed'], true);
    }

    // التقاط السيرة الحيّة في هذه الدورة مرة واحدة عند التجميد.
    // $onlyIfFilled: عند بدء التقييم لا نُجمّد سيرةً فارغة (نترك المشارك يُكملها).
    public function freezeCvSnapshot(bool $onlyIfFilled = false): void
    {
        if ($this->cv_snapshot_enc !== null) {
            return;
        } // مجمَّدة مسبقاً — لا تُكتب فوقها أبداً
        $cv = $this->candidate->cv;
        $doc = $cv?->data ?? CandidateCv::emptyDoc();
        if ($onlyIfFilled && CandidateCv::isEmptyDoc($doc)) {
            return;
        } // سيرة فارغة — أجّل التجميد
        $this->cv_snapshot_enc = Crypt::encryptString(json_encode($doc, JSON_UNESCAPED_UNICODE));
        $this->cv_snapshot_version = $cv?->version ?? 0;
        $this->cv_snapshotted_at = now();
        $this->save();
    }

    // رمز تأكيد فريد يوضَع في رابط الرسالة النصية (غير قابل للتخمين)
    public static function generateConfirmToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::where('confirm_token', $token)->exists());

        return $token;
    }

    // توليد رمز مشارك جديد فريد عالميًا للقطاع (يقرأ من كل الدورات)
    // نحسب أكبر رقم عدديًّا لا معجميًّا — وإلا اعتُبر 'DA-999' > 'DA-1000' فتكرّر الرمز بعد 999
    // ── توليد رمز المشارك ──
    //
    // كان يقرأ أعلى رقم في ذاكرة PHP ثم يُضيف واحداً: طلبان متزامنان يقرآن
    // القيمة نفسها فيولّدان الرمز نفسه، ويسقط أحدهما على القيد الفريد بخطأ
    // 500 (٣٦٪ فشل تحت ثمانية كتّاب في قياس الحمل). وكان يجلب كل رموز
    // القطاع في كل إدراج — كلفة تنمو مع عدد المشاركين.
    //
    // الآن: الترقيم في القاعدة بعبارة ذرّية، والقاعدة تسلسل المتزامنين.
    //
    // يُستدعى خارج المعاملات في كل مواضعه، فالقفل على صفّ العدّاد لا يُحتجَز
    // إلا لحظة العبارة نفسها. لو استُدعي داخل معاملة طويلة لسلسل الإضافات
    // خلفه — فليبقَ الاستدعاء قبل DB::transaction لا داخلها.
    // ── الصيغة ──
    //   PV0007Aug26 = بادئة القطاع + تسلسل رباعي + شهر الإضافة + سنتاها
    //
    // بلا فواصل، ولا يوم فيه. والتسلسل **متّصل لكل قطاع** لا يُصفَّر شهرياً،
    // فهو وحده ما يجعل الرمز فريداً — والشهر والسنة يقولان متى دخل صاحبه
    // المنصّة لا متى صدر رمزه، ولذلك يُمرَّران من تاريخ إضافته لا من اليوم.
    //
    // الرباعيّ لا الثنائيّ: الاستيراد الضخم يعالج حتى عشرة آلاف صفّ في
    // الدفعة، فسعةُ تسعةٍ وتسعين تنفد على أوّل كشفٍ كبير — والنفاد هنا ليس
    // رسالة خطأ بل مشاركٌ لا يُضاف.
    public static function generateParticipantCode(Sector $sector, ?\DateTimeInterface $addedAt = null): string
    {
        // البادئة قابلة للتحديد من الإعدادات؛ الرجوع لأول حرفين يبقي التنصيبات
        // القديمة عاملة قبل تشغيل هجرة البادئة
        $prefix = strtoupper($sector->participant_prefix ?: substr($sector->code, 0, 2));
        // 'My' يعطي «Aug26» — الشهر مختصراً إنجليزياً والسنة برقمين
        $stamp = ($addedAt ? Carbon::parse($addedAt) : now())->format('My');

        // حلقة محدودة لتخطّي رمزٍ موجودٍ من قبل العدّاد (بيانات مستوردة أو
        // مبذورة يدوياً بأرقام تتجاوز ما بُذر به العدّاد). الحالة نادرة،
        // والحدّ يمنع حلقةً لا تنتهي إن كان الجدول ممتلئاً بشكل مرضي.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $code = sprintf('%s%04d%s', $prefix, self::nextCodeNumber($prefix), $stamp);
            if (! self::participantCodeTaken($code)) {
                return $code;
            }
        }

        throw new \RuntimeException("تعذّر توليد رمز مشارك فريد للبادئة {$prefix}");
    }

    // الرقم التالي للبادئة — ذرّي: عبارة واحدة تزيد وتُرجِع في آنٍ واحد
    private static function nextCodeNumber(string $prefix): int
    {
        $now = now();

        if (DB::connection()->getDriverName() === 'pgsql') {
            $row = DB::selectOne(
                'INSERT INTO participant_code_counters (prefix, last_number, created_at, updated_at)
                 VALUES (?, 1, ?, ?)
                 ON CONFLICT (prefix) DO UPDATE
                    SET last_number = participant_code_counters.last_number + 1,
                        updated_at  = EXCLUDED.updated_at
                 RETURNING last_number',
                [$prefix, $now, $now]
            );

            return (int) $row->last_number;
        }

        // مسار محمول لمحرّكات أخرى: قفل الصفّ داخل معاملة قصيرة.
        // أبطأ من العبارة الواحدة لكنه آمن — والإنتاج على Postgres.
        return (int) DB::transaction(function () use ($prefix, $now) {
            $row = DB::table('participant_code_counters')
                ->where('prefix', $prefix)->lockForUpdate()->first();

            if (! $row) {
                DB::table('participant_code_counters')->insert([
                    'prefix' => $prefix, 'last_number' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                return 1;
            }

            $next = (int) $row->last_number + 1;
            DB::table('participant_code_counters')->where('prefix', $prefix)
                ->update(['last_number' => $next, 'updated_at' => $now]);

            return $next;
        });
    }

    // الرمز يُكتب على الدورة وعلى المشارك — يُفحص الجدولان معاً
    private static function participantCodeTaken(string $code): bool
    {
        return self::where('participant_code', $code)->exists()
            || Candidate::where('participant_code', $code)->exists();
    }
}
