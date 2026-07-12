# الاختبارات الآلية (Kafaat)

اختبارات Feature تعمل على قاعدة PostgreSQL مخصّصة للاختبار (تُهجَّر تلقائياً وتُبذَر، وكل اختبار داخل معاملة تُلغى بعده).

## إعداد لمرّة واحدة

```bash
createdb kafaat_test          # أو: psql -c "CREATE DATABASE kafaat_test"
```

يُستخدم نفس مضيف/مستخدم/كلمة مرور قاعدة التطوير (من `.env`)، ويُبدَّل اسم القاعدة إلى `kafaat_test` عبر `phpunit.xml`.
(اخترنا PostgreSQL بدل sqlite لأن بعض الهجرات تستخدم `->change()` غير المدعوم جيداً في sqlite.)

## التشغيل

```bash
php artisan test                       # كل الاختبارات
php artisan test tests/Feature/PublicGateTest.php   # ملف واحد
```

## التغطية (ثوابت أمنية أساسية)

- **AuthTest** — الدخول يرجع الصلاحيات، كلمة مرور خاطئة، قفل الحساب بعد ٥ محاولات.
- **CandidateSecurityTest** — بوابة التصنيف (٤٠٤ للمصنّف لغير المخوّل)، منع طمس سجلّ مصنّف، بوابة الصلاحية.
- **ReportAuthoringTest** — الإنشاء يشترط انتهاء التقييم، تقرير واحد لكل دورة، ملكية التعديل، بوابة التصنيف، الإرسال للاعتماد.
- **PublicGateTest** — بوابة الهوية (رفض/قبول)، رمز الجلسة، التأكيد لمرة واحدة، الرمز المزوّر، قفل التخمين، عدم كشف الوجود.
- **AttendanceTest** — الحضور لمرّة واحدة، بوابة التصنيف.

الأدوات المشتركة في `tests/TestCase.php`: `actingAsRole()`، `makeCandidate()`، `validNationalId()`.
