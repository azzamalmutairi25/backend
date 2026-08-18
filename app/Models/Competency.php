<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Competency extends Model
{
    protected $fillable = ['name_ar', 'type', 'group', 'domain', 'max_level', 'level_descriptors', 'weight', 'target_upper', 'target_middle', 'sort_order'];
    protected $casts = [
        'weight' => 'float', 'max_level' => 'integer',
        'target_upper' => 'integer', 'target_middle' => 'integer',
        'level_descriptors' => 'array',
    ];

    /**
     * أسماء مستويات السلّم — مرساةٌ لفظية لكل درجة.
     *
     * السلالم عندنا رباعية أو خماسية. الرباعي يقفز من «متمكّن» إلى «قدوة»،
     * والخماسي يُدخل «متقدّم» بينهما. ما عدا ذلك يُرقَّم ترقيماً صريحاً
     * بدل أن نخترع اسماً لا معنى له.
     */
    public const LEVEL_NAMES = [
        4 => ['مبتدئ', 'نامٍ', 'متمكّن', 'قدوة'],
        5 => ['مبتدئ', 'نامٍ', 'متمكّن', 'متقدّم', 'قدوة'],
    ];

    /**
     * مراسٍ عامّة تُستعمل حين لا تُكتب أوصاف الكفاءة بعد.
     * عامّةٌ عمداً: تصف *درجة* التمكّن لا *موضوعه*، فتصلح لأي كفاءة
     * حتى تُكتب أوصافها الخاصّة.
     */
    private const GENERIC = [
        'مبتدئ'   => 'يُظهر السلوك في المواقف البسيطة وبتوجيهٍ مباشر، ويحتاج متابعةً مستمرّة.',
        'نامٍ'    => 'يُظهر السلوك في المواقف المعتادة باستقلاليةٍ جزئية، ويتعثّر في المعقّد منها.',
        'متمكّن'  => 'يُظهر السلوك باتّساقٍ واستقلالية في أغلب المواقف، وينجزه بالجودة المتوقّعة.',
        'متقدّم'  => 'يُظهر السلوك في المواقف المعقّدة والغامضة، ويُحسّن طريقة العمل لا ينفّذها فقط.',
        'قدوة'    => 'مرجعٌ لغيره في هذا السلوك: يُطوّره في الآخرين ويُرسي به معياراً في وحدته.',
    ];

    /**
     * سلّم الكفاءة جاهزاً للعرض: رقم المستوى واسمه ووصفه السلوكي.
     * الوصف المكتوب يسبق العامّ، فمن كتب وصفاً رأى وصفه لا البديل.
     */
    public function levelScale(): array
    {
        $max = max(2, (int) $this->max_level);
        $names = self::LEVEL_NAMES[$max] ?? null;
        $written = $this->level_descriptors ?? [];

        return array_map(function (int $i) use ($names, $written) {
            $label = $names[$i - 1] ?? "المستوى {$i}";
            return [
                'level' => $i,
                'label' => $label,
                'descriptor' => $written[(string) $i] ?? $written[$i] ?? self::GENERIC[$label] ?? null,
            ];
        }, range(1, $max));
    }

    /**
     * يجيب معرّفات (IDs) الكفاءات التي تُقيَّم في نشاط معيّن.
     * يُستخدم للتحقق من اكتمال التقييم حسب النشاط.
     */
    public static function idsForActivity(string $activity): array
    {
        return \DB::table('activity_competency')
            ->where('activity', $activity)
            ->pluck('competency_id')
            ->toArray();
    }
}
