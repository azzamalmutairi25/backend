<?php
namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Security\Permissions;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  حرّاس النطاق المشتركة.
//
//  كانت كل شاشة تكتب حارسها بنفسها، فحُصرت القوائم ونُسي أشقّاؤها:
//  قائمةٌ تُظهر قطاعاً واحداً بجانب show/export/approve تُظهر الجميع.
//  الحلّ أن يكون الحارس واحداً يُستدعى، لا نمطاً يُعاد كتابته.
// ════════════════════════════════════════════════════════════

abstract class Controller
{
    // التصنيفات التي يجوز للمستخدم رؤيتها
    protected function allowedClassifications(Request $request): array
    {
        return $request->user()->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED)
            ? ['normal', 'secret', 'top_secret']
            : ['normal'];
    }

    // ── حلّ مشارك ضمن نطاق المستخدم كاملاً: التصنيف + القطاع ──
    // يرجع null إن كان خارج النطاق — لا يفرّق بين «غير موجود» و«ليس لك»،
    // فلا يصير المعرّف أداةً لكشف من هو موجود.
    protected function resolveCandidateInScope(Request $request, int $id, array $with = []): ?Candidate
    {
        $user = $request->user();

        return Candidate::with($with)
            ->whereIn('classification', $this->allowedClassifications($request))
            ->when($user->isSectorBound(), fn ($q) => $q->where('sector_id', $user->sector_id))
            ->find($id);
    }

    // ── حصر استعلام مشاركين على نطاق المستخدم ──
    protected function scopeCandidateQuery(Request $request, $query): void
    {
        $user = $request->user();
        $query->whereIn('classification', $this->allowedClassifications($request));
        if ($user->isSectorBound()) {
            $query->where('sector_id', $user->sector_id);
        }
    }

    // ── حصر استعلام على علاقة candidate ──
    protected function scopeViaCandidate(Request $request, $query): void
    {
        $user = $request->user();
        $allowed = $this->allowedClassifications($request);

        $query->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));
        if ($user->isSectorBound()) {
            $query->whereHas('candidate', fn ($q) => $q->where('sector_id', $user->sector_id));
        }
    }

    // ── تضييق المقيّم على مشارك مفرد ──
    // المقيّم/مستشار النقاش المحصور لا يرى إلا من قيّمهم هو. تُستعمل في مسارات
    // تحلّ مشاركاً بالمعرّف (score-preview/competency-gap) كي تطابق حصر القائمة.
    protected function evaluatorNarrowedOut(Request $request, Candidate $candidate): bool
    {
        $user = $request->user();
        return $user->isSectorBound() && $user->hasRole('EVALUATOR', 'DISCUSSION_EVAL')
            && !\App\Models\Evaluation::where('evaluator_id', $user->id)
                ->where('candidate_id', $candidate->id)->exists();
    }

    // ── نطاق التقارير ──
    // القطاع حدّ أعلى لكل محصور. وداخله يضيق المقيّم أكثر: لا يرى إلا تقارير
    // من قيّمهم هو — فتقريرٌ لم يشارك في تقييمه ليس شأنه ولو كان في قطاعه.
    // مساعد التقييم يبقى على حدّ القطاع: يكتب تقارير قطاعه ولا يقيّم.
    //
    // هنا لا في ReportController لأن محادثة التقرير تحتاجه أيضاً — ومحادثةٌ
    // أوسع من تقريرها تُفشي مضمونه: سبب الإرجاع ونقاش المقيّمين.
    protected function scopeReports(Request $request, $query): void
    {
        $this->scopeViaCandidate($request, $query);

        $user = $request->user();
        if ($user->isSectorBound() && $user->hasRole('EVALUATOR', 'DISCUSSION_EVAL')) {
            $query->whereIn(
                'candidate_id',
                \App\Models\Evaluation::where('evaluator_id', $user->id)->select('candidate_id')
            );
        }
    }

    // ════════════════════════════════════════════════════════
    //  ترقيم القوائم وفرزها — واحدة تُستدعى لا نمطٌ يُعاد كتابته
    // ════════════════════════════════════════════════════════
    //
    // أربع شاشات تحتاجها، ولكلٍّ منها فخّاها نفسه: فرزٌ بلا فاصلٍ ثابت يُظهر
    // الصفّ في صفحتين، وعمود فرزٍ يأتي من المستخدم يدخل جملة SQL، وعميلٌ قديم
    // ينكسر بصمت لو صار الترقيم افتراضاً. تُحلّ مرّةً هنا.

    protected const LIST_HARD_CAP = 5000;
    protected const LIST_DEFAULT_PER_PAGE = 50;
    protected const LIST_MAX_PER_PAGE = 200;

    /** قواعد التحقّق — تُشتقّ من خريطة الفرز فلا يفترق المسموح عن المترجَم */
    protected function listPagingRules(array $sortable): array
    {
        return [
            'page' => 'nullable|integer|min:1',
            'perPage' => 'nullable|integer|min:1|max:' . self::LIST_MAX_PER_PAGE,
            'sort' => 'nullable|string|in:' . implode(',', array_keys($sortable)),
            'dir' => 'nullable|string|in:asc,desc',
        ];
    }

    /**
     * يُطبّق الفرز والترقيم على الاستعلام ويرجع `meta`.
     *
     * @param array  $sortable   مفتاح الواجهة ⇐ عمودٌ نصّاً، أو دالّة($query,$dir) للفرز الخاص
     * @param string $default    مفتاح الفرز الافتراضي
     * @param string $tie        عمود الفصل الثابت — يُذيَّل بكل فرزٍ سواه
     * @param string $defaultDir اتجاه الفرز حين لا يطلبه العميل. لا يُترك «تصاعدياً»
     *                           دائماً: قائمةٌ كانت تعرض الأحدث أولاً تنقلب إلى
     *                           الأقدم أولاً بلا أن يطلب أحد — وذلك تغيير سلوك
     */
    protected function applyListPaging(
        Request $request,
        $query,
        array $sortable,
        string $default,
        string $tie,
        string $defaultDir = 'asc'
    ): array {
        $sort = $request->input('sort') ?: $default;
        $dir = $request->input('dir') ?: $defaultDir;
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        $column = $sortable[$sort] ?? $sortable[$default];
        if (is_callable($column)) {
            $column($query, $dir);
        } else {
            $query->orderBy($column, $dir);
        }

        // صفوفٌ متساوية في عمود الفرز ترتيبها غير محدَّد في postgres، فتتنقّل
        // بين الصفحات: يُرى صفٌّ مرّتين ويغيب آخر. الفاصل يجعل الترتيب تامّاً.
        //
        // ويتبع اتجاه الفرز لا يثبت على «تصاعدي»: قائمةٌ بالأحدث أولاً فيها
        // صفّان في الثانية نفسها يجب أن يتقدّم أحدثُهما، وثباتُ الفاصل
        // تصاعدياً يُقدّم الأقدم داخل كل تعادل — ترتيبٌ ثابت لكنّه مقلوب.
        if (($sortable[$sort] ?? null) !== $tie) {
            $query->orderBy($tie, $dir);
        }

        $total = (clone $query)->toBase()->getCountForPagination();

        // الترقيم بطلبٍ صريح: غيابه يُبقي القائمة كاملةً كما كانت، فلا ينكسر
        // عميلٌ قائم بصمت حين يرى خمسين صفّاً مكان ألف ويظنّها كلّ ما عنده.
        $wantsPage = $request->filled('page') || $request->filled('perPage');
        $truncated = false;

        if ($wantsPage) {
            $perPage = (int) ($request->input('perPage') ?: self::LIST_DEFAULT_PER_PAGE);
            $lastPage = max(1, (int) ceil($total / $perPage));
            // صفحةٌ تجاوزت الآخر تُشدّ إلى الأخيرة بدل أن تعود فارغةً فيُقرأ
            // ذلك «لا نتيجة» وهي موجودة
            $page = min(max(1, (int) ($request->input('page') ?: 1)), $lastPage);
            $query->forPage($page, $perPage);
        } else {
            $perPage = null;
            $page = 1;
            $lastPage = 1;
            $truncated = $total > self::LIST_HARD_CAP;
            $query->limit(self::LIST_HARD_CAP);
        }

        return [
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'lastPage' => $lastPage,
            'sort' => $sort,
            'dir' => $dir,
            'truncated' => $truncated,
        ];
    }
}
