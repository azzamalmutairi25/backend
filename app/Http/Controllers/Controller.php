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

    // ── حلّ مرشّح ضمن نطاق المستخدم كاملاً: التصنيف + القطاع ──
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

    // ── حصر استعلام مرشحين على نطاق المستخدم ──
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

    // ── تضييق المقيّم على مرشّح مفرد ──
    // المقيّم/مستشار النقاش المحصور لا يرى إلا من قيّمهم هو. تُستعمل في مسارات
    // تحلّ مرشّحاً بالمعرّف (score-preview/competency-gap) كي تطابق حصر القائمة.
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
}
