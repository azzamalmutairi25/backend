# مرجع واجهة برمجة التطبيقات — مركز تمكين الكفاءات (Kafaat API)

مرجعٌ للـ REST API الخلفي (Laravel 13). كل المسارات تحت البادئة `/api`.

> **تبني تكاملاً؟** ابدأ بـ[**دليل المستخدم**](API_GUIDE.md) — أمثلة عاملة ووصفات كاملة وأخطاء شائعة. هذا الملف مرجعُ المسارات تعود إليه بعده.

---

## المصادقة (Authentication)

- **النوع:** رمز Bearer عبر Laravel Sanctum.
- **الحصول عليه:** `POST /api/login` يُعيد `token` — يُرسَل في كل طلب محمي:
  `Authorization: Bearer <token>`.
- **الاستجابة:** JSON دائماً (`Accept: application/json`).
- **الخروج:** `POST /api/logout` يُبطل الرمز الحالي. `POST /api/change-password` يُبطل بقية الجلسات ويُبقي الحالية.

### أمثلة الحالات
| الحالة | المعنى |
|---|---|
| `200` / `201` | نجاح |
| `401` | غير مُصادَق (رمز مفقود/منتهٍ) |
| `403` | مُصادَق لكن **بلا صلاحية** |
| `404` | غير موجود **أو خارج نطاقك** (لا يُفرَّق بينهما عمداً — انظر الأعراف) |
| `409` | تعارض حالة (أعد التحميل) — مثل سلسلة اعتماد التقارير أو إعادة الجدولة |
| `422` | مدخلات غير صالحة / حالة غير مسموحة |
| `429` | تجاوز حدّ المعدّل (throttle) |

---

## الأعراف (Conventions)

- **خارج النطاق = ٤٠٤ لا ٤٠٣:** المعرّف لا يكشف الوجود أبداً. غياب الصلاحية = ٤٠٣؛ أما مورد خارج قطاع/تصنيف المستخدم فيُعامَل كـ«غير موجود» (٤٠٤).
- **حصر التصنيف (fail-closed):** من لا يملك `candidate.view_classified` يرى المشاركين «العاديين» فقط؛ المصنّفون (`secret`/`top_secret`) محجوبون في القوائم والتفاصيل والتجميعات والسجل.
- **الحصر القطاعي:** الأدوار المحصورة (`EVALUATOR`, `DISCUSSION_EVAL`, `ASSISTANT`) محصورة بقطاعها؛ والمقيّم يُضيَّق أكثر إلى من قيّمهم هو.
- **تقييد المعدّل:** `POST /login` (١٠/دقيقة)، البوّابة العامة (٢٠/دقيقة)، واختبارات التكامل الخارجي `settings/*/test` (٥/دقيقة).
- **شكل الخطأ:** `{ "error": "..." }` أو `{ "message": "...", "errors": { "field": ["..."] } }` لأخطاء التحقق (٤٢٢).

---

## نموذج الصلاحيات (Permissions)

- كل دور يملك مجموعة صلاحيات. **المرجع جدول `role_permissions`** يحرّره مدير النظام من شاشة «الأدوار والصلاحيات» فيسري فوراً بلا نشر؛ و`Permissions::matrix()` هي الافتراضي الذي يُبذَر منه كل دور أول مرّة ويُرجَع إليه عند «إعادة الافتراضي». `ADMIN` يملك `*` (كل شيء).
- **استثناءات المستخدم** (`user_permission_overrides`): تمنح/تمنع صلاحية فوق الدور. المنع يغلب المنح.
- **غير قابلة للتفويض** (`NON_DELEGABLE`): `user.manage`, `settings.manage`, `audit.view` — تُدار بالدور فقط.
- الواجهة تقرأ الصلاحيات من كائن المستخدم (`GET /me`)؛ لكن **الخادم هو المرجع** ويفرضها على كل طلب.

---

## المسارات (Endpoints)

> العمود «الصلاحية» يذكر البوابة الأساسية للمسار. بعض المسارات تطبّق حصراً إضافياً (تصنيف/قطاع) داخلياً.

### المصادقة
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| POST | `/login` | — (عام، ١٠/دقيقة) | تسجيل الدخول، يُعيد الرمز والمستخدم |
| GET | `/me` | مُصادَق | بيانات المستخدم الحالي وصلاحياته |
| POST | `/logout` | مُصادَق | إبطال الرمز الحالي |
| POST | `/change-password` | مُصادَق | تغيير كلمة المرور (يُبطل بقية الجلسات) |

