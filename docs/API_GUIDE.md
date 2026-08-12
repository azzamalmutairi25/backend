# دليل المستخدم — واجهة منصّة مركز تمكين الكفاءات البرمجية

دليلٌ عملي لمن يبني تكاملاً مع المنصّة: كيف تحصل على رمز، وكيف تُنجز مهمّة كاملة من أوّلها إلى آخرها، وما الذي سيفاجئك إن لم تقرأه.

> **هذا الدليل ليس المرجع.** المرجع — جدول كل مسار وصلاحيته — في [`API.md`](API.md). هنا الطريق لا الخريطة: تقرؤه مرّة قبل أوّل تكامل، ثم تعود إلى المرجع عند كل مسار.

---

## المحتويات

1. [قبل أن تبدأ](#١-قبل-أن-تبدأ)
2. [أوّل خمس دقائق](#٢-أوّل-خمس-دقائق)
3. [ست قواعد تحكم كل مسار](#٣-ست-قواعد-تحكم-كل-مسار)
4. [وصفات كاملة](#٤-وصفات-كاملة)
5. [الأخطاء: ماذا تعني وماذا يفعل عميلك](#٥-الأخطاء-ماذا-تعني-وماذا-يفعل-عميلك)
6. [حدود المعدّل](#٦-حدود-المعدّل)
7. [المستندات المطبوعة](#٧-المستندات-المطبوعة)
8. [أمن التكامل](#٨-أمن-التكامل)
9. [قائمة تحقّق قبل الإنتاج](#٩-قائمة-تحقّق-قبل-الإنتاج)

---

## ١. قبل أن تبدأ

### ما تحتاجه

| | |
|---|---|
| **العنوان** | `https://<مضيف-المنصّة>/api` — المنصّة داخلية، فالعنوان يُسلَّم لك من مشغّل المركز |
| **الحساب** | مستخدم بدور يملك ما تريد فعله. **لا يوجد مفتاح API منفصل** — التكامل يعمل بحساب |
| **الشهادة** | المنصّة تعمل بشهادة من جهة إصدار داخلية. ثبّت شهادة الجذر في مخزن ثقة نظامك، ولا تُعطّل التحقّق |

### أنشئ حساباً للتكامل، لا تستعمل حساب موظّف

اطلب من مدير النظام حساباً باسم النظام المتكامل (مثل `svc_hr_sync`) بدورٍ يحمل **ما تحتاجه وحده**. سببان:

- حساب الموظّف يُعطَّل حين يغادر، فيتوقّف تكاملك في اليوم الذي لا تتوقّعه.
- سجلّ التدقيق يقيّد كل عملية على صاحب الرمز. تكاملٌ يعمل بحساب موظّف يُنسَب إليه فعلٌ لم يفعله.

منذ صارت الصلاحيات تُحرَّر من شاشة **«الأدوار والصلاحيات»**، يستطيع مدير النظام أن ينشئ لك دوراً بصلاحيتين اثنتين إن كان ذلك كل ما تحتاجه.

---

## ٢. أوّل خمس دقائق

### الخطوة ١ — احصل على رمز

```bash
curl -sX POST https://kafaat.local/api/login \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"username":"svc_hr_sync","password":"..."}'
```

```json
{
  "token": "17|abcDEF...",
  "user": {
    "id": 42,
    "username": "svc_hr_sync",
    "fullName": "تكامل الموارد البشرية",
    "role": "SCHEDULER",
    "roleName": "مسؤول الجدولة",
    "mustChangePassword": false,
    "permissions": ["candidate.view", "candidate.create", "..."]
  }
}
```

### الخطوة ٢ — استعمله

```bash
curl -s https://kafaat.local/api/candidates \
  -H 'Authorization: Bearer 17|abcDEF...' \
  -H 'Accept: application/json'
```

### الخطوة ٣ — اقرأ صلاحياتك قبل أن تبني

`user.permissions` في استجابة الدخول (و`GET /me`) هي **ما تستطيعه فعلاً** — الدور زائد أي استثناء فردي عليه. ابنِ عليها لا على اسم الدور: مديرُ النظام قد يسحب صلاحيةً من دورك غداً من الشاشة، فالاسم يبقى والقدرة تتغيّر.

```python
me = get("/me")
if "candidate.create" not in me["permissions"]:
    raise SystemExit("الحساب لا يملك إضافة المرشحين — راجع مدير النظام")
```

### عميل جاهز (Python)

```python
import requests

class Kafaat:
    def __init__(self, base, username, password, verify="/etc/ssl/certs/kafaat-ca.pem"):
        self.base, self.s = base.rstrip("/"), requests.Session()
        self.s.verify = verify
        self.s.headers["Accept"] = "application/json"
        r = self.s.post(f"{self.base}/login",
                        json={"username": username, "password": password})
        r.raise_for_status()
        d = r.json()
        self.s.headers["Authorization"] = f"Bearer {d['token']}"
        self.permissions = d["user"]["permissions"]

    def _go(self, method, path, **kw):
        r = self.s.request(method, f"{self.base}{path}", **kw)
        if r.status_code == 429:
            raise RuntimeError("تجاوزت حدّ المعدّل — انتظر ثم أعد المحاولة")
        if not r.ok:
            raise RuntimeError(f"{r.status_code}: {r.text[:300]}")
        return r.json()

    def get(self, path, **kw):  return self._go("GET", path, **kw)
    def post(self, path, **kw): return self._go("POST", path, **kw)

    def close(self):
        self.s.post(f"{self.base}/logout")   # لا تترك رمزاً حيّاً
```

### PowerShell

```powershell
$body  = @{ username = 'svc_hr_sync'; password = '...' } | ConvertTo-Json
$login = Invoke-RestMethod -Method Post -Uri 'https://kafaat.local/api/login' `
                           -ContentType 'application/json' -Body $body
$h = @{ Authorization = "Bearer $($login.token)"; Accept = 'application/json' }

Invoke-RestMethod -Uri 'https://kafaat.local/api/candidates' -Headers $h
```

---

## ٣. ست قواعد تحكم كل مسار

اقرأها الآن. كلٌّ منها سيربكك مرّة إن لم تفعل.

### ١. الرمز لا الاسم

المنصّة تعمل بـ**رمز المشارك** (`participantCode`) لا بالاسم. قائمة المرشحين **لا تحوي أسماء لأي دور بلا استثناء** — حتى من يملك صلاحية الأسماء. والبحث فيها بالرمز لا بالاسم.

الاسم يظهر في مسارات التفصيل لحاملي `candidate.view_names` وحدهم:

| المسار | الاسم |
|---|---|
| `GET /candidates` | ✗ لا أحد |
| `GET /candidates/{id}` | ✓ لحاملي الصلاحية (`name`, `nationalId`, `mobile`, `email` — وإلا `null`) |
| `GET /candidates/{id}/cv` | ✓ لحاملي الصلاحية (وإلا **تُشطَب** المُعرِّفات من نصّ السيرة) |
| `GET /candidates/export` | ✓ لحاملي الصلاحية |
| `GET /reception/assignments/{id}/cv` | ✗ **أبداً**، مهما كانت صلاحيتك |

> السطر الأخير ليس صلاحيةً بل قاعدة إجراء: المقيّم يقرأ سيرة من سيقابله بلا اسم ولا هوية، ولو مُنح `candidate.view_names` صراحةً. حياد التقييم لا يُفوَّض.

### ٢. ٤٠٤ لا تعني «غير موجود»

المعرّف لا يكشف الوجود أبداً. مورد خارج قطاعك أو فوق تصنيفك الأمني يعود **٤٠٤**، لا ٤٠٣.

- **٤٠٣** = «تعرف أنه موجود، ولا تملك هذه العملية»
- **٤٠٤** = «لا وجود له **بالنسبة لك**» — قد يكون موجوداً

لا تبنِ منطق «أنشئه إن لم يوجد» على ٤٠٤: قد تُنشئ نسخةً ثانية من سجلٍّ لا تراه.

### ٣. الحصر يسبق فلترك

المستخدم المحصور بقطاع لا يخرج منه، وتمريرك `?sectorId=` لقطاع آخر لا يوسّع شيئاً — يضيّق فقط. والمرشّحون المصنّفون (`secret` / `top_secret`) محجوبون عمّن لا يملك `candidate.view_classified` في **القوائم والتفاصيل والتجميعات والإحصاءات والتصدير والسجل** معاً، لا في القائمة وحدها.

فإن رأيت عدداً في `/candidates/stats` لا يطابق عدّاً من مصدر آخر، فالفرق تصنيفٌ أو قطاعٌ لا خلل.

### ٤. الصلاحيات بيانات تتغيّر بلا نشر

مدير النظام يحرّر صلاحيات أي دور من الشاشة، ويسري التعديل فوراً على كل حامليه. عميلٌ يخزّن `permissions` عند بدء التشغيل ويعيش أياماً سيعمل على صورة قديمة. أعد قراءة `GET /me` عند بدء كل دورة تشغيل، وعامِل ٤٠٣ مفاجئاً على أنه «سُحبت صلاحية» لا «خلل».

### ٥. الترقيم بطلبٍ صريح

`GET /candidates` يُرقَّم ويُفرَز على الخادم — **إن طلبتَ**:

```bash
curl -s "https://kafaat.local/api/candidates?page=2&perPage=50&sort=sector&dir=desc" \
  -H "Authorization: Bearer $TOKEN"
```

```json
{ "candidates": [ … ],
  "meta": { "total": 1240, "shown": 50, "page": 2, "perPage": 50,
            "lastPage": 25, "sort": "sector", "dir": "desc", "truncated": false } }
```

- **بلا `page` ولا `perPage` تعود القائمة كاملةً كما كانت** — عميلك القديم يعمل بلا تعديل، وسقفٌ صلب (٥٠٠٠) يحميك ويُعلن نفسه في `meta.truncated`.
- **`total` يعكس فلاترك وحصرك** لا الجدول كلّه. صفحةٌ تجاوزت الآخر تُشدّ إلى الأخيرة، فاقرأ `meta.page` لا ما أرسلتَه.
- **`sort` من قائمة مغلقة** تختلف بكل مسار (انظر [`API.md`](API.md)). غيرها ٤٢٢.
- **لا تُرقّم للمزامنة الكاملة.** صفحةٌ تلو صفحة على قائمةٍ تتغيّر أثناء مرورك تُفوّت صفوفاً وتكرّر أخرى. للمزامنة استعمل `/candidates/export` مرّةً واحدة.

**أربع قوائم تقبلها**: `/candidates` و`/reports` و`/evaluations` و`/users`.
انتبه لاتجاهها الافتراضي: `/reports` و`/evaluations` **تنازليتان** (الأحدث أولاً)، والأُخريان تصاعديتان.

و`/schedules` بنافذةٍ متدحرجة وسقفٍ صلب (٢٠٠٠ صفّ)، و`/audit/log` و`/notifications` مُرقَّمان بنمطهما الخاص.

### ٦. الوقت بتوقيت الرياض

التواريخ `YYYY-MM-DD` والأوقات `HH:MM` بتوقيت المملكة. لا تُرسل ISO بمنطقة زمنية في حقول التاريخ والوقت — أرسل ما يراه المستخدم في المركز.

---

## ٤. وصفات كاملة

### الوصفة ١ — أضف مرشّحاً من نظام خارجي

**تحتاج:** `candidate.create`

```bash
curl -sX POST https://kafaat.local/api/candidates \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "nationalId": "1012345674",
    "fullName": "فهد بن عبدالعزيز الشمري",
    "mobile": "0501234567",
    "sectorId": 6,
    "rankLabel": "مدير عام",
    "assessmentType": "comprehensive",
    "classification": "normal"
  }'
```

| الحقل | القاعدة |
|---|---|
| `nationalId` | ١٠ أرقام سعودية صحيحة (خانة تحقّق Luhn). رقمٌ لا يمرّ ⇒ ٤٢٢ |
| `mobile` | `05xxxxxxxx` بالضبط، أو اتركه |
| `sectorId` | من `GET /sectors` — لا تُثبّت رقماً في شيفرتك |
| `rankLabel` | من `GET /ranks` — **الرتبة تقود تصنيف الفئة القيادية**، فنصٌّ حرّ لا يطابق قائمة المركز يُدخِل المرشّح في الفئة الخطأ |
| `classification` | `normal` افتراضاً. رفعها فوق ذلك يحجب المرشّح عمّن لا يملك التصريح |

الاستجابة تحمل `participantCode` — **احفظه**. هو مفتاحك في كل شاشة ومستند بعد ذلك.

**إن كان المرشّح مسجّلاً مسبقاً** يعود ٤٢٢. لا تُعِد المحاولة: ارفع **طلب تحديث** (الوصفة التالية).

### الوصفة ٢ — حدّث بيانات مرشّح مسجّل

الكتابة فوق سجلٍّ قائم من نظام خارجي ممنوعة. المسار المشروع: طلبٌ يبتّ فيه صاحب صلاحية.

```
POST /candidate-update-requests        ← ترفع الطلب        (candidate.update_request)
GET  /candidate-update-requests/mine   ← تتابع حالته        (candidate.update_request)
                    ↓
        يبتّ فيه مسؤول الجدولة من الشاشة              (candidate.update_approve)
                    ↓
        approved ⇒ طُبِّق على السجل  |  rejected ⇒ مع السبب
```

تابع بـ`/mine` — لا تعيد الرفع لأن الطلب لم يُطبَّق بعد.

### الوصفة ٣ — اقرأ جدول اليوم

**تحتاج:** `schedule.view`

```bash
curl -s "https://kafaat.local/api/schedules?date=2026-08-06" -H "Authorization: Bearer $TOKEN"
curl -s "https://kafaat.local/api/attendance/today"          -H "Authorization: Bearer $TOKEN"
```

`/schedules` يعمل بنافذة متدحرجة وسقف عدد. لتقرير تاريخي واسع استعمل `/reports/export` أو `/analytics/*` — لا تُصفّح الجدول ألف صفحة.

### الوصفة ٤ — مسار الاستقبال كاملاً

ستّ مراحل، لكلٍّ صلاحيتها. أي محاولة لتخطّي مرحلة تعود ٤٢٢ بسبب مكتوب بالعربية.

```
① وصول          POST   /reception/arrive                       reception.record
   {"assessmentId": 88}                                          → visit.id
   الوقت يُملأ تلقائياً؛ لتعديله:
   PATCH /reception/visits/{id}/arrival   {"arrivedAt":"08:35"}

② توقيع وإقرار  POST   /reception/visits/{id}/sign             reception.record
   {"signature":"data:image/png;base64,iVBOR...", "attested":true}
   ⚠ لا توزيع قبل التوقيع — ٤٢٢

③ توزيع         GET    /reception/evaluators?activity=interview  reception.assign
   POST   /reception/visits/{id}/assign                          reception.assign
   {"activity":"interview", "evaluatorId": 17}
   activity ∈ interview | discussion | measurement
   → إشعارٌ للمقيّم المُسنَد إليه

④ قرار المقيّم  GET    /reception/assignments/{id}/cv           reception.decide
   ⚠ سيرة بالرمز — بلا اسم ولا هوية ولا جوال، مهما كانت صلاحيتك
   POST   /reception/assignments/{id}/accept                     reception.decide
   POST   /reception/assignments/{id}/reject  {"reason":"..."}   reception.decide
   الردّ يعود لمسؤول العمليات ليعيد الإسناد (③)

⑤ اعتماد        POST   /reception/visits/{id}/approve           reception.approve
   ⚠ ٤٢٢ إن لم يوقّع المرشّح

⑥ ترحيل         الجلسات تُنشأ في الجدول تلقائياً بعد الاعتماد
```

**الفخّان الشائعان:**
- `GET /reception/evaluators` يعيد **من يستطيع الاستلام فعلاً** — لا كل من يحمل الدور. مرّر `sectorId` لتستبعد المحصورين بقطاع آخر، وإلا رفض `/assign` اختيارك بـ٤٢٢ بعد أن عرضته أنت على المستخدم.
- التوقيع صورة PNG بترميز `data:` وحدّه ٤٠٠ ألف محرف. أي صيغة أخرى ⇒ ٤٢٢.

### الوصفة ٥ — من التقييم إلى تقرير معتمد

```
POST /evaluations/start           evaluation.input
POST /evaluations/{id}/scores     evaluation.input
POST /evaluations/{id}/submit     evaluation.input
POST /evaluations/{id}/approve    evaluation.approve      (أو /return للإرجاع)
                 ↓
GET  /reports/eligible-candidates report.create           ← من اكتمل تقييمه
POST /reports  {"candidateId": 12}   report.create
PUT  /reports/{id}                report.create           ← تحرير ثم إرسال
POST /reports/{id}/approve        صلاحية المرحلة          ← سلسلة الاعتماد
```

سلسلة الاعتماد **قابلة للضبط** (`GET /workflow/report`) — لا تُثبّت مراحلها في شيفرتك. اقرأ حالة التقرير من `GET /reports/{id}` وتصرّف بناءً عليها.

**٤٠٩ هنا يعني: اعتمدها غيرك قبلك.** أعد القراءة، لا تُعد الإرسال — إعادة الإرسال قد تعتمد مرحلةً أبعد ممّا تظنّ.

### الوصفة ٦ — اسحب مؤشرات للوحة خارجية

```bash
curl -s "https://kafaat.local/api/analytics/executive?months=6" -H "Authorization: Bearer $TOKEN"
```

`analytics.executive` — مؤشرات بفروقاتها، خريطة كفاءة×قطاع، الاتجاهات، مقارنات القطاعات والفئات، توزيع الجاهزية.

| تريد | استعمل | الصلاحية |
|---|---|---|
| نظرة تنفيذية كاملة | `/analytics/executive` | `analytics.executive` |
| موجزاً خفيفاً | `/analytics/dashboard` | `analytics.view` |
| يوم المركز | `/daily-report?date=…` | `analytics.daily_report` |
| حسب القطاع / الفجوات / الاتجاهات | `/analytics/by-sector` · `/competency-gaps` · `/trends` | `analytics.view` |

كلّها **تحترم تصنيفك**: حسابٌ بلا تصريح مصنّف يرى أرقاماً لا تشمل المصنّفين — وهذا صحيح لا ناقص. وحّد الحساب بين لوحاتك وإلا اختلفت الأرقام بين شاشتين.

### الوصفة ٧ — أنشئ دوراً واضبط صلاحياته

**تحتاج:** `user.manage`

```bash
curl -s https://kafaat.local/api/roles -H "Authorization: Bearer $TOKEN"
curl -s https://kafaat.local/api/roles/5/permissions -H "Authorization: Bearer $TOKEN"

curl -sX PUT https://kafaat.local/api/roles/5/permissions \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"permissions":["candidate.view","report.view","report.create"]}'
```

**استبدالٌ لا إضافة:** ما لا تُرسله يُسحَب. اقرأ الحالية، عدّل عليها، أرسل الكل.

أربعة حرّاس ترفض بـ٤٢٢، ولن تلتفّ عليها:

| الحارس | لماذا |
|---|---|
| دور `ADMIN` لا يُعدَّل ولا يُحذف | سحب إدارة المستخدمين منه يُغلق باب الإدارة بلا رجعة |
| لا تعدّل الدور الذي تحمله أنت | وإلا منحتَ نفسك كل شيء |
| لا تمنح صلاحيةً لا تملكها | سقفٌ لا منعٌ مطلق — والمحاولة تُقيَّد في التدقيق |
| `user.manage` · `settings.manage` · `audit.view` لا تُمنح من هنا | سلطات نظام تُدار بالدور وحده |

`POST /roles/{id}/reset` يُعيد الدور إلى افتراضي المنصّة.

---

## ٥. الأخطاء: ماذا تعني وماذا يفعل عميلك

| الحالة | المعنى الحقيقي | افعل |
|---|---|---|
| **٤٠١** | الرمز مفقود أو أُبطل (خروج، أو تغيير كلمة مرور) | سجّل الدخول مرّة واحدة وأعد الطلب. لا تُعِد الدخول في حلقة |
| **٤٠٣** | مُصادَق بلا صلاحية | **لا تُعِد المحاولة** — أعد قراءة `/me`، وإن نقصت الصلاحية فأبلغ لا تدور |
| **٤٠٤** | غير موجود **أو خارج نطاقك** | لا تُنشئ بديلاً — قد يكون موجوداً ولا تراه |
| **٤٠٩** | سبقك غيرك إلى الحالة | أعد القراءة ثم قرّر. لا تُعد الإرسال آلياً |
| **٤١٣** | حمولة أكبر من المسموح | جزّئ الاستيراد |
| **٤٢٢** | مدخلات غير صالحة أو حالة غير مسموحة | **اقرأ الرسالة** — مكتوبة بالعربية وتقول ما ينقص بالضبط |
| **٤٢٩** | تجاوزت حدّ المعدّل | تراجُعٌ أُسّي. المهلة دقيقة |
| **٥٠٠** | خلل في المنصّة | لا تُعِد فوراً؛ أبلغ المشغّل بالوقت والمسار |

شكل الخطأ:

```json
{ "error": "لم يوقّع المرشح ولم يُقرّ بصحّة بياناته" }
```

وفي أخطاء التحقّق (٤٢٢):

```json
{ "message": "...", "errors": { "mobile": ["صيغة رقم الجوال غير صحيحة"] } }
```

اعرض `error` أو `errors` كما هي على مستخدمك — هي مكتوبة له لا لك.

---

## ٦. حدود المعدّل

| المسار | الحدّ |
|---|---|
| `POST /login` | ١٠ / دقيقة لكل IP |
| البوّابة العامة `/public/assessment/*` | ٢٠ / دقيقة |
| `POST /candidate-update-requests` | ٣٠ / دقيقة |
| `POST /reception/visits/{id}/sign` | ٦٠ / دقيقة |
| `POST /settings/{ldap,sms,smtp,idverify}/test` | ٥ / دقيقة |

الباقي بلا حدّ صريح، وليس دعوةً للتوازي: **مزامنة واحدة متسلسلة أفضل من عشرين خيطاً**. المنصّة تخدم موظّفي المركز أثناء عملهم.

---

## ٧. المستندات المطبوعة

بعض المسارات تعيد **HTML جاهزاً للطباعة** لا JSON:

```
GET /reports/{id}/document        التقرير الكامل
GET /reports/{id}/brief           الموجز
GET /candidates/{id}/cv/document  نموذج السيرة
GET /roster/document?date=…       كشف الحضور  (+ &showNationalId=1)
GET /daily-report/document        التقرير اليومي
```

اطبعها من المتصفّح (`Ctrl+P` → PDF). محتواها مُهرَّب ضدّ الحقن، ولا تحوي أسماء لمن لا يملك صلاحيتها — و`showNationalId=1` من غير حاملها يُتجاهَل بصمت لا يُخطئ.

---

## ٨. أمن التكامل

- **لا تكتب كلمة المرور في الشيفرة.** متغيّر بيئة أو خزنة أسرار، والملف بصلاحية ٦٠٠.
- **لا تخزّن الرمز على القرص.** يعيش في الذاكرة، و`POST /logout` عند الانتهاء.
- **رمزٌ واحد لكل تشغيل** لا رمز لكل طلب — كل دخول يُنشئ رمزاً جديداً، وتركُها حيّة يُراكم أرصدةً صالحة.
- **لا تُعطّل التحقّق من الشهادة** (`verify=False`, `-k`, `TrustAllCerts`). ثبّت شهادة الجذر الداخلية.
- **لا تُسجّل الحمولات في سجلّك.** فيها أسماء وأرقام هوية — وسجلّك ليس محكوماً بضوابط المنصّة.
- **كل عملية تُقيَّد** في سجل التدقيق باسم صاحب الرمز، ومنها محاولاتك المرفوضة. هذا في مصلحتك: تكاملٌ يُثبِت ما فعل.
- **إعادة تعيين كلمة المرور من الإدارة تُبطل كل رموز الحساب** (وكذلك تعطيله أو تغيير دوره). إن بدأ تكاملك يعود ٤٠١ فجأةً بلا سبب، فابدأ من هنا قبل أن تبحث في شيفرتك.

---

## ٩. قائمة تحقّق قبل الإنتاج

- [ ] حساب خدمة مستقلّ بدورٍ يحمل ما يلزم وحده — لا حساب موظّف
- [ ] كلمة المرور من خزنة أسرار لا من الشيفرة
- [ ] شهادة الجذر مثبّتة، والتحقّق **مفعّل**
- [ ] `GET /me` عند بدء التشغيل، والصلاحيات مفحوصة قبل الاعتماد عليها
- [ ] ٤٠٣ و٤٠٤ و٤٠٩ لكلٍّ تصرّف مختلف — لا «أعد المحاولة» للجميع
- [ ] تراجُع أُسّي على ٤٢٩، ولا حلقة إعادة على ٤٠١
- [ ] رسائل ٤٢٢ العربية تصل إلى المستخدم كما هي
- [ ] لا أسماء ولا أرقام هوية في سجلّك
- [ ] `POST /logout` في مسار الإنهاء **وفي مسار الخطأ**
- [ ] `sectorId` يُقرأ من `GET /sectors` لا مثبّتاً في الشيفرة
- [ ] مسار الاستقبال مُختبَر بمرشّح تجريبي من الوصول إلى الاعتماد

---

*للمسارات وصلاحياتها كاملةً: [`API.md`](API.md) — ولبنية المنصّة: [`ARCHITECTURE.md`](ARCHITECTURE.md).*
