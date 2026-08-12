<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Rank;
use App\Security\Permissions;
use Illuminate\Http\Request;

// إدارة قائمة الرتب/المراتب — مرجعٌ لإنشاء المرشّح وتصنيف الفئة القيادية.
class RankController extends Controller
{
    // GET /ranks — القائمة (مرجع للجميع؛ مدير الإعدادات يرى غير الفعّالة أيضاً)
    public function index(Request $request)
    {
        $canManage = $request->user()->hasPermission(Permissions::SETTINGS_MANAGE);
        $q = Rank::orderBy('category')->orderBy('sort_order')->orderBy('id');
        if (!$canManage) {
            $q->where('is_active', true);
        }
        return response()->json([
            'ranks' => $q->get()->map(fn ($r) => [
                'id' => $r->id, 'label' => $r->label, 'category' => $r->category,
                'tier' => $r->tier, 'sortOrder' => $r->sort_order, 'isActive' => $r->is_active,
            ]),
            'canManage' => $canManage,
        ]);
    }

    // POST /ranks — إضافة رتبة
    public function store(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }
        $validated = $request->validate([
            'label' => 'required|string|max:100',
            'category' => 'required|in:military,civilian',
            'tier' => 'required|in:upper,middle',
            'sortOrder' => 'nullable|integer|min:0',
        ]);
        if (Rank::where('category', $validated['category'])->where('label', $validated['label'])->exists()) {
            return response()->json(['errors' => ['label' => ['الرتبة مسجّلة في هذه الفئة']]], 422);
        }
        $rank = Rank::create([
            'label' => $validated['label'], 'category' => $validated['category'],
            'tier' => $validated['tier'], 'sort_order' => $validated['sortOrder'] ?? 0, 'is_active' => true,
        ]);
        $this->audit($request, 'CREATE_RANK', $rank);
        return response()->json(['message' => 'تمت إضافة الرتبة', 'rankId' => $rank->id], 201);
    }

    // PUT /ranks/{id} — تعديل رتبة (التسمية/الفئة/الطبقة/الترتيب/التفعيل)
    public function update(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }
        $rank = Rank::find($id);
        if (!$rank) {
            return response()->json(['error' => 'الرتبة غير موجودة'], 404);
        }
        $validated = $request->validate([
            'label' => 'required|string|max:100',
            'category' => 'required|in:military,civilian',
            'tier' => 'required|in:upper,middle',
            'sortOrder' => 'nullable|integer|min:0',
            'isActive' => 'boolean',
        ]);
        $clash = Rank::where('category', $validated['category'])->where('label', $validated['label'])
            ->where('id', '!=', $id)->exists();
        if ($clash) {
            return response()->json(['errors' => ['label' => ['الرتبة مسجّلة في هذه الفئة']]], 422);
        }
        $rank->update([
            'label' => $validated['label'], 'category' => $validated['category'], 'tier' => $validated['tier'],
            'sort_order' => $validated['sortOrder'] ?? $rank->sort_order,
            'is_active' => $request->boolean('isActive', $rank->is_active),
        ]);
        $this->audit($request, 'UPDATE_RANK', $rank);
        return response()->json(['message' => 'تم تحديث الرتبة']);
    }

    // DELETE /ranks/{id} — حذف (المرشحون يحفظون النصّ لا المعرّف، فلا يُيتّم أحد)
    public function destroy(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }
        $rank = Rank::find($id);
        if (!$rank) {
            return response()->json(['error' => 'الرتبة غير موجودة'], 404);
        }
        $rank->delete();
        $this->audit($request, 'DELETE_RANK', $rank);
        return response()->json(['message' => 'تم حذف الرتبة']);
    }

    private function audit(Request $request, string $action, Rank $rank): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => $action,
            'entity_type' => 'rank', 'entity_id' => (string) $rank->id,
            'details' => ['label' => $rank->label, 'category' => $rank->category, 'tier' => $rank->tier],
            'ip_address' => $request->ip(), 'created_at' => now(),
        ]);
    }
}