### المشاركون (Candidates)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/candidates` | `candidate.view` | قائمة المشاركين (محصورة بالنطاق) — انظر **الترقيم والفرز** أدناه |
| GET | `/candidates/stats` | `candidate.view` | إحصاءات مطابقة لحصر القائمة |
| POST | `/candidates` | `candidate.create` | إضافة مشارك (+ دورة تقييم). `assessmentType`: `comprehensive` أو `special_request`. السيرة (`cv`) إلزامية. `technicalAreaIds` اختيارية هنا وإلزامية في التعديل — والاستجابة تردّ `needsTechnicalAreas` و`candidateId` لسَوق الشاشة إلى استكمالها |
| POST | `/candidates/lookup` | `candidate.create` | فحص تكرار الهوية قبل ملء النموذج — يرجع `exists` وحدها، والرمز لحاملي `candidate.edit` فقط. مخنوق ٢٠/دقيقة ومُقيَّد في السجلّ |
| POST | `/candidates/import/batch` | `candidate.create` | الاستيراد الضخم: تُجمَّع الصفوف على نداءات (حتى ١٠٠٠ صفّ للنداء، و١٠٠٠٠ للملفّ) ثمّ تُعالَج في الخلفية. أوّل نداء بلا `batchId` يفتح رفعة، و`final:true` يُغلقها ويُطلق المعالجة. مخنوق ٦٠/دقيقة |
| GET | `/candidates/import/batch/{}` | — | حالة الرفعة وتقدّمها وإخفاقاتها (لصاحبها وحده). الصفوف لا تُردّ، والإخفاقات تُقتطع عند ٢٠٠ |
| POST | `/candidates/import` · `/import/candidates` | `candidate.create` | استيراد جماعي — `rows[]` حتى ٥٠٠، انظر **الاستيراد الجماعي** أدناه |
| GET | `/candidates/export` | `candidate.view` | تصدير القائمة |
| GET | `/candidates/{id}` | `candidate.view` | تفاصيل مشارك |
| PUT | `/candidates/{id}` | `candidate.edit` | تعديل |
| DELETE | `/candidates/{id}` | `candidate.edit` | حذف |
| POST | `/candidates/{id}/approve` | `candidate.edit` | اعتماد للتقييم |
| PATCH | `/candidates/{id}/classify` | `candidate.view_classified` | تغيير تصنيف السرّية |
| PATCH | `/candidates/{id}/notes` | `candidate.edit` | حفظ ملاحظات المشارك وحدها — لا تشترط الهوية والاسم كما يشترطهما التعديل الكامل، فيكتبها من يرى المشارك بلا بياناته الشخصية |
| GET | `/candidates/{id}/assessments` | `candidate.view` | دورات المشارك |
| GET | `/candidates/{id}/journey` | `candidate.journey` | رحلة المشارك |
| POST | `/candidates/{id}/reassess` | `candidate.edit` | دورة تقييم جديدة |
| GET | `/candidates/{id}/history` | `audit.view` | سجل تدقيق المشارك |
| GET | `/candidates/{id}/interviewers` | `schedule.manage` | مستشارو المقابلة المؤهّلون |
| GET | `/candidates/{id}/assessors` | `schedule.manage` | المؤهّلون لنشاطٍ ومقعد — `?activity`، `?seat=evaluator\|assistant`، ومع `?periodId` (و`?date`) يعود النصاب والحمل |
| GET | `/candidates/cards` | `candidate.view` | بطاقات المشاركين للطباعة |

#### الترقيم والفرز — أربع قوائم

تقبلها `GET /candidates` و`/reports` و`/evaluations` و`/users` بالسلوك نفسه:

| المُعامِل | القيم |
|---|---|
| `page` | ≥ ١ — صفحةٌ تجاوزت الآخر **تُشدّ إلى الأخيرة** لا تعود فارغة |
| `perPage` | ١–٢٠٠ (٥٠ عند طلب صفحة بلا تحديد) |
| `sort` | من قائمة كل مسار أدناه — وغيرها ٤٢٢ |
| `dir` | `asc` · `desc` |

| المسار | أعمدة الفرز | الافتراضي |
|---|---|---|
| `/candidates` | `code` · `sector` · `rank` · `tier` · `status` · `classification` · `created` | `code` تصاعدياً |
| `/reports` | `created` · `code` · `status` · `recommendation` · `behavioral` · `technical` · `returns` | `created` **تنازلياً** |
| `/evaluations` | `updated` · `code` · `status` · `activity` | `updated` **تنازلياً** |
| `/users` | `name` · `username` · `role` · `active` · `lastLogin` · `created` | `name` تصاعدياً |

**الترقيم بطلبٍ صريح.** بلا `page` ولا `perPage` تعود القائمة **كاملةً كما كانت** — فلا ينكسر عميلٌ قائم بصمت. وفي هذه الحالة يُطبَّق سقفٌ صلب (٥٠٠٠ صفّ) يُعلَن في `meta.truncated`.

الاستجابة تحمل `meta` **إضافةً** لا بديلاً عن `candidates`:

```json
{
  "candidates": [ … ],
  "meta": { "total": 1240, "shown": 50, "page": 1, "perPage": 50,
            "lastPage": 25, "sort": "code", "dir": "asc", "truncated": false }
}
```

