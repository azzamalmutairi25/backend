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
- **حصر التصنيف (fail-closed):** من لا يملك `candidate.view_classified` يرى المرشحين «العاديين» فقط؛ المصنّفون (`secret`/`top_secret`) محجوبون في القوائم والتفاصيل والتجميعات والسجل.
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

### المرشحون (Candidates)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/candidates` | `candidate.view` | قائمة المرشحين (محصورة بالنطاق) |
| GET | `/candidates/stats` | `candidate.view` | إحصاءات مطابقة لحصر القائمة |
| POST | `/candidates` | `candidate.create` | إضافة مرشح (+ دورة تقييم) |
| POST | `/candidates/import` · `/import/candidates` | `candidate.create` | استيراد جماعي |
| GET | `/candidates/export` | `candidate.view` | تصدير القائمة |
| GET | `/candidates/{id}` | `candidate.view` | تفاصيل مرشح |
| PUT | `/candidates/{id}` | `candidate.edit` | تعديل |
| DELETE | `/candidates/{id}` | `candidate.edit` | حذف |
| POST | `/candidates/{id}/approve` | `candidate.edit` | اعتماد للتقييم |
| PATCH | `/candidates/{id}/classify` | `candidate.view_classified` | تغيير تصنيف السرّية |
| GET | `/candidates/{id}/assessments` | `candidate.view` | دورات المرشح |
| GET | `/candidates/{id}/journey` | `candidate.journey` | رحلة المرشح |
| POST | `/candidates/{id}/reassess` | `candidate.edit` | دورة تقييم جديدة |
| GET | `/candidates/{id}/history` | `audit.view` | سجل تدقيق المرشح |
| GET | `/candidates/{id}/interviewers` | `schedule.manage` | مستشارو المقابلة المؤهّلون |
| GET | `/candidates/cards` | `candidate.view` | بطاقات المشاركين للطباعة |

### طلبات تحديث بيانات المرشحين (Update Requests)
> يرفعها المستخدم الخارجي حين يجد المرشّح مسجّلاً مسبقاً — الكتابة فوق سجلٍّ قائم ممنوعة من الخارج.

| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| POST | `/candidate-update-requests` | `candidate.update_request` (٣٠/دقيقة) | رفع طلب تحديث |
| GET | `/candidate-update-requests/mine` | `candidate.update_request` | متابعة طلباتي وحالتها |
| GET | `/candidate-update-requests` | `candidate.update_approve` | الطلبات الواردة |
| GET | `/candidate-update-requests/{id}` | `candidate.update_approve` | تفاصيل طلب |
| POST | `/candidate-update-requests/{id}/approve` | `candidate.update_approve` | اعتماد (يُطبَّق على السجل) |
| POST | `/candidate-update-requests/{id}/reject` | `candidate.update_approve` | رفض بسبب |

### استقبال الموظفين (Reception)
> مسار المرشّح من باب المركز إلى جدول المقابلات — **صلاحية لكل مرحلة**، ولا تُتخطّى مرحلة.

| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/reception` | `reception.view` | كشف اليوم + مهامّي (يتشكّل بالصلاحية) — `?date`، `?q` |
| POST | `/reception/arrive` | `reception.record` | تسجيل وصول (وقت تلقائي) |
| PATCH | `/reception/visits/{id}/arrival` | `reception.record` | تعديل وقت الوصول (`HH:MM`) |
| POST | `/reception/visits/{id}/sign` | `reception.record` (٦٠/دقيقة) | توقيع المرشح وإقراره — PNG بترميز `data:` ≤٤٠٠ك محرف |
| GET | `/reception/visits/{id}/cv` | `reception.view` + (`reception.record` أو `candidate.cv_view`) | سيرة من أمامك اليوم |
| GET | `/reception/evaluators` | `reception.assign` | **من يستطيع الاستلام فعلاً** — `?activity`، `?sectorId` |
| POST | `/reception/visits/{id}/assign` | `reception.assign` | توزيع على `interview`/`discussion`/`measurement` (بعد التوقيع) |
| DELETE | `/reception/assignments/{id}` | `reception.assign` | سحب إسناد |
| GET | `/reception/assignments/{id}/cv` | `reception.decide` | **سيرة بالرمز — بلا اسم ولا هوية أبداً** (قاعدة إجراء لا صلاحية) |
| POST | `/reception/assignments/{id}/accept` | `reception.decide` | قبول المرشح |
| POST | `/reception/assignments/{id}/reject` | `reception.decide` | ردّه للعمليات بسبب (٣–٥٠٠ حرف) |
| POST | `/reception/visits/{id}/approve` | `reception.approve` | اعتماد البيانات وترحيلها للجدول (يشترط التوقيع) |

### السيرة الذاتية (CV)
| الطريقة | المسار | الصلاحية | الغرض |
|---|---|---|---|
| GET | `/candidates/{id}/cv` | `candidate.cv_view` | عرض السيرة (إدارة) |
| PUT | `/candidates/{id}/cv` | `candidate.edit` | حفظ/تعديل السيرة |
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
| GET | `/schedules` | `schedule.view` | قائمة الجلسات (نافذة متدحرجة + سقف) |
| POST | `/schedules` | `schedule.manage` | جدولة جلسة |
| PUT | `/schedules/{id}` | `schedule.manage` | تعديل (يُبطل الحضور عند تغيّر الموعد) |
| DELETE | `/schedules/{id}` | `schedule.manage` | حذف (يُمنع بعد الحضور) |
| GET | `/schedules/absences/{candidateId}` | `schedule.view` | جلسات غياب قابلة لإعادة الجدولة |
| POST | `/schedules/{id}/reschedule` | `candidate.edit` | إعادة جدولة غياب (مرّة واحدة) |
| GET | `/roster` | `schedule.view` | مجموعتا كشف اليوم (أ/ب) |
| POST · DELETE | `/roster/assign` | `roster.manage` | إسناد/إلغاء إسناد مجموعة |
| GET | `/roster/document` | `schedule.view` | كشف الحضور المطبوع — `?date`، و`&showNationalId=1` لحاملي `candidate.view_names` وحدهم |

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
| GET | `/reports/eligible-candidates` | `report.create` | مرشحون جاهزون لتقرير |
| GET | `/reports/score-preview` | `report.view` | معاينة الدرجات |
| GET | `/reports/competency-gap` | `report.view` | فجوة الكفاءات لمرشح |
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
| GET | `/reports/{id}/document` · `/brief` | `report.view` | مستند HTML للطباعة (مُهرَّب) |

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
| GET | `/analytics/executive` | `analytics.executive` | **اللوحة التنفيذية الكاملة**: مؤشرات بفروقات، خريطة حرارية كفاءة×قطاع، اتجاهات، مقارنة قطاعات، مقارنة فئات قيادية، توزيع جاهزية، رؤى تلقائية. المُعامِل `?months` (٦ افتراضاً) |
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

### الإعدادات (Settings) — `settings.manage`
`GET/PUT /settings/ldap` · `/sms` · `/smtp` · `/distribution` · `/tier` · `/idverify` — قراءة/حفظ.
`POST /settings/{ldap,sms,smtp,idverify}/test` — اختبار تكامل خارجي (٥/دقيقة). `GET /settings/idverify/log` — سجل التحقق.
`GET /sectors` · `PUT /sectors/{id}/prefix` — القطاعات ورموزها.
`GET/PUT /workflow/report` — ترتيب سلسلة الاعتماد وتفعيل مراحلها (`workflow.manage` أو `settings.manage`).
`GET /settings/session-times` · `PUT` — أوقات جلسات اليوم. `GET /setup-status` — ما بقي من خطوات التهيئة على منصّة جديدة.

### التدقيق (Audit) — `audit.view`
`GET /audit/log` — السجل الموحّد (يحجب تفاصيل المرشحين المصنّفين عن غير المصرَّح له). `GET /candidates/{id}/history`.

### البوّابة العامة (Public Portal) — بلا مصادقة، ٢٠/دقيقة
| الطريقة | المسار | الغرض |
|---|---|---|
| POST | `/public/assessment/{token}/verify` | بوّابة العامل الثاني (رقم الهوية) — لا بيانات قبلها |
| POST | `/public/assessment/{token}/confirm` | تأكيد الحضور |
| POST | `/public/assessment/{token}/arrive` | تسجيل الوصول |
| POST | `/public/assessment/{token}/cv` | حفظ السيرة الذاتية (مُدقّقة ومُجهّلة) |

---

*مرجعٌ حيّ — يُحدَّث مع تطوّر الـ API. للتفاصيل الحقلية لكل مسار، انظر المتحكّم المقابل في `app/Http/Controllers/`. وللأمثلة العاملة والوصفات الكاملة: [دليل المستخدم](API_GUIDE.md).*
