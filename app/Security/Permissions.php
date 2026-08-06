<?php

namespace App\Security;

// ════════════════════════════════════════════════════════════
//  نظام الصلاحيات الكامل (منقول من نظام .NET)
//  يوثّق كل الأدوار والصلاحيات ويتحقق منها
// ════════════════════════════════════════════════════════════

class Permissions
{
    // ── الصلاحيات المتاحة ──
    const CANDIDATE_VIEW = 'candidate.view';
    const CANDIDATE_CREATE = 'candidate.create';
    const CANDIDATE_EDIT = 'candidate.edit';
    const CANDIDATE_APPROVE = 'candidate.approve';
    const CANDIDATE_VIEW_NAMES = 'candidate.view_names';   // رؤية الأسماء (حساس)
    const CANDIDATE_VIEW_CLASSIFIED = 'candidate.view_classified';   // رؤية المرشحين السرّيين
    const CANDIDATE_JOURNEY = 'candidate.journey';   // عرض رحلة المرشح (الخط الزمني)
    const CANDIDATE_CV_VIEW = 'candidate.cv_view';   // قراءة السيرة الذاتية بمعرّف المرشح (مسار الإدارة)
    // رفع طلب تحديث بيانات مرشّح مسجّل — للمستخدم الخارجي الذي لا يملك التعديل.
    // الطلب اقتراح لا كتابة: لا يمسّ السجلّ حتى يعتمده صاحب صلاحية.
    const CANDIDATE_UPDATE_REQUEST = 'candidate.update_request';
    // البتّ في طلبات التحديث (اعتماد/رفض) — سلطة تعديل بيانات المرشّح بالنيابة
    const CANDIDATE_UPDATE_APPROVE = 'candidate.update_approve';
    // إسناد مرشّح لمقيّم من قطاع آخر — الأصل أن كل مقيّم لقطاعه
    const CROSS_SECTOR_ASSIGN = 'candidate.cross_sector';

    const SCHEDULE_VIEW = 'schedule.view';
    const SCHEDULE_MANAGE = 'schedule.manage';
    // التوزيع الأسبوعي: اقتراح واعتماد — لمسؤول الجدولة (إدارة المرشحين)
    const DISTRIBUTION_MANAGE = 'schedule.distribute';

    // إسناد مشاركي اليوم لمجموعتَي الكشف (أ/ب) — بها يتحدّد مَن يبدأ بالمقابلة
    // ومَن يبدأ بجلسة النقاش، فالمجموعتان تتبادلان الفترتين.
    // طباعة الكشف نفسه تكفيها SCHEDULE_VIEW — الإسناد وحده هو القرار.
    const ROSTER_MANAGE = 'roster.manage';

    const ATTENDANCE_VIEW = 'attendance.view';
    // تسجيل حضور الجلسات المُسنَدة للمستخدم (مقيّماً أو مساعداً) — «الذي يستقبله يسجّله»
    const ATTENDANCE_RECORD = 'attendance.record';
    // تسجيل أي جلسة بلا إسناد — للاستقبال ومشرف القياس: يستقبلان من لا جلسة لهما فيه
    const ATTENDANCE_RECORD_ANY = 'attendance.record_any';

    // ── استقبال الموظفين ──
    // صلاحية مستقلّة لكل مرحلة من مراحل المسار، لا صلاحية واحدة للشاشة كلها:
    // مَن يسجّل الوصول ليس بالضرورة مَن يوزّع، ومَن يوزّع ليس مَن يقبل، ومَن
    // يقبل ليس مَن يعتمد. جمعُها في واحدة يجعل كلَّ من فتح الشاشة يملك المسار كاملاً.
    const RECEPTION_VIEW = 'reception.view';        // فتح شاشة الاستقبال وقراءة كشف اليوم
    const RECEPTION_RECORD = 'reception.record';    // تسجيل الوصول وتعديل وقته وأخذ التوقيع والإقرار
    const RECEPTION_ASSIGN = 'reception.assign';    // توزيع المرشّح على مقابلة/حلقة نقاش/أدوات قياس
    const RECEPTION_DECIDE = 'reception.decide';    // قرار المقيّم: استلام المرشّح أو ردّه
    const RECEPTION_APPROVE = 'reception.approve';  // اعتماد العمليات وترحيل الجلسات إلى الجدول