`total` يعكس **الفلاتر والحصر** لا الجدول كلّه — عدد ما يطابق بحثك في نطاقك.
والفرز على أي عمودٍ غير الرمز يُذيَّل بالرمز فاصلاً ثابتاً: صفوفٌ متساوية ترتيبها غير محدَّد في postgres، فبلا الفاصل يظهر صفٌّ في صفحتين ويغيب آخر.

#### الاستيراد الجماعي

`POST /candidates/import` — `{"rows": [...]}`‏، حتى **٥٠٠** صفّ (تجاوزها ‎422‎).

```json
{"rows": [{
  "nationalId": "1054321987",
  "fullName": "محمد بن أحمد الشهري",
  "mobile": "0501234567",
  "sectorCode": "الأمن العام",
  "rankLabel": "عميد"
}]}
```

`sectorCode` يُقبل بالرمز الداخلي أو ببادئة المشارك أو **باسم القطاع العربي**؛ والاسم يُطبَّع قبل المطابقة (تُزال التشكيلات و«ال» التعريف، وتُوحَّد الهمزات والتاء المربوطة والياء). `mobile` وحده اختياري. وحقل البريد الإلكتروني رُفع من المشارك — يُتجاهَل إن أُرسل.

الفحص هو فحص `POST /candidates` نفسه — `nationalId` يمرّ على `SaudiNationalId` كاملاً (عشرة أرقام، بادئة ١ أو ٢، والمجموع التحقّقي)، و`mobile` على `^05\d{8}$`. وسبق أن اكتفى هذا المسار بطول الهوية، فكان باباً يدخل منه ما ترفضه الشاشة.

الاستيراد **جزئي**: يُنشأ الصالح ويُردّ الباقي — ولا يُجهَض كلّه لصفٍّ واحد.

```json
{
  "message": "اكتمل الاستيراد",
  "imported": 2,
  "failed": 1,
  "successList": [{"line": 1, "code": "PS-002", "name": "…"}],
  "failures": [{"row": 3, "nationalId": "…", "name": "…",
                "reasons": ["رقم الهوية غير صحيح (فشل التحقّق)", "الرتبة / المرتبة مفقودة"]}],
  "errors": ["الصفّ 3: رقم الهوية غير صحيح (فشل التحقّق) · الرتبة / المرتبة مفقودة"]
}
```

`failures` مبنيّة وهي المعتمَدة؛ و`errors` نصوصٌ مسطّحة تبقى للتوافق مع مستهلكٍ قديم. وأسباب الصفّ **تُجمع كلّها** لا يُكتفى بأوّلها: الردّ عند أول خطأ يجعل تصحيح ملفٍّ رحلاتٍ متكرّرة. والتكرار داخل الدفعة الواحدة يُكشف كما يُكشف المسجَّل في القاعدة.

### طلبات تحديث بيانات المشاركين (Update Requests)
> يرفعها المستخدم الخارجي حين يجد المشارك مسجّلاً مسبقاً — الكتابة فوق سجلٍّ قائم ممنوعة من الخارج.

| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| POST | `/candidate-update-requests` | `candidate.update_request` (٣٠/دقيقة) | رفع طلب تحديث |
| GET | `/candidate-update-requests/mine` | `candidate.update_request` | متابعة طلباتي وحالتها |
| GET | `/candidate-update-requests` | `candidate.update_approve` | الطلبات الواردة |
| GET | `/candidate-update-requests/{id}` | `candidate.update_approve` | تفاصيل طلب |
| POST | `/candidate-update-requests/{id}/approve` | `candidate.update_approve` | اعتماد (يُطبَّق على السجل) |
| POST | `/candidate-update-requests/{id}/reject` | `candidate.update_approve` | رفض بسبب |

### استقبال الموظفين (Reception)
> مسار المشارك من باب المركز إلى جدول المقابلات — **صلاحية لكل مرحلة**، ولا تُتخطّى مرحلة.

| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/reception` | `reception.view` | كشف اليوم + مهامّي (يتشكّل بالصلاحية) — `?date`، `?q` |
| POST | `/reception/arrive` | `reception.record` | تسجيل وصول (وقت تلقائي) |
| PATCH | `/reception/visits/{id}/arrival` | `reception.record` | تعديل وقت الوصول (`HH:MM`) |
| POST | `/reception/visits/{id}/sign` | `reception.record` (٦٠/دقيقة) | توقيع المشارك وإقراره — PNG بترميز `data:` ≤٤٠٠ك محرف |
| GET | `/reception/visits/{id}/cv` | `reception.view` + (`reception.record` أو `candidate.cv_view`) | سيرة من أمامك اليوم |
| GET | `/reception/evaluators` | `reception.assign` | **من يستطيع الاستلام فعلاً** — `?activity`، `?sectorId` |
| POST | `/reception/visits/{id}/assign` | `reception.assign` | توزيع على `interview`/`discussion`/`measurement` (بعد التوقيع) |
| DELETE | `/reception/assignments/{id}` | `reception.assign` | سحب إسناد |
| GET | `/reception/assignments/{id}/cv` | `reception.decide` | **سيرة بالرمز — بلا اسم ولا هوية أبداً** (قاعدة إجراء لا صلاحية) |
| POST | `/reception/assignments/{id}/accept` | `reception.decide` | قبول المشارك |
| POST | `/reception/assignments/{id}/reject` | `reception.decide` | ردّه للعمليات بسبب (٣–٥٠٠ حرف) |
| POST | `/reception/visits/{id}/approve` | `reception.approve` | اعتماد البيانات وترحيلها للجدول (يشترط التوقيع) |

### السيرة الذاتية (CV)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/candidates/{id}/cv` | `candidate.cv_view` | عرض السيرة (إدارة) |
| PUT | `/candidates/{id}/cv` | `candidate.edit` | حفظ/تعديل السيرة |
| GET | `/candidates/{id}/cv/document` | `candidate.cv_view` | نموذج السيرة مطبوعاً (المتصفّح → PDF) |
| GET | `/evaluations/{id}/cv` | `evaluation.view` | سيرة مُجهّلة للمقيّم (لقطة مجمّدة) |

