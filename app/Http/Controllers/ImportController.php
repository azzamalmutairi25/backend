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

        $request->validate(['rows' => 'required|array|min:1']);

        $sectors = Sector::all()->keyBy('code');
        $success = [];
        $errors = [];
        $userId = $request->user()->id;

        foreach ($request->rows as $i => $row) {
            $lineNum = $i + 1;
            $nationalId = trim($row['nationalId'] ?? '');
            $fullName = trim($row['fullName'] ?? '');
            $mobile = trim($row['mobile'] ?? '');
            $email = trim($row['email'] ?? '');
            $sectorCode = strtoupper(trim($row['sectorCode'] ?? ''));
            $rankLabel = trim($row['rankLabel'] ?? '');

            if (strlen($nationalId) !== 10) {
                $errors[] = "السطر {$lineNum}: رقم الهوية غير صحيح ({$nationalId})";
                continue;
            }
            if (empty($fullName)) {
                $errors[] = "السطر {$lineNum}: الاسم مفقود";
                continue;
            }
            if (!isset($sectors[$sectorCode])) {
                $errors[] = "السطر {$lineNum}: كود القطاع غير موجود ({$sectorCode})";
                continue;
            }
            // لا نُعيد رقم الهوية في الرسالة (تفادي كشف الوجود عبر السجل/الرد)
            if (Candidate::nationalIdExists($nationalId)) {
                $errors[] = "السطر {$lineNum}: هذه الهوية مسجّلة مسبقاً";
                continue;
            }

            try {
                $sector = $sectors[$sectorCode];
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
                if (Candidate::nationalIdExists($nationalId)) {
                    $errors[] = "السطر {$lineNum}: هذه الهوية مسجّلة مسبقاً";
                } else {
                    $errors[] = "السطر {$lineNum}: تعذّر توليد رمز فريد (تعارض متزامن) — أعد المحاولة";
                }
            } catch (\Throwable $e) {
                // لا نُسرّب نص الاستثناء الخام للعميل
                \Illuminate\Support\Facades\Log::warning('candidate import row failed', ['line' => $lineNum, 'error' => $e->getMessage()]);
                $errors[] = "السطر {$lineNum}: تعذّر استيراد السطر";
            }
        }

        AuditLog::create([
            'user_id' => $userId,
            'action' => 'IMPORT_CANDIDATES',
            'details' => ['imported' => count($success), 'failed' => count($errors)],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'اكتمل الاستيراد',
            'imported' => count($success),
            'failed' => count($errors),
            'successList' => $success,
            'errors' => $errors,
        ]);
    }
}