    const EVALUATION_VIEW = 'evaluation.view';
    const EVALUATION_INPUT = 'evaluation.input';
    const EVALUATION_APPROVE = 'evaluation.approve';
    const EVALUATION_ASSIST = 'evaluation.assist';

    const MEASUREMENT_VIEW = 'measurement.view';
    const MEASUREMENT_UPLOAD = 'measurement.upload';

    const REPORT_VIEW = 'report.view';
    const REPORT_CREATE = 'report.create';
    const REPORT_EDIT_ANY = 'report.edit_any';   // تعديل تقرير أنشأه غيره (مدير التقييم)
    // سلسلة الاعتماد: صلاحية لكل مرحلة — المرحلة تحدَّد من حالة التقرير لا من الدور.
    // ترتيب المراحل وتفعيلها بيانات في workflow_stages، لا ثوابت هنا.
    const REPORT_APPROVE_EVALUATOR = 'report.approve_evaluator';   // اعتماد المقيّم
    const REPORT_APPROVE_MANAGER = 'report.approve_manager';       // اعتماد مدير إدارة التقييم
    const REPORT_APPROVE = 'report.approve';                       // اعتماد إدارة تطوير الكفاءات
    const REPORT_APPROVE_CENTER = 'report.approve_center';         // اعتماد مدير المركز
    // الإرجاع (لمسودة أو للمرحلة السابقة) والإلغاء — مدير المركز وحده.
    // كانا موزّعين على كل مرحلة، فكان كلُّ معتمِدٍ يردّ التقرير خطوات للوراء.
    const REPORT_RETURN = 'report.return';
    const REPORT_CANCEL = 'report.cancel';
    const REPORT_EXPORT = 'report.export';
    // اسم المرشّح في المستند المطبوع — لا يراه غير حامل هذه الصلاحية، ولو ملك رؤية الأسماء
    const REPORT_VIEW_NAMES = 'report.view_names';
    const REPORT_EXEC_SUMMARY = 'report.exec_summary';   // الملخّص التنفيذي النهائي (مدير المركز، قابل للتفويض)

    const COMPETENCY_VIEW = 'competency.view';
    const COMPETENCY_MANAGE = 'competency.manage';

    const SEND_INVITATION = 'communication.invite';

    const USER_MANAGE = 'user.manage';
    const AUDIT_VIEW = 'audit.view';
    const SETTINGS_MANAGE = 'settings.manage';
    const ANALYTICS_VIEW = 'analytics.view';

    // ── صلاحية مستقلّة لكل شاشة ──
    // كانت خمس صلاحيات تحرس إحدى عشرة شاشة: من ملك «التحليلات» ملك اللوحة
    // التنفيذية والتقرير اليومي معه، ومن ملك «التقارير» ملك خطط التطوير،
    // ومن ملك «الإعدادات» ملك سير العمل. فلم يكن يمكن منح شاشةٍ دون أختها.
    const ANALYTICS_EXECUTIVE = 'analytics.executive';      // اللوحة التنفيذية
    const ANALYTICS_DAILY_REPORT = 'analytics.daily_report'; // التقرير اليومي
    const DEVELOPMENT_PLAN_VIEW = 'development_plan.view';   // خطط التطوير
    const CHAT_VIEW = 'chat.view';                           // المحادثات
    const WORKFLOW_MANAGE = 'workflow.manage';               // مراحل الاعتماد