### التقييم (Evaluations)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/evaluations` | `evaluation.view` | تقييماتي |
| POST | `/evaluations/start` | `evaluation.input` | بدء تقييم |
| GET | `/evaluations/{id}` | `evaluation.view` | تفاصيل |
| POST | `/evaluations/{id}/scores` | `evaluation.input` | حفظ الدرجات |
| POST | `/evaluations/{id}/submit` | `evaluation.input` | إرسال للاعتماد |
| POST | `/evaluations/{id}/approve` | `evaluation.approve` | اعتماد |
| POST | `/evaluations/{id}/return` | `evaluation.approve` | إرجاع للمقيّم |
| GET | `/competencies` | `evaluation.view` | كفاءات النشاط |

### الجدولة (Scheduling)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/scheduling-periods` | `schedule.view` | موجات الجدولة — `?status`، `?openOnly=1` |
| POST | `/scheduling-periods` | `schedule.manage` | إنشاء موجة (الاسم فريد) |
| PUT | `/scheduling-periods/{id}` | `schedule.manage` | تعديل موجة (ما لم تُعتمد) |
| DELETE | `/scheduling-periods/{id}` | `schedule.manage` | حذف موجة بلا جلسات |
| GET | `/scheduling-periods/{id}/eligible` | `schedule.manage` | من يصلح للإدراج — `?activity`، `?seat` (بلا فتح `/users`) |
| GET | `/scheduling-periods/{id}/assessors` | `schedule.view` | لوحة المقيّمين والمساعدين ونصابهم وحملهم |
| PUT | `/scheduling-periods/{id}/assessors` | `schedule.manage` | حفظ اللوحة كاملةً (استبدال ذرّي) |
| POST | `/scheduling-periods/{id}/submit` | `schedule.manage` | إرسال الجدولة لمدير المركز |
| POST | `/scheduling-periods/{id}/approve` | `schedule.approve_center` | اعتماد الموجة |
| POST | `/scheduling-periods/{id}/reject` | `schedule.approve_center` | إرجاعها مسودّةً بسبب (`reason` إلزامي) |
| POST | `/scheduling-periods/{id}/close` | `schedule.manage` | إغلاق موجة معتمَدة |
| GET | `/scheduling-periods/{id}/workflow` | `schedule.view` | خطوات سير العمل وحالة كلٍّ منها على الموجة + نسبة الإنجاز |
| POST | `/scheduling-periods/{id}/workflow/{stepId}` | `schedule.manage` | تأشير خطوة يدوية — `status=done\|skipped\|pending`، و`note` إلزامية مع `skipped` |
| GET | `/schedules` | `schedule.view` | قائمة الجلسات (نافذة متدحرجة + سقف) — `?periodId` يحصرها بموجة |
| POST | `/schedules` | `schedule.manage` | جدولة جلسة |
| PUT | `/schedules/{id}` | `schedule.manage` | تعديل (يُبطل الحضور عند تغيّر الموعد) |
| DELETE | `/schedules/{id}` | `schedule.manage` | حذف (يُمنع بعد الحضور) |
| GET | `/schedules/permits` | `schedule.view` | تصاريح دخول اليوم — `?date`، `?sectorId`، و`&showName=1` لحاملي `candidate.view_names` وحدهم |
| GET | `/schedules/absences/{candidateId}` | `schedule.view` | جلسات غياب قابلة لإعادة الجدولة |
| POST | `/schedules/{id}/reschedule` | `candidate.edit` | إعادة جدولة غياب (مرّة واحدة) |
| GET | `/golden-schedule` | `schedule.view` | الجدول الذهبي — `?periodId` إلزامي، `?sectorId` |
| POST | `/golden-schedule` | `schedule.manage` | صفّ يدوي (تاريخ + رمز + قطاع) — لا تمحوه المزامنة |
| POST | `/golden-schedule/{id}/sync` | `schedule.manage` | ترحيل جلسات الموجة إلى الجدول (idempotent) |
| DELETE | `/golden-schedule/{id}` | `schedule.manage` | حذف صفّ |
| GET | `/golden-schedule/document` | `schedule.view` | المستند المطبوع (المتصفّح → PDF) |
| GET | `/discussion-circles` | `schedule.view` | حلقات النقاش — `?date`، `?periodId`، `?sectorId` |
| POST | `/discussion-circles` | `schedule.manage` | إنشاء حلقة (السعة من الإعدادات ما لم تُرسَل) |
| PUT · DELETE | `/discussion-circles/{id}` | `schedule.manage` | تعديل (تتبعه جلساتها) / حذف حلقة فارغة |
| POST | `/discussion-circles/{id}/attach` | `schedule.manage` | إسناد مشاركين — يُنشئ جلسات `discussion`، ويردّ ما تجاوز السعة في `skipped` |
| DELETE | `/discussion-circles/{id}/detach` | `schedule.manage` | سحب مشارك (يُمنع بعد الحضور) |
| GET | `/roster` | `schedule.view` | مجموعتا كشف اليوم (أ/ب) |
| POST · DELETE | `/roster/assign` | `roster.manage` | إسناد/إلغاء إسناد مجموعة |
| GET | `/roster/document` | `schedule.view` | كشف الحضور المطبوع — `?date`، و`?sectorId` لغير المحصور بقطاع، و`&showNationalId=1` لحاملي `candidate.view_names` وحدهم |
| GET | `/roster/sectors` | `schedule.view` | قطاعات اليوم وأعدادها — لفتح ملفٍّ لكل قطاع |
| GET | `/dispatch/authorities` | `schedule.view` | الجهات المستلِمة والفئات التي تستقبلها |
| GET | `/dispatch/preview` | `schedule.view` | ما سيُسلَّم لكل جهة — `?periodId` أو `?from`+`?to` |
| POST | `/dispatch/send` | `schedule.dispatch` | إخراج ملفّ CSV وتسجيل التسليم ببصمة SHA-256 |
| GET | `/dispatch/document` | `schedule.dispatch` | محضر تسليم للتوقيع — `?dispatchId` |
| GET | `/dispatches` | `schedule.view` | سجلّ التسليمات |

