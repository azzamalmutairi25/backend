<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Sector;
use App\Models\AuditLog;
use App\Security\Permissions;
use Illuminate\Http\Request;

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
            if (Candidate::nationalIdExists($nationalId)) {
                $errors[] = "السطر {$lineNum}: الهوية مكرّرة ({$nationalId})";
                continue;
            }

            try {
                $sector = $sectors[$sectorCode];
                $tier = Candidate::classifyTier($rankLabel, $sector->is_military);
                $code = Candidate::generateParticipantCode($sector);

                $c = new Candidate();
                $c->participant_code = $code;
                $c->national_id = $nationalId;
                $c->full_name = $fullName;
                $c->mobile = $mobile ?: null;
                $c->email = $email ?: null;
                $c->sector_id = $sector->id;
                $c->rank_label = $rankLabel;
                $c->tier = $tier;
                $c->status = 'draft';
                $c->save();

                $success[] = ['line' => $lineNum, 'code' => $code, 'name' => $fullName];
            } catch (\Exception $e) {
                $errors[] = "السطر {$lineNum}: خطأ - " . $e->getMessage();
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