    // ════════════════════════════════════════════════════════
    //  مصفوفة الأدوار والصلاحيات
    // ════════════════════════════════════════════════════════
    public static function matrix(): array
    {
        return [
            // مدير النظام — كل الصلاحيات
            'ADMIN' => ['*'],

            // مدير المركز — يطّلع على كل شيء ويتحكّم في التشغيل، ولا يتحكّم في
            // المستخدمين ولا الإعدادات ولا مراحل الاعتماد: تلك سلطات نظام
            // تُدار من حساب مدير النظام وحده، وفصلُها يبقي من يشرف غير من يضبط.
            //
            // بلا CANDIDATE_VIEW_NAMES عمداً: أسماء المرشحين محجوبة عنه في
            // الشاشات كغيره — حياد التقييم مبنيّ على ذلك — والاستثناء للمستند
            // المطبوع وحده (REPORT_VIEW_NAMES) لأنه وثيقة رسمية تُوقَّع.
            // ولمن أراد غير ذلك: تُمنَح من شاشة «الأدوار والصلاحيات».
            'CENTER_MANAGER' => [
                self::CANDIDATE_VIEW, self::CANDIDATE_JOURNEY, self::CANDIDATE_CV_VIEW, self::CANDIDATE_VIEW_CLASSIFIED,
                self::CANDIDATE_EDIT, self::CANDIDATE_APPROVE,
                self::SCHEDULE_VIEW, self::SCHEDULE_MANAGE, self::DISTRIBUTION_MANAGE, self::ROSTER_MANAGE,
                self::RECEPTION_VIEW, self::RECEPTION_ASSIGN, self::RECEPTION_APPROVE,
                self::ATTENDANCE_VIEW, self::ATTENDANCE_RECORD_ANY,
                self::EVALUATION_VIEW, self::EVALUATION_APPROVE,
                self::MEASUREMENT_VIEW, self::MEASUREMENT_UPLOAD,
                self::REPORT_VIEW, self::REPORT_CREATE, self::REPORT_EDIT_ANY,
                self::REPORT_APPROVE_CENTER, self::REPORT_RETURN, self::REPORT_CANCEL,
                self::REPORT_EXEC_SUMMARY, self::REPORT_VIEW_NAMES, self::REPORT_EXPORT,
                self::DEVELOPMENT_PLAN_VIEW,
                self::COMPETENCY_VIEW, self::COMPETENCY_MANAGE,
                self::SEND_INVITATION, self::CHAT_VIEW,
                self::AUDIT_VIEW,
                self::ANALYTICS_VIEW, self::ANALYTICS_EXECUTIVE, self::ANALYTICS_DAILY_REPORT,
            ],

            // مسؤول الجدولة — يملك إدارة المرشحين، فله وحده تجاوز حدّ القطاع (بتحذير وتدقيق)
            'SCHEDULER' => [
                self::CANDIDATE_VIEW, self::CANDIDATE_CREATE, self::CANDIDATE_EDIT,
                self::CANDIDATE_APPROVE, self::CANDIDATE_VIEW_NAMES, self::CANDIDATE_CV_VIEW, self::CROSS_SECTOR_ASSIGN,
                // البتّ في طلبات التحديث الواردة من المستخدمين الخارجيين — هو مالك
                // بيانات المرشحين (CANDIDATE_EDIT)، فالاعتماد امتداد لسلطته لا سلطة جديدة
                self::CANDIDATE_UPDATE_APPROVE,
                self::SCHEDULE_VIEW, self::SCHEDULE_MANAGE, self::DISTRIBUTION_MANAGE, self::ATTENDANCE_VIEW,
                self::ROSTER_MANAGE,
                self::SEND_INVITATION, self::CHAT_VIEW,
                // العمليات: يستقبل المردود فيعيد إسناده، ويعتمد البيانات ويُرحّلها
                // للجدول. لا RECEPTION_RECORD — الوصول والتوقيع عند الاستقبال.
                self::RECEPTION_VIEW, self::RECEPTION_ASSIGN, self::RECEPTION_APPROVE,
            ],

            // مسؤول استقبال الموظفين — يستقبل كل داخل فيسجّل أي جلسة، ويأخذ
            // توقيعه وإقراره، ويوزّعه على المقابلة أو حلقة النقاش أو أدوات القياس.
            // لا RECEPTION_APPROVE: من يوزّع لا يعتمد توزيعه بنفسه.
            // بلا CANDIDATE_CV_VIEW: تلك تفتح سيرة **أي** مرشّح في القاعدة بمعرّفه.
            // سيرة من يستقبله اليوم تُقرأ من مسار الاستقبال بـRECEPTION_RECORD،
            // وهو محصور بزيارةٍ قائمة في يومها — فرقٌ بين «يقرأ سيرة من أمامه»
            // و«يتصفّح سِيَر المرشحين».
            'RECEPTIONIST' => [
                self::CANDIDATE_VIEW, self::CANDIDATE_VIEW_NAMES,
                self::ATTENDANCE_VIEW, self::ATTENDANCE_RECORD, self::ATTENDANCE_RECORD_ANY,
                self::SEND_INVITATION, self::CHAT_VIEW,
                self::RECEPTION_VIEW, self::RECEPTION_RECORD, self::RECEPTION_ASSIGN,
            ],

            // مسؤول العمليات — طرف المسار الآخر: يستقبل المردود من المقيّمين
            // فيعيد إسناده، ويعتمد بيانات المرشّح فتُرحَّل جلساته إلى الجدول.
            // قراره إجرائي (مَن يقابل مَن) لا محتوائي، فيعمل بالرمز: بلا
            // CANDIDATE_VIEW_NAMES ولا CANDIDATE_CV_VIEW. قائمة قارئي الأسماء
            // مغلقة تُراجَع بالعين، ولا يُضاف إليها دورٌ لا يحتاجها.
            'OPERATIONS' => [
                self::CANDIDATE_VIEW,
                self::SCHEDULE_VIEW, self::ATTENDANCE_VIEW,
                self::RECEPTION_VIEW, self::RECEPTION_ASSIGN, self::RECEPTION_APPROVE,
            ],

            // مدير إدارة التقييم — يكتب التقرير، ويعتمد المرحلة الثانية
            'ASSESS_MANAGER' => [
                self::CANDIDATE_VIEW, self::CANDIDATE_VIEW_NAMES, self::CANDIDATE_VIEW_CLASSIFIED, self::CANDIDATE_JOURNEY, self::CANDIDATE_CV_VIEW, self::SCHEDULE_VIEW,
                self::ATTENDANCE_VIEW, self::EVALUATION_VIEW, self::EVALUATION_APPROVE,
                self::MEASUREMENT_VIEW, self::REPORT_VIEW, self::REPORT_CREATE,
                self::REPORT_EDIT_ANY, self::REPORT_APPROVE_MANAGER,
                self::REPORT_EXPORT, self::COMPETENCY_VIEW,
                self::ANALYTICS_VIEW, self::ANALYTICS_EXECUTIVE, self::ANALYTICS_DAILY_REPORT,
                self::DEVELOPMENT_PLAN_VIEW, self::CHAT_VIEW,
            ],

            // مستشار المقابلة — يعتمد المرحلة الأولى، ويسجّل حضور جلساته
            // لا يكتب التقرير: من يكتب لا يعتمد
            'EVALUATOR' => [
                self::CANDIDATE_VIEW, self::EVALUATION_VIEW, self::EVALUATION_INPUT,
                self::ATTENDANCE_VIEW, self::ATTENDANCE_RECORD,
                self::REPORT_VIEW, self::REPORT_APPROVE_EVALUATOR,
                self::DEVELOPMENT_PLAN_VIEW, self::CHAT_VIEW,
                // يرى المُسنَد إليه وحده ويقرّر فيه — بلا RECEPTION_ASSIGN
                self::RECEPTION_VIEW, self::RECEPTION_DECIDE,
            ],

            // مستشار حلقة النقاش — يسجّل حضور حلقاته
            'DISCUSSION_EVAL' => [
                self::CANDIDATE_VIEW, self::EVALUATION_VIEW, self::EVALUATION_INPUT,
                self::ATTENDANCE_VIEW, self::ATTENDANCE_RECORD,
                self::CHAT_VIEW,
                self::RECEPTION_VIEW, self::RECEPTION_DECIDE,
            ],

            // مساعد التقييم — يرصد، ويكتب التقرير، ويسجّل حضور جلساته
            // بلا CANDIDATE_VIEW_NAMES: المقيّم ومساعده يريان الرمز لا الاسم
            'ASSISTANT' => [
                self::CANDIDATE_VIEW, self::EVALUATION_VIEW, self::EVALUATION_ASSIST,
                self::ATTENDANCE_VIEW, self::ATTENDANCE_RECORD,
                self::MEASUREMENT_VIEW, self::REPORT_VIEW, self::REPORT_CREATE,
                self::DEVELOPMENT_PLAN_VIEW, self::CHAT_VIEW,
            ],

            // إدارة تطوير الكفاءات — الاعتماد النهائي
            'DEV_MANAGER' => [
                self::CANDIDATE_VIEW, self::CANDIDATE_VIEW_CLASSIFIED, self::CANDIDATE_JOURNEY, self::CANDIDATE_CV_VIEW, self::EVALUATION_VIEW, self::MEASUREMENT_VIEW,
                self::REPORT_VIEW, self::REPORT_APPROVE,
                self::REPORT_EXPORT, self::COMPETENCY_VIEW, self::COMPETENCY_MANAGE,
                self::ANALYTICS_VIEW, self::ANALYTICS_EXECUTIVE, self::ANALYTICS_DAILY_REPORT,
                self::DEVELOPMENT_PLAN_VIEW, self::CHAT_VIEW,
            ],

            // مشرف أدوات القياس — يشرف على جلسات القياس كلها لا جلسة بعينها
            'MEASURE_SUPER' => [
                self::CANDIDATE_VIEW, self::ATTENDANCE_VIEW, self::ATTENDANCE_RECORD, self::ATTENDANCE_RECORD_ANY,
                self::MEASUREMENT_VIEW, self::MEASUREMENT_UPLOAD, self::CHAT_VIEW,
                self::RECEPTION_VIEW, self::RECEPTION_DECIDE,
            ],

            // المستخدم الخارجي — يُدخل المرشّح ونموذج سيرته، ولا يقرأ القاعدة ولا
            // يكتب فوق سجلٍّ قائم: المسجَّل مسبقاً يمرّ عبر «طلب تحديث» يُعتمد.
            'EXTERNAL_ADD' => [
                self::CANDIDATE_CREATE,
                self::CANDIDATE_UPDATE_REQUEST,
            ],
        ];
    }