### الحضور (Attendance)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/attendance/today` | `attendance.view` | جلسات اليوم |
| GET | `/attendance/stats` | `attendance.view` | مؤشرات الحضور |
| POST | `/attendance/{scheduleId}/checkin` | `attendance.record` | تسجيل حضور (اليوم فقط) |
| POST | `/attendance/{scheduleId}/absence` | `attendance.record` | تسجيل غياب |

### أدوات القياس (Measurement)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/measurements/{candidateId}` | `measurement.view` | نتيجة القياس للدورة |
| POST | `/measurements` | `measurement.upload` | رفع/تحديث نتيجة القياس |

### التقارير (Reports)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/reports` | `report.view` | قائمة التقارير |
| GET | `/reports/stats` | `report.view` | إحصاءات |
| GET | `/reports/eligible-candidates` | `report.create` | مشاركون جاهزون لتقرير |
| GET | `/reports/score-preview` | `report.view` | معاينة الدرجات |
| GET | `/reports/competency-gap` | `report.view` | فجوة الكفاءات لمشارك |
| GET | `/reports/analytics` | `report.view` | تجميعات التقارير |
| GET | `/reports/export` | `report.export` | تصدير CSV |
| POST | `/reports` | `report.create` | إنشاء |
| GET | `/reports/{id}` | `report.view` | تفاصيل |
| PUT | `/reports/{id}` | `report.create`/`report.edit_any` | تعديل/إرسال |
| POST | `/reports/{id}/approve` | مرحلة السلسلة | اعتماد مرحلة (٤٠٩ عند التعارض) |
| POST | `/reports/{id}/return` | `report.return` | إرجاع للتعديل |
| POST | `/reports/{id}/resubmit` | `report.create` | إعادة إرسال |
| POST | `/reports/{id}/cancel` | `report.cancel` | إلغاء |
| POST | `/reports/{id}/executive-summary` | `report.exec_summary` | الملخص التنفيذي (مدير المركز) |
| GET | `/reports/{id}/document` | `report.view` | التقرير الكامل مطبوعاً (HTML مُهرَّب) |
| GET | `/reports/{id}/brief` | `report.view` | الموجز مطبوعاً |

### خطط التطوير (Development Plans)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/development-plans/{candidateId}` | `development_plan.view` | بنود خطة الدورة |
| POST | `/development-plans` | `report.create` | إضافة بند |
| POST | `/development-plans/seed` | `report.create` | توليد من مجالات التقرير (مرّة واحدة) |
| PUT | `/development-plan-items/{id}` | `report.create` | تحديث بند |
| DELETE | `/development-plan-items/{id}` | `report.create` | حذف بند |

