<?php

namespace App\Http\Controllers;

use App\Security\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  حالة التهيئة الأولى للمنصّة.
//
//  منصّةٌ فارغة تبدو معطّلة لا جديدة: كل شاشة تقول «لا توجد بيانات» ولا تقول
//  ماذا يُفعل، والترتيب ليس اختيارياً — لا مشارك بلا قطاع، ولا تقييم بلا
//  كفاءات مربوطة بأنشطة. هذا المسار يُخبر اللوحة بما أُنجز وما بقي، فتُرشد
//  المستخدمَ بدل أن تتركه يستكشف.
//
//  يختفي الإرشاد من تلقائه حين تكتمل الخطوات الإلزامية.
// ════════════════════════════════════════════════════════════
class SetupStatusController extends Controller
{
    public function show(Request $request)
    {
        // الإرشاد لمن يستطيع تنفيذه. غيره يرى شاشاتٍ فارغة لأن العمل لم يبدأ
        // بعد، ولا فائدة من إرشاده إلى شاشات لا يملكها.
        //
        // 403 لا 200 بجسمٍ فارغ: كانت تُرجِع 200 للجميع، فصار مسارٌ جديد يبلغه
        // أدنى الأدوار بنجاح — وهو ما أمسكه RouteAuthorizationSweepTest. القاعدة
        // في هذا النظام أن كل مسار يُصرّح بحارسه، فلا يتسع السطح خطوةً خطوة.
        $user = $request->user();
        if (!$user->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        $sectors = DB::table('sectors')->count();
        $ranks = DB::table('ranks')->count();
        $competencies = DB::table('competencies')->count();
        $links = DB::table('activity_competency')->count();
        // حسابات الموظّفين عدا الحساب الحالي: منصّةٌ بمدير واحد لم تُسلَّم بعد
        $staff = DB::table('users')->where('id', '!=', $user->id)->count();
        $candidates = DB::table('candidates')->count();

        // الترتيب ليس تفضيلاً بل تبعية: كل خطوة تحتاج ما قبلها.
        // `required` تميّز ما يوقف التشغيل عمّا يُحسّنه فحسب.
        $steps = [
            [
                'key' => 'sectors',
                'title' => 'القطاعات',
                'hint' => 'رمز المشارك يُشتقّ من بادئة قطاعه — لا يُضاف مشارك بلا قطاع.',
                'route' => '/settings',
                'done' => $sectors > 0,
                'count' => $sectors,
                'required' => true,
            ],
            [
                // ليست إلزامية بحكم التصميم: جدول الرتب فارغٌ عمداً، وما دام فارغاً
                // يبقى تصنيف الفئة على المنطق القائم. إعلانها إلزاميةً يقول للمدير
                // إن المنصّة غير جاهزة وهي جاهزة.
                'key' => 'ranks',
                'title' => 'الرتب المُدارة',
                'hint' => 'اختيارية — تُصنَّف الفئة القيادية تلقائياً بدونها. أضِفها لتجاوز التصنيف الافتراضي لرتبةٍ بعينها.',
                'route' => '/settings',
                'done' => $ranks > 0,
                'count' => $ranks,
                'required' => false,
            ],
            [
                'key' => 'competencies',
                'title' => 'الكفاءات',
                'hint' => 'التقييم يُرصَد على كفاءات معرَّفة.',
                'route' => '/competency-framework',
                'done' => $competencies > 0,
                'count' => $competencies,
                'required' => true,
            ],
            [
                'key' => 'activity_links',
                'title' => 'ربط الكفاءات بالأنشطة',
                'hint' => 'شاشة التقييم تعرض ما رُبط هنا — كفاءة غير مربوطة لا تُرصَد.',
                'route' => '/competency-map',
                'done' => $links > 0,
                'count' => $links,
                'required' => true,
            ],
            [
                'key' => 'staff',
                'title' => 'حسابات الموظّفين',
                'hint' => 'لكل موظّف حساب بدوره، ولمقيّمي القطاعات قطاعُهم.',
                'route' => '/users',
                'done' => $staff > 0,
                'count' => $staff,
                'required' => true,
            ],
            [
                'key' => 'candidates',
                'title' => 'المشاركون',
                'hint' => 'بعد اكتمال ما سبق — إضافةً أو استيراداً أو ترشيحاً خارجياً.',
                'route' => '/candidates',
                'done' => $candidates > 0,
                'count' => $candidates,
                'required' => false,
            ],
        ];

        $required = array_filter($steps, fn ($s) => $s['required']);
        $doneRequired = array_filter($required, fn ($s) => $s['done']);

        return response()->json([
            'applicable' => true,
            // يظهر الإرشاد ما بقيت خطوة إلزامية — وليس بمجرّد خلوّ المشاركين
            'complete' => count($doneRequired) === count($required),
            'doneCount' => count($doneRequired),
            'totalCount' => count($required),
            'steps' => array_values($steps),
        ]);
    }
}