    // ── صلاحيات لا تُفوَّض عبر استثناء فردي (تُدار بالدور فقط) ──
    // منحها لمستخدم بعينه عبر UserPermissionOverride يكسر حدود المصفوفة:
    // إدارة المستخدمين/الإعدادات/سجل التدقيق سلطات نظام تُسنَد بالدور لا بالاستثناء.
    public const NON_DELEGABLE = [
        self::USER_MANAGE,
        self::SETTINGS_MANAGE,
        self::AUDIT_VIEW,
    ];

    // ── كل الصلاحيات المعرَّفة ──
    // تُقرأ من ثوابت الصنف بالانعكاس، فلا تُنسى واحدة عند إضافتها.
    // تُستعمل لفَرْد '*' قبل تطبيق سحبٍ على مدير النظام، ولبناء شاشة الصلاحيات.
    public static function all(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $consts = (new \ReflectionClass(self::class))->getConstants();

        // نمطٌ صارم لا مجرّد «فيه نقطة». الانعكاس يقرأ **كل** ثوابت الصنف بما
        // فيها ما ليس صلاحية؛ ومفتاح ذاكرة اسمه 'kafaat.rolePermissions' كان
        // يمرّ بالفحص القديم فيصير صلاحيةً وهمية تظهر مربّعَ اختيارٍ في شاشة
        // صلاحيات الأدوار. الشكل الحقيقي: مجموعة.فعل بحروف صغيرة وشرطة سفلية.
        return $cache = array_values(array_filter(
            $consts,
            fn ($v) => is_string($v) && preg_match('/^[a-z][a-z_]*\.[a-z][a-z_]*$/', $v) === 1
        ));
    }