### التحليلات (Analytics)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/analytics/executive` | `analytics.executive` | **القيادة التنفيذية — المؤشرات**: مؤشرات بفروقات، خريطة حرارية كفاءة×قطاع، اتجاهات، مقارنة قطاعات، مقارنة فئات قيادية، توزيع جاهزية، رؤى تلقائية. المُعامِل `?months` (٦ افتراضاً) |
| GET | `/analytics/executive/overview` | `analytics.executive` | **القيادة التنفيذية — نظرة شاملة**: ثلاثة عشر قسماً (المشاركون، الموجات، الجلسات، الاستقبال، الحضور، التقييم، القياس، التقارير، خطط التطوير، الكفاءات، طلبات التحديث، الفريق، التدقيق) بشكلٍ موحّد `{key,label,icon,route,metrics,bars}`. **الإعدادات خارجها عمداً** |
| GET | `/analytics/executive/reports` | `analytics.executive` | **القيادة التنفيذية — التقارير**: مؤشرات السلسلة، خطّ الاعتماد، أطول انتظار في كل مرحلة، توزيع التوصيات، وأحدث التقارير **بالرمز لا بالاسم** (اطّلاع لا تحرير). المُعامِل `?limit` (٢٥ افتراضاً، ١٠٠ حدّاً) |
| GET | `/analytics/dashboard` | `analytics.view` | نظرة موحّدة مختصرة |
| GET | `/analytics/by-sector` | `analytics.view` | تجميع حسب القطاع |
| GET | `/analytics/competency-gaps` | `analytics.view` | فجوات الكفاءات (الأضعف أولاً) |
| GET | `/analytics/trends` | `analytics.view` | التقارير المعتمدة شهرياً |
| GET | `/daily-report` | `analytics.daily_report` | يوم المركز مجمَّعاً — `?date` |
| GET | `/daily-report/document` | `analytics.daily_report` | التقرير اليومي مطبوعاً |

### المحادثات والإشعارات (Chat & Notifications)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/chat/{entityType}/{entityId}` | `chat.view` + `report.view` (+نطاق) | محادثة كيان (تقرير) |
| POST | `/chat/{threadId}/message` | `chat.view` + `report.view` (+نطاق) | إرسال رسالة |
| GET | `/notifications` | مُصادَق | إشعاراتي (مُرقّمة) |
| GET | `/notifications/unread-count` | مُصادَق | عدّاد غير المقروء |
| PATCH | `/notifications/{id}/read` | مُصادَق | تعليم كمقروء |
| PATCH | `/notifications/read-all` | مُصادَق | تعليم الكل |

### إطار الكفاءات (Competencies)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/competencies/framework` | `competency.view` | الإطار المرجعي |
| POST | `/competencies` | `competency.manage` | إضافة كفاءة |
| PUT | `/competencies/{id}` | `competency.manage` | تعديل |
| GET | `/activity-competencies` | `competency.view` | ربط الأنشطة بالكفاءات |
| PUT | `/activity-competencies/{activity}` | `competency.manage` | استبدال ربط نشاط (الإضافة فقط عند وجود تقييمات نشطة) |

### التوزيع الأسبوعي (Distribution)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/distribution` | `schedule.distribute` | مقترح التوزيع |
| POST | `/distribution/propose` | `schedule.distribute` | توليد مقترح (فريد لكل أسبوع) |
| POST | `/distribution/{id}/approve` | `schedule.distribute` | اعتماد (قفل صفّي ضد الحجز المزدوج) |
| DELETE | `/distribution/{id}` | `schedule.distribute` | حذف مقترح |

### التواصل (Communications)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| POST | `/communications/invite` | `send_invitation` | إرسال دعوة (رابط بوّابة عبر SMS) |
| GET | `/communications/history/{candidateId}` | `send_invitation` | سجل الرسائل |

### الأدوار وصلاحياتها (Roles) — `user.manage`
> المرجع الحيّ للصلاحيات. أربعة حرّاس ترفض بـ٤٢٢: دور `ADMIN` محميّ، ولا تعدّل دورك، ولا تمنح ما لا تملك، والصلاحيات غير القابلة للتفويض لا تُمسّ.

| الطريقة | المسار | الغرض |
|---|---|---|
| GET | `/roles` | الأدوار وعدد صلاحيات كلٍّ ومن يحمله |
| GET | `/roles/{id}/permissions` | صلاحيات الدور + الكتالوج مجموعاً + أسباب القفل |
| PUT | `/roles/{id}/permissions` | حفظ الصلاحيات — **استبدال لا إضافة**: ما لا تُرسله يُسحَب |
| POST | `/roles` | إنشاء دور |
| PUT | `/roles/{id}` | تعديل اسم الدور |
| DELETE | `/roles/{id}` | حذف دور (صلاحياته تتبعه) |
| POST | `/roles/{id}/reset` | إعادة الدور إلى افتراضي المنصّة |

