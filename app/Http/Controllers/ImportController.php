<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCandidateImport;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Security\Permissions;
use App\Services\CandidateImporter;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  بابا الاستيراد: المتزامن للصغير، والمُقطَّع للكبير.
//
//  المنطق واحدٌ في `CandidateImporter` — هنا القرار: كم صفّاً يُعالَج الآن،
//  وكم يُؤجَّل إلى الخلفية. والحدّ ليس ذوقاً: خمسمئة صفٍّ فيها خمسمئة سيرة
//  تُشفَّر وتُحفَظ في معاملات — وهي تقارب سقف انتظار المتصفّح. وعشرة آلاف
//  تتجاوزه بأضعاف، فتنقطع الصلة وقد أُنشئ نصف الملفّ ولا أحد يعرف كم.
// ════════════════════════════════════════════════════════════
class ImportController extends Controller
{
    // سقفُ المسار المتزامن — لا سقفُ الاستيراد نفسه. ما فوقه يمرّ بالدفعات.
    private const MAX_ROWS = 500;

    // سقفُ الدفعة الواحدة. عشرة آلاف صفٍّ بسيَرها تبلغ عشرات الميغابايت في
    // حمولة JSON واحدة، وهو ما يرفضه الخادم قبل أن يبلغ الشيفرة — فالواجهة
    // تُقطّع والملفّ يُجمَّع هنا صفوفاً تُضاف إلى الدفعة.
    private const MAX_BATCH_ROWS = 10000;

    private const MAX_CHUNK_ROWS = 1000;

    public function import(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::CANDIDATE_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية الاستيراد'], 403);
        }

        // سقفٌ صريح: `min:1` بلا `max` يقبل مصفوفةً بمئة ألف عنصر، فتُفتح دورة
        // معاملات بعددها في طلبٍ واحد — إنهاكٌ للخدمة بطلبٍ مصرَّحٍ به.
        $request->validate(
            ['rows' => 'required|array|min:1|max:'.self::MAX_ROWS],
            ['rows.max' => 'الحدّ الأقصى '.self::MAX_ROWS.' صفّاً في المرّة الواحدة'],
        );

        $seen = [];
        $result = app(CandidateImporter::class)
            ->import($request->rows, $request->user()->id, 0, $seen);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'IMPORT_CANDIDATES',
            'details' => ['imported' => count($result['success']), 'failed' => count($result['failures'])],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'اكتمل الاستيراد',
            'imported' => count($result['success']),
            'failed' => count($result['failures']),
            'successList' => $result['success'],
            // `errors` نصوصٌ مسطّحة تبقى للتوافق مع أي مستهلك قديم،
            // و`failures` هي المبنيّة: الواجهة تعرض منها الصفّ وأسبابه معاً.
            'errors' => $result['errors'],
            'failures' => $result['failures'],
        ]);
    }

    // ── POST /candidates/import/batch — يفتح رفعةً ويُضيف إليها صفوفاً ──
    // الواجهة تُقطّع الملفّ وتنادي هذا مرّاتٍ: أوّلها بلا `batchId` فيُفتح قيد،
    // وما بعدها به فتُضاف الصفوف. و`final` يُغلق الرفعة ويُطلق معالجتها.
    //
    // لماذا يُجمَّع قبل أن يُعالَج: الصفوف يجب أن تُفحص كوحدة — «مكرّر داخل
    // الملفّ» لا يُعرف إلا بعد قراءة الملفّ كلّه، ومعالجةُ الدفعة الأولى قبل
    // وصول الأخيرة تُنشئ صفّاً سيتبيّن أنه مكرّر.
    public function startBatch(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::CANDIDATE_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية الاستيراد'], 403);
        }

        $validated = $request->validate([
            'rows' => 'required|array|min:1|max:'.self::MAX_CHUNK_ROWS,
            'batchId' => 'nullable|integer',
            'filename' => 'nullable|string|max:255',
            'final' => 'boolean',
        ], [
            'rows.max' => 'الحدّ الأقصى '.self::MAX_CHUNK_ROWS.' صفّاً في الدفعة الواحدة',
        ]);

        $batch = $validated['batchId'] ?? null
            ? ImportBatch::where('id', $validated['batchId'])
                ->where('user_id', $request->user()->id)
                ->where('status', 'queued')
                ->first()
            : null;

        if (($validated['batchId'] ?? null) && ! $batch) {
            // رفعةٌ لغيره، أو بدأت معالجتها، أو لا وجود لها — لا يُفرَّق بينها
            return response()->json(['error' => 'الرفعة غير متاحة — ابدأ من جديد'], 404);
        }

        if (! $batch) {
            $batch = ImportBatch::create([
                'user_id' => $request->user()->id,
                'filename' => $validated['filename'] ?? null,
                'status' => 'queued',
                'payload' => [],
            ]);
        }

        $rows = array_merge($batch->payload ?? [], $validated['rows']);
        if (count($rows) > self::MAX_BATCH_ROWS) {
            $batch->delete();

            return response()->json([
                'error' => 'الحدّ الأقصى '.self::MAX_BATCH_ROWS.' صفّاً في الملفّ الواحد',
            ], 422);
        }

        $batch->payload = $rows;
        $batch->total_rows = count($rows);
        $batch->save();

        if ($validated['final'] ?? false) {
            ProcessCandidateImport::dispatch($batch->id);
        }

        return response()->json([
            'batchId' => $batch->id,
            'received' => count($rows),
            'queued' => (bool) ($validated['final'] ?? false),
        ], 201);
    }

    // ── GET /candidates/import/batch/{id} — حالة الرفعة ──
    // تُستفتى دورياً من شاشة التقدّم. الصفوف نفسها لا تُردّ — الحمولة عشرة
    // آلاف صفّ، وإعادتها مع كل استفتاء تُغرق الشبكة بما لا يُقرأ.
    public function batchStatus(Request $request, int $id)
    {
        $batch = ImportBatch::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $batch) {
            return response()->json(['error' => 'الرفعة غير موجودة'], 404);
        }

        return response()->json(['batch' => $batch->summary()]);
    }
}