    // ── وصف عربي لكل صلاحية ──
    // شاشة صلاحيات الأدوار يقرؤها مدير المركز لا مبرمج. «candidate.cross_sector»
    // لا تقول له شيئاً، و«إسناد مرشّح لمقيّم من قطاع آخر» تقول كل شيء — والفرق
    // بينهما هو الفرق بين ضبطٍ واعٍ ونقرٍ على المجهول.
    public const LABELS = [
        // المرشحون
        'candidate.view' => 'عرض المرشحين',
        'candidate.create' => 'إضافة مرشّح',
        'candidate.edit' => 'تعديل بيانات مرشّح',
        'candidate.approve' => 'اعتماد ترشيح',
        'candidate.view_names' => 'رؤية أسماء المرشحين (حسّاسة)',
        'candidate.view_classified' => 'رؤية المرشحين المصنَّفين (حسّاسة)',
        'candidate.journey' => 'عرض رحلة المرشّح',
        'candidate.cv_view' => 'قراءة السيرة الذاتية',
        'candidate.update_request' => 'رفع طلب تحديث بيانات',
        'candidate.update_approve' => 'البتّ في طلبات التحديث',
        'candidate.cross_sector' => 'الإسناد عبر القطاعات',
        // الجدولة
        'schedule.view' => 'عرض الجدول',
        'schedule.manage' => 'إدارة الجدولة',
        'schedule.distribute' => 'التوزيع الأسبوعي',
        'roster.manage' => 'إسناد مجموعات كشف اليوم',
        // استقبال الموظفين
        'reception.view' => 'فتح شاشة استقبال الموظفين',
        'reception.record' => 'تسجيل الوصول وأخذ التوقيع والإقرار',
        'reception.assign' => 'توزيع المرشحين على الأنشطة',
        'reception.decide' => 'استلام المرشّح أو ردّه (للمقيّم)',
        'reception.approve' => 'اعتماد الاستقبال وترحيل الجلسات',
        // الحضور
        'attendance.view' => 'عرض الحضور',
        'attendance.record' => 'تسجيل حضور جلساته',
        'attendance.record_any' => 'تسجيل حضور أي جلسة',
        // التقييم
        'evaluation.view' => 'عرض التقييم',
        'evaluation.input' => 'إدخال التقييم',
        'evaluation.assist' => 'الرصد المساعد',
        'evaluation.approve' => 'اعتماد التقييم',
        // أدوات القياس
        'measurement.view' => 'عرض أدوات القياس',
        'measurement.upload' => 'رفع نتائج القياس',
        // التقارير
        'report.view' => 'عرض التقارير',
        'report.create' => 'كتابة تقرير',
        'report.edit_any' => 'تعديل تقرير كتبه غيره',
        'report.approve_evaluator' => 'اعتماد: مستشار المقابلة',
        'report.approve_manager' => 'اعتماد: مدير إدارة التقييم',
        'report.approve' => 'اعتماد: إدارة تطوير الكفاءات',
        'report.approve_center' => 'اعتماد: مدير المركز (نهائي)',
        'report.return' => 'إرجاع تقرير',
        'report.cancel' => 'إلغاء تقرير',
        'report.export' => 'تصدير التقارير',
        'report.view_names' => 'اسم المرشّح في المستند المطبوع (حسّاسة)',
        'report.exec_summary' => 'كتابة الملخّص التنفيذي',
        // خطط التطوير
        'development_plan.view' => 'خطط التطوير الفردية',
        // الكفاءات
        'competency.view' => 'عرض إطار الكفاءات',
        'competency.manage' => 'إدارة الكفاءات وربطها بالأنشطة',
        // المراسلات
        'communication.invite' => 'إرسال الدعوات',
        'chat.view' => 'المحادثات',
        // التحليلات
        'analytics.view' => 'شاشة التحليلات',
        'analytics.executive' => 'اللوحة التنفيذية',
        'analytics.daily_report' => 'التقرير اليومي',
        // سلطات النظام
        'workflow.manage' => 'ضبط مراحل الاعتماد',
        'user.manage' => 'إدارة المستخدمين والأدوار',
        'settings.manage' => 'إدارة الإعدادات',
        'audit.view' => 'سجل التدقيق',
    ];