### المستخدمون (Users) — إدارة
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/users` | `user.manage` | قائمة المستخدمين |
| GET | `/users/roles` | `user.manage` | الأدوار |
| GET | `/users/role-permissions` | `user.manage` | مصفوفة الدور↔الصلاحية |
| POST | `/users` | `user.manage` | إنشاء (بسقف امتياز) |
| PUT | `/users/{id}` | `user.manage` | تعديل (لا تعديل من يفوقك) |
| PATCH | `/users/{id}/toggle` | `user.manage` | تفعيل/تعطيل |
| PATCH | `/users/{id}/password` | `user.manage` | إعادة تعيين كلمة المرور |
| GET | `/users/{id}/permissions` | `user.manage` | استثناءات المستخدم |
| PUT | `/users/{id}/permissions` | `user.manage` | حفظ الاستثناءات (بسقف ثلاثي) |
| GET | `/users/permission-catalog` | `user.manage` | الصلاحيات مجمّعةً بأسمائها العربية + `canGrant`/`canRevoke`/`lockedReason` — تقرأها شاشة الوصول الجماعي |
| POST | `/users/bulk-permissions` | `user.manage` | **وصولٌ واحد على مجموعة موظفين**: `{userIds[], changes[{permission, action: grant\|revoke\|reset}], reason?}`. السقف الثلاثي نفسه؛ حسابُك وحاملُ `*` يُتخطّيان ويُعادان في `skipped`؛ الاستثناء المطابق للدور يُمحى صامتاً؛ كل حسابٍ متأثّر يُسجَّل في التدقيق وتُطرد جلساته |

### القوائم المرجعية (Reference) — مُصادَق، بلا صلاحية
> يحتاجها كل من يملأ نموذجاً — ومنه المستخدم الخارجي: `sectorId` و`rankLabel` حقلان إلزاميان في إنشاء المشارك.
> كلتاهما **تُشكّل استجابتها بالصلاحية**: البادئات وأعداد المرتبطين — وهي أرقام تكشف حجم كل قطاع — لا تُرسَل إلا لحامل `settings.manage`، ويُرسَل معها `canManage`.

| الطريقة | المسار | الغرض |
|---|---|---|
| GET | `/sectors` | القطاعات (+ البادئات والأعداد لمدير الإعدادات) |
| GET | `/ranks` | الرتب/المراتب — غير النشطة تظهر لمدير الإعدادات وحده |
| GET | `/dashboard/overview` | لوحة البداية — أقسامها تُحجب فرادى بحسب صلاحية القارئ |

### الإعدادات (Settings) — `settings.manage`
| الطريقة | المسار | الغرض |
|---|---|---|
| GET · PUT | `/settings/ldap` | ربط الدليل النشط |
| POST | `/settings/ldap/test` | اختبار الاتصال (٥/دقيقة) |
| GET · PUT | `/settings/sms` | بوّابة الرسائل النصية |
| POST | `/settings/sms/test` | إرسال رسالة اختبار — **بتكلفة** (٥/دقيقة) |
| GET · PUT | `/settings/smtp` | البريد الصادر |
| POST | `/settings/smtp/test` | اختبار الإرسال (٥/دقيقة) |
| GET · PUT | `/settings/idverify` | بوّابة التحقق من الهوية |
| POST | `/settings/idverify/test` | اختبار البوّابة (٥/دقيقة) |
| GET | `/settings/idverify/log` | سجل عمليات التحقق |
| GET · PUT | `/settings/distribution` | ضوابط التوزيع الأسبوعي |
| GET · PUT | `/settings/tier` | حدود الفئات القيادية |
| GET · PUT | `/settings/session-times` | أوقات جلسات اليوم (خيارات الحقل وأعمدة الكشف) |
| GET | `/expertise-areas` | مجالات الخبرة — مرجعٌ للجميع، وغير الفعّالة لحاملي `settings.manage` |
| POST · PUT · DELETE | `/expertise-areas` · `/expertise-areas/{id}` | إدارة المجالات (`settings.manage`) |
| PUT | `/users/{id}/expertise` | وسم حساب بمجالاته — `areaIds[]` (`user.manage`) |
| GET | `/technical-areas` | المجالات الفنية — مرجعٌ يُوسَم به المشارك ويُرشَّح عليه. قراءتها أوسع من مجالات الخبرة: تكفيها `candidate.view` أو `candidate.create` لأن نموذج الإضافة يعرضها وشاشة الترشيح تفلتر بها. تُرجع `areas[{id, label, sortOrder, isActive, participantCount}]` و`canManage`؛ وغير الفعّالة لحاملي `settings.manage` وحدهم ليعيدوا تفعيلها |
| POST | `/technical-areas` | إضافة مجال — `{label, sortOrder?}` ← `{message, areaId}` (٢٠١)؛ الاسم المكرّر ٤٢٢ في `errors.label` (`settings.manage`) |
| PUT | `/technical-areas/{id}` | تعديل مجال — `{label, sortOrder?, isActive?}` ← `{message}`؛ غير الموجود ٤٠٤ والاسم المكرّر ٤٢٢ (`settings.manage`) |
| DELETE | `/technical-areas/{id}` | حذف مجال ← `{message}`؛ **مجالٌ موصوفٌ به مشاركون لا يُحذف** — ٤٢٢ تدلّ على تعطيله ليبقى وسمهم مقروءاً (`settings.manage`) |
| GET · POST | `/settings/scheduling-workflow` | خطوات سير عمل الجدولة — القراءة تكفيها `schedule.view`، والإضافة `settings.manage` |
| PUT · DELETE | `/settings/scheduling-workflow/{id}` | تعديل/حذف خطوة (`settings.manage`) |
| PUT | `/settings/scheduling-workflow/reorder` | إعادة الترتيب — `ids[]` كاملةً لا جزئية (`settings.manage`) |
| PUT | `/sectors/{id}/prefix` | بادئة رمز المشارك للقطاع |
| POST · PUT · DELETE | `/sectors` · `/sectors/{id}` | إدارة القطاعات |
| POST · PUT · DELETE | `/ranks` · `/ranks/{id}` | إدارة الرتب — **تقود تصنيف الفئة القيادية** |
| GET · PUT | `/workflow/report` | ترتيب سلسلة الاعتماد وتفعيل مراحلها (`workflow.manage` أو `settings.manage`) |

> مسارات القطاعات والرتب الإدارية مسجَّلة في `routes/config.php` لا `routes/api.php`.

### التدقيق (Audit) — `audit.view`
> **لا يُفوَّض** بالاستثناء الفردي — يُدار بالدور وحده.

| الطريقة | المسار | الغرض |
|---|---|---|
| GET | `/audit/log` | السجل الموحّد — يحجب تفاصيل المشاركين المصنّفين عمّن لا يملك التصريح |
| GET | `/candidates/{id}/history` | سجل مشارك بعينه |

### كشك الاستقبال (Kiosk) — بلا مصادقة، ١٢٠/دقيقة
الجهاز اللوحي في بهو المركز. رمز اليوم في الرابط يفتحه مسؤول المشاركين، ثم بوّابة رقم الهوية داخل الشاشة — لا بيان قبلها. الرمز نطاقه يومٌ واحد وقابل للإبطال، ورمز الجلسة عمره ٥ دقائق ومربوط بالكشك والدورة معاً. يُعطَّل كلّياً بـ`features.reception_kiosk`.

| الطريقة | المسار | الغرض |
|---|---|---|
| GET | `/kiosk/{token}` | حالة الكشك — جاهزيةٌ فقط، لا بيانات مشاركين |
| POST | `/kiosk/{token}/identify` | بوّابة الهوية (٥ محاولات لكل رقم / ١٥ دقيقة) — تُرجع `accessToken` |
| POST | `/kiosk/{token}/arrive` | تسجيل الوصول — يُنشئ نفس `ReceptionVisit` لكشف الاستقبال |
| POST | `/kiosk/{token}/sign` | التوقيع والإقرار بصحّة البيانات (يشترط الوصول) |
| POST | `/kiosk/{token}/badge` | أمر طباعة البطاقة إلى طابور المسؤول (يشترط التوقيع) |

### كشك الاستقبال — جهة المسؤول
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/reception/kiosks` | `reception.record` | كشوك اليوم الفعّالة بروابطها |
| POST | `/reception/kiosks` | `reception.record` | إصدار رابط اليوم (`label` اختياري) |
| DELETE | `/reception/kiosks/{id}` | `reception.record` | إبطال الرابط فوراً |
| GET | `/reception/print-queue` | `reception.record` | البطاقات المطلوبة ولم تُطبع — `?date` |
| POST | `/reception/visits/{id}/badge-printed` | `reception.record` | تعليم البطاقة مطبوعة |
| POST | `/reception/visits/{id}/badge-reprint` | `reception.record` | إعادتها إلى الطابور |

### البوّابة العامة (Public Portal) — بلا مصادقة، ٢٠/دقيقة
> **مُعطَّلة حتى إشعار آخر.** المسارات لا تُسجَّل ما لم يُشغَّل `features.candidate_portal` (وقرينه `candidatePortal` في `frontend/src/services/features.js`). الشيفرة والاختبارات باقية لإعادتها.

| الطريقة | المسار | الغرض |
|---|---|---|
| POST | `/public/assessment/{token}/verify` | بوّابة العامل الثاني (رقم الهوية) — لا بيانات قبلها |
| POST | `/public/assessment/{token}/confirm` | تأكيد الحضور |
| POST | `/public/assessment/{token}/arrive` | تسجيل الوصول |
| POST | `/public/assessment/{token}/cv` | حفظ السيرة الذاتية (مُدقّقة ومُجهّلة) |

---

*مرجعٌ حيّ — يُحدَّث مع تطوّر الـ API. للتفاصيل الحقلية لكل مسار، انظر المتحكّم المقابل في `app/Http/Controllers/`. وللأمثلة العاملة والوصفات الكاملة: [دليل المستخدم](API_GUIDE.md).*
