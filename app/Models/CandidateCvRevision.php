<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

// إصدارٌ من سيرة مشارك — الوثيقة كاملةً بعد التغيير، ومعها ما تغيّر ومن غيّره.
//
// `candidate_cvs.version` يقول «تغيّرت» ولا يقول ماذا ولا من. وهذه السيرة
// تُصحَّح عند مكتب الاستقبال قبيل التقييم بدقائق، فحقلٌ صُحّح خطأً يصل
// المستشار ولا أثر يدلّ عليه. الإصدار يجعل الفرق مقروءاً لا مستنتَجاً.
class CandidateCvRevision extends Model
{
    // الطابع الوحيد `created_at` — الإصدار لا يُحدَّث بعد كتابته
    public const UPDATED_AT = null;

    protected $fillable = [
        'candidate_id', 'version', 'data', 'changed_fields', 'source', 'note', 'created_by',
    ];

    protected $hidden = ['cv_data_enc'];

    protected $casts = [
        'changed_fields' => 'array',
        'created_at' => 'datetime',
    ];

    // نفس نمط CandidateCv: الوثيقة تُقرأ وتُكتب منطقيّاً وتُخزَّن مشفّرة
    protected function data(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cv_data_enc
                ? json_decode(Crypt::decryptString($this->cv_data_enc), true)
                : null,
            set: fn ($value) => ['cv_data_enc' => $value === null
                ? null
                : Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE))],
        );
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * أسماء الحقول التي اختلفت بين وثيقتين.
     *
     * المقارنة على المستوى الأول وحده: «المؤهلات» تُقال مرّةً ولا تُفصَّل إلى
     * «المؤهل الثاني ← الجامعة». سطرُ الفرق يُقرأ في سير الأحداث، وتفصيلُه
     * إلى مسارات داخل المصفوفات يجعله سطراً لا يُقرأ.
     */
    public static function diffKeys(array $before, array $after): array
    {
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        $changed = [];
        foreach ($keys as $k) {
            if (($before[$k] ?? null) !== ($after[$k] ?? null)) {
                $changed[] = $k;
            }
        }
        sort($changed);

        return $changed;
    }

    /**
     * قيدُ إصدارٍ جديد. يعود بـ`null` إن لم يتغيّر شيء.
     *
     * «لم يتغيّر شيء» ليس خطأً يُرفَض: الموظّف يفتح النموذج ويحفظ بلا تعديل،
     * وقيدُ إصدارٍ فارغ يُغرق السجلّ بما لا يُقرأ.
     */
    public static function record(
        Candidate $candidate,
        array $before,
        array $after,
        int $version,
        string $source,
        ?int $userId = null,
        ?string $note = null,
    ): ?self {
        $changed = self::diffKeys($before, $after);
        if (! $changed) {
            return null;
        }

        return self::create([
            'candidate_id' => $candidate->id,
            'version' => $version,
            'data' => $after,
            'changed_fields' => $changed,
            'source' => $source,
            'note' => $note,
            'created_by' => $userId,
        ]);
    }

    // تسميات عربية لحقول الوثيقة — تُقرأ في سير الأحداث وفي شاشة الإصدارات
    public const FIELD_LABEL = [
        'birthDate' => 'تاريخ الميلاد',
        'appointmentDate' => 'تاريخ التعيين',
        'rankLabel' => 'الرتبة',
        'rankTitle' => 'لقب الرتبة',
        'rankPromotedAt' => 'تاريخ الترقية',
        'department' => 'الإدارة',
        'generalDepartment' => 'الإدارة العامة',
        'region' => 'المنطقة',
        'workCity' => 'مدينة العمل',
        'currentPosition' => 'المنصب الحالي',
        'currentPositionYears' => 'سنوات المنصب الحالي',
        'totalYearsExperience' => 'إجمالي سنوات الخبرة',
        'briefBio' => 'نبذة',
        'qualifications' => 'المؤهلات',
        'experiences' => 'الخبرات',
        'certifications' => 'الشهادات',
    ];

    public static function fieldLabel(string $key): string
    {
        return self::FIELD_LABEL[$key] ?? $key;
    }

    /** أسماء ما تغيّر عربيّةً — «المنصب الحالي، الخبرات» */
    public function changedLabels(): string
    {
        return collect($this->changed_fields ?? [])
            ->map(fn ($k) => self::fieldLabel($k))->implode('، ');
    }
}