    public static function label(string $permission): string
    {
        return self::LABELS[$permission] ?? $permission;
    }

    // ── الصلاحيات مجمّعة للعرض ──
    public static function grouped(): array
    {
        $groups = [
            'candidate' => 'المرشحون',
            'schedule' => 'الجدولة',
            'roster' => 'مجموعات المشاركين',
            'reception' => 'استقبال الموظفين',
            'attendance' => 'الحضور',
            'evaluation' => 'التقييم',
            'measurement' => 'أدوات القياس',
            'report' => 'التقارير',
            'development_plan' => 'خطط التطوير',
            'competency' => 'الكفاءات',
            'communication' => 'المراسلات',
            'chat' => 'المحادثات',
            'analytics' => 'التحليلات',
            'workflow' => 'مراحل الاعتماد',
            'user' => 'المستخدمون',
            'audit' => 'التدقيق',
            'settings' => 'الإعدادات',
        ];

        $out = [];
        foreach (self::all() as $p) {
            $prefix = explode('.', $p)[0];
            $out[$prefix]['label'] = $groups[$prefix] ?? $prefix;
            $out[$prefix]['permissions'][] = $p;
        }

        // الترتيب بترتيب $groups لا بترتيب ثوابت الصنف. الانعكاس يُرجعها بترتيب
        // التعريف، فتظهر «سلطات النظام» في وسط الشاشة وتتفرّق مجموعةٌ أُضيفت
        // متأخّرة عن أخواتها — والشاشة تُقرأ بالعين لا بالترتيب الداخلي.
        $ordered = [];
        foreach (array_keys($groups) as $key) {
            if (isset($out[$key])) $ordered[$key] = $out[$key];
        }
        // مجموعة بلا مدخل في $groups تبقى ظاهرة في الذيل لا تختفي صامتة
        foreach ($out as $key => $g) {
            if (!isset($ordered[$key])) $ordered[$key] = $g;
        }

        return $ordered;
    }

    // ════════════════════════════════════════════════════════
    //  مصدر الصلاحيات: الجدول أولاً، والمصفوفة افتراضياً
    // ════════════════════════════════════════════════════════
    //
    // matrix() صارت **القيمة الافتراضية** التي يُبذَر بها كل دور أول مرّة، لا
    // المرجع الوحيد. المرجع هو جدول role_permissions كي يُحرّره المدير من
    // الشاشة بلا نشر. ودورٌ لا صفوف له يقع على المصفوفة — فتنصيبٌ لم يُبذَر
    // بعد، أو دورٌ أُضيف في الشيفرة ولم يُبذَر، يبقى عاملاً لا مشلولاً.
    //
    // التخزين على الحاوية لا في متغيّر ساكن: الحاوية تُبنى من جديد لكل طلب
    // ولكل اختبار، أمّا الساكن فيعيش مع العملية — فتقرأ اختباراتٌ تالية
    // صلاحياتِ اختبارٍ سابق.
    private const CACHE_KEY = 'kafaat.rolePermissions';

    private static function dbMap(): array
    {
        $app = app();
        if ($app->bound(self::CACHE_KEY)) {
            return $app->make(self::CACHE_KEY);
        }

        $map = [];
        try {
            $rows = \Illuminate\Support\Facades\DB::table('role_permissions')
                ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->select('roles.code', 'role_permissions.permission')
                ->get();
            foreach ($rows as $r) {
                $map[$r->code][] = $r->permission;
            }
        } catch (\Throwable) {
            // القاعدة غير مهيّأة بعد (هجرة أولى، أو أمر console قبل الترحيل):
            // نقع على المصفوفة بدل أن نُسقط الطلب
            $map = [];
        }

        $app->instance(self::CACHE_KEY, $map);
        return $map;
    }

    // تُستدعى بعد كل كتابة على صلاحيات الأدوار — وإلا خدم بقيةُ الطلب قيمةً قديمة
    public static function forgetCache(): void
    {
        app()->forgetInstance(self::CACHE_KEY);
    }

    // ── التحقق: هل الدور يملك الصلاحية؟ ──
    public static function roleHasPermission(string $roleCode, string $permission): bool
    {
        $perms = self::forRole($roleCode);
        if ($perms === []) return false;

        // '*' يُفرَد على الصلاحيات المعرَّفة لا على أي نصّ. كان يمرّر أي سلسلة،
        // فخطأ مطبعي في فحصٍ داخل متحكّم (hasPermission('candidate.viewww'))
        // يمرّ لمدير النظام ويُمنع عن الجميع — عطلٌ لا يظهر في اختبار المدير.
        // هذا أيضاً يوائم effectivePermissions() التي تفرد '*' إلى all() أصلاً،
        // فلا تعد الواجهةُ بقائمة تختلف عمّا يفرضه الخادم.
        if (in_array('*', $perms, true)) {
            return in_array($permission, self::all(), true);
        }

        return in_array($permission, $perms, true);
    }

    // ── قائمة صلاحيات الدور (تُرسل للواجهة لضبط العرض) ──
    //
    // الجدول أولاً. ودورٌ لا صفوف له يقع على المصفوفة — لا على قائمة فارغة:
    // دورٌ بلا صلاحيات إطلاقاً يعني حساباً لا يفتح شيئاً، وهو عطلٌ صامت لا
    // إعدادٌ مقصود. أمّا الفراغ المقصود (دور جُرّد عمداً) فيُمثَّل بصفٍّ
    // واحد هو PLACEHOLDER — انظر RoleController::savePermissions.
    public static function forRole(string $roleCode): array
    {
        $map = self::dbMap();
        if (array_key_exists($roleCode, $map)) {
            return array_values(array_diff($map[$roleCode], [self::PLACEHOLDER]));
        }

        return self::matrix()[$roleCode] ?? [];
    }

    // علامة «هذا الدور مضبوط عمداً على لا شيء». بدونها كان تجريد دورٍ من كل
    // صلاحياته يحذف صفوفه، فيقع على المصفوفة ويستعيد صلاحياته الافتراضية —
    // أي أنّ السحب الكامل كان ينقلب إلى استعادةٍ صامتة.
    public const PLACEHOLDER = '__none__';

    // هل صلاحيات هذا الدور محرَّرة في القاعدة (لا مأخوذة من المصفوفة)؟
    public static function roleIsCustomised(string $roleCode): bool
    {
        return array_key_exists($roleCode, self::dbMap());
    }
}
