# دليل تنصيب الجذر الداخلي «Tamkeen Alkafaat Internal Root CA» على أجهزة المستخدمين

> الغرض: إزالة تحذير المتصفّح نهائياً عند فتح منصّة كفاءات.
> الرابط المعتمد للمنصّة صار `https://moitp.gov.sa/`، والعنوان `https://172.16.0.73/` يبقى عاملاً.
> ⚠ الشهادة المنشورة اليوم **لا تغطّي `moitp.gov.sa`** — راجع القسم ٩ قبل تعميم الرابط الجديد.
> الخادم يقدّم الشهادة النهائية وحدها، وهي موقّعة مباشرة من الجذر الداخلي؛ لذلك **تنصيب الجذر على الجهاز هو الإجراء الوحيد المطلوب** — لا حاجة لأي شهادة وسيطة.

> **لماذا الوثوق بهذا الجذر محدود الأثر:** الجذر مقيَّد بـ`nameConstraints`،
> فلا يقبل منه المتصفّح إلا شهادات نطاقات المنصّة (`internal.gov.sa`،
> `kafaat.local`، والشبكة `172.16.0.0/16`). لو وقّع هذا الجذر شهادةً لأي موقع
> آخر — بنكاً أو بريداً — **رفضها المتصفّح**. وهذا يفصل بين تنصيب جذرٍ لغرضٍ
> محدّد وتنصيب جذرٍ يملك الإنترنت كلّه على جهازك.

---

## 0. بطاقة تعريف الجذر — طابِق قبل أن تثق

**لا تُنصِّب هذا الملف قبل مطابقة بصمة SHA-256 عبر قناة مستقلة عن القناة التي وصلك بها الملف** (اتصال هاتفي بمسؤول النظام، أو ورقة مطبوعة معتمدة). تنصيب جذر خاطئ يمنح مُصدِره القدرة على انتحال أي موقع تزوره — وهو أخطر بكثير من التحذير الذي تحاول إزالته.

| الحقل | القيمة |
|---|---|
| الاسم (CN) | `Tamkeen Alkafaat Internal Root CA` |
| المؤسسة (O) | `Tamkeen Alkafaat Center` |
| الدولة (C) | `SA` |
| النوع | موقّع ذاتياً (Subject = Issuer) |
| المفتاح | RSA 4096، توقيع sha256WithRSAEncryption |
| الصلاحية | من 2026-08-05 23:48:07 UTC إلى 2036-08-02 23:48:07 UTC |
| الرقم التسلسلي | `F1C14423C617500C` |
| Basic Constraints | `CA:TRUE, pathlen:0` (critical) |
| Key Usage | `Certificate Sign, CRL Sign` (critical) |
| **Name Constraints** | **`critical` — مسموح: `DNS:internal.gov.sa`، `DNS:kafaat.local`، `IP:172.16.0.0/16` فقط** |
| Subject Key Identifier | `8A:A2:29:28:E6:13:E2:D2:55:60:DF:9A:1B:5F:92:A6:86:EB:11:32` |

### البصمة المرجعية

```
SHA-256:
98:43:AA:93:6D:B5:82:A2:E8:24:EF:1D:0B:B2:F0:C8:48:EC:08:71:44:2A:DF:83:FD:7D:9C:92:70:11:68:AE

بصيغة متصلة (كما تعرضها بعض الأدوات):
9843aa936db582a2e824ef1d0bb2f0c848ec0871442adf83fd7d9c92701168ae

SHA-1 (تعرضها PowerShell في حقل Thumbprint):
5F1E8B484247CF8FA151F9EE881DFD61D6D62DCC
```

> ⚠ **المزلق الأكثر شيوعاً في المطابقة:** لا تحسب بصمة الملف بـ`shasum -a 256 ca.crt` أو `Get-FileHash ca.crt` على ملف PEM النصّي — ستحصل على قيمة مختلفة تماماً (`قيمة أخرى…7ae1` في حالتنا) لأنك تجزّئ النص المُرمَّز بـBase64 لا جسم الشهادة. البصمة المعتمدة هي تجزئة جسم الشهادة بصيغة DER. استخدم دائماً `openssl x509 -fingerprint -sha256` أو `certutil -dump` أو واجهة عرض الشهادة في النظام.

---

## 1. الحصول على ملف الجذر وتحضيره للتوزيع

من جهاز إداري له وصول إلى الخادم:

```bash
# جلب الجذر (النسخة العامة فقط — المفتاح الخاص ca.key لا يغادر الخادم أبداً)
ssh tamkeenadmin@172.16.0.73 \
  'SUDO_ASKPASS=/tmp/.ka sudo -A cat /etc/ssl/kafaat/ca.crt' > kafaat-ca.crt

# تحقّق من البصمة فوراً بعد الجلب
openssl x509 -in kafaat-ca.crt -noout -fingerprint -sha256 -subject -dates

# نسخة DER لبعض المنصّات (أندرويد خصوصاً) وبامتداد .cer المألوف على ويندوز
openssl x509 -in kafaat-ca.crt -outform DER -out kafaat-ca.der
cp kafaat-ca.crt kafaat-ca.cer
```

كلا الصيغتين (PEM بامتداد `.crt`/`.cer` وDER) مقبولتان في معالج الاستيراد على ويندوز وmacOS. وزّع الملف عبر مشاركة شبكية داخلية أو SYSVOL أو نظام إدارة الأجهزة — لا عبر البريد الخارجي.

---

## 2. ويندوز — النشر المركزي عبر Group Policy (الأسلوب المعتمد في بيئة النطاق)

هذا هو المسار الصحيح لوزارة على نطاق Active Directory: تنصيب واحد يغطي جميع الأجهزة دون تدخّل المستخدم.

### 2.1 تجهيز الملف

ضع `kafaat-ca.crt` في موقع تقرؤه حسابات الأجهزة، مثل:
`\\<domain>\SYSVOL\<domain>\scripts\kafaat-ca.crt`

### 2.2 إنشاء السياسة وربطها

1. افتح **Group Policy Management** (`gpmc.msc`) على وحدة تحكّم النطاق أو جهاز عليه RSAT.
2. وسّع: `Forest: <forest>` → `Domains` → `<your-domain>`.
3. انقر بالزر الأيمن على الوحدة التنظيمية (OU) التي تحوي أجهزة المستخدمين ← **Create a GPO in this domain, and Link it here…**
4. سمِّها مثلاً: `Kafaat Internal Root CA — Trusted Root`.
5. انقر بالزر الأيمن على السياسة الجديدة ← **Edit**.

### 2.3 المسار الدقيق داخل محرّر السياسات

```
Computer Configuration
  └─ Policies
      └─ Windows Settings
          └─ Security Settings
              └─ Public Key Policies
                  └─ Trusted Root Certification Authorities
```

6. انقر بالزر الأيمن في اللوحة اليمنى (الفارغة) ← **Import…**
7. في معالج **Certificate Import Wizard**: **Next** ← **Browse…** ← اختر `\\<domain>\SYSVOL\<domain>\scripts\kafaat-ca.crt` ← **Next**.
8. في خطوة المخزن تأكّد من: **Place all certificates in the following store: Trusted Root Certification Authorities** ← **Next** ← **Finish**.
9. أغلق المحرّر. تحقّق من نطاق التطبيق في تبويب **Scope** (Security Filtering يجب أن يشمل `Domain Computers` أو مجموعة الأجهزة المستهدفة).

### 2.4 تطبيق السياسة والتحقّق منها

على جهاز عميل:

```cmd
gpupdate /force
```

(التحديث التلقائي يقع خلال 90 دقيقة + عشوائية حتى 30 دقيقة؛ لا حاجة لإعادة تشغيل.)

ثم تأكّد أن الجذر وصل فعلاً:

```cmd
certutil -store -enterprise Root "Tamkeen Alkafaat Internal Root CA"
```

أو تقرير شامل: `gpresult /h %USERPROFILE%\Desktop\gp.html`

> **بديل للأجهزة المُدارة غير المنضمّة للنطاق (Intune / Microsoft Endpoint Manager):** أنشئ ملف تعريف من نوع **Trusted certificate** (المنصّة: Windows 10/11)، ارفع `kafaat-ca.cer`، واختر مخزن الوجهة **Computer certificate store – Root**.

---

## 3. ويندوز — جهاز مفرد (خارج النطاق)

### 3.1 عبر سطر الأوامر — الأسرع والأدق

افتح **Command Prompt أو PowerShell كمسؤول (Run as administrator)** — بدون الرفع سيذهب الجذر إلى مخزن المستخدم فقط ولن يعمّ الجهاز:

```cmd
certutil -addstore -f Root C:\Temp\kafaat-ca.crt
```

- `Root` = مخزن **Trusted Root Certification Authorities** على مستوى الجهاز.
- `-f` = استبدال أي نسخة سابقة.
- لمخزن المستخدم الحالي فقط (لا يُنصح به): `certutil -user -addstore -f Root C:\Temp\kafaat-ca.crt`

**تحقّق من البصمة قبل التنصيب** بعرض الملف:

```cmd
certutil -dump C:\Temp\kafaat-ca.crt
```

ابحث في المخرجات عن السطر `Cert Hash(sha256):` وطابِقه مع البصمة في القسم 0. (هذا الأمر يعمل مع PEM وDER معاً — على خلاف `Get-FileHash` الذي يجزّئ الملف النصّي.)

**التراجع عند الحاجة:**

```cmd
certutil -delstore Root "Tamkeen Alkafaat Internal Root CA"
```

### 3.2 عبر الواجهة الرسومية

1. `Win + R` ← اكتب **`certlm.msc`** ← Enter (وليس `certmgr.msc` — ذاك لمخزن المستخدم فقط).
2. وسّع **Trusted Root Certification Authorities** ← **Certificates**.
3. انقر بالزر الأيمن ← **All Tasks** ← **Import…**
4. **Next** ← **Browse…** ← اختر `kafaat-ca.crt` (قد تحتاج تغيير مشارك الملفات إلى *All Files*) ← **Next**.
5. اختر **Place all certificates in the following store** ← تأكّد أنه **Trusted Root Certification Authorities** ← **Next** ← **Finish**.
6. ستظهر نافذة تحذير أمني تسألك عن تنصيب جذر ببصمة معيّنة — **اقرأ البصمة المعروضة وطابِقها** ثم **Yes**.

---

## 4. macOS

### 4.1 عبر الواجهة الرسومية (Keychain Access)

> ⚠ الفخّ الشائع: الاستيراد إلى سلسلة **login** بدل **System**. الاستيراد إلى `login` يجعل الشهادة موجودة لكنها لا تُعتمد على مستوى النظام، والتحذير يبقى لبقية المستخدمين والخدمات.

1. افتح **Keychain Access** (`⌘ + Space` ← اكتب Keychain Access).
2. من الشريط الجانبي اختر سلسلة **System** (وليس **login**).
3. القائمة **File** ← **Import Items…** ← اختر `kafaat-ca.crt` ← تأكّد أن حقل *Destination Keychain* = **System** ← **Open** ← أدخل كلمة مرور المسؤول.
4. **خطوة الثقة (إلزامية):** انقر نقراً مزدوجاً على الشهادة `Tamkeen Alkafaat Internal Root CA` في القائمة ← وسّع قسم **Trust** ← اضبط **When using this certificate:** على **Always Trust** ← أغلق النافذة ← أدخل كلمة مرور المسؤول للتأكيد.
5. يجب أن تتحوّل أيقونة الشهادة إلى علامة `+` زرقاء مع عبارة *This certificate is marked as trusted for all users*.

### 4.2 المكافئ عبر سطر الأوامر

```bash
sudo security add-trusted-cert -d -r trustRoot \
  -k /Library/Keychains/System.keychain \
  /path/to/kafaat-ca.crt
```

- `-d` = إضافة إعدادات الثقة في نطاق المسؤول (لجميع المستخدمين).
- `-r trustRoot` = اعتماده جذراً موثوقاً.
- `-k /Library/Keychains/System.keychain` = سلسلة النظام (لا `~/Library/Keychains/login.keychain-db`).

**التحقّق:**

```bash
# وجود الشهادة وبصمتها في سلسلة النظام
security find-certificate -c "Tamkeen Alkafaat Internal Root CA" -a -Z \
  /Library/Keychains/System.keychain

# إعدادات الثقة المسجّلة في نطاق المسؤول
sudo security dump-trust-settings -d
```

**التراجع:**

```bash
sudo security delete-certificate -c "Tamkeen Alkafaat Internal Root CA" \
  /Library/Keychains/System.keychain
```

> Safari وChrome وEdge على macOS تعتمد مخزن النظام، فتُصبح موثوقة فور تنفيذ ما سبق. **Firefox لا يعتمده** — راجع القسم 6.

---

## 5. iOS / iPadOS — التنصيب ثم تفعيل الثقة (الخطوة المنسيّة)

على iOS التنصيب وحده **لا يكفي**. يُخزَّن الجذر بعد التنصيب لكنه يبقى غير موثوق لمصادقة خوادم TLS حتى تُفعِّله يدوياً من شاشة منفصلة تماماً. هذه هي الخطوة التي يغفل عنها الجميع فيظنّون أن التنصيب فشل.

### المرحلة الأولى: إيصال الملف وتنصيبه

1. أرسل `kafaat-ca.crt` إلى الجهاز عبر البريد الداخلي أو AirDrop أو رابط تنزيل داخلي.
   - **افتح الرابط بمتصفّح Safari تحديداً** — تنزيلات Chrome/Firefox على iOS لا تُشغّل مسار ملفات التعريف.
2. ستظهر رسالة: *This website is trying to download a configuration profile* ← **Allow**.
3. اذهب إلى **Settings** — سيظهر بند **Profile Downloaded** في الأعلى.
   - إن لم يظهر: **Settings** ← **General** ← **VPN & Device Management** ← ستجد ملف التعريف تحت *Downloaded Profile*.
4. اضغط على ملف التعريف ← **Install** (أعلى اليمين) ← أدخل رمز الجهاز ← **Install** ← **Install** مرة أخرى لتأكيد التحذير ← **Done**.

### المرحلة الثانية: تفعيل الثقة الكاملة ← بدونها يبقى التحذير

```
Settings → General → About → Certificate Trust Settings
```

5. مرّر إلى أسفل شاشة **About** تماماً — بند **Certificate Trust Settings** يظهر فقط بعد تنصيب جذر واحد على الأقل.
6. تحت **ENABLE FULL TRUST FOR ROOT CERTIFICATES** ستجد `Tamkeen Alkafaat Internal Root CA` ومفتاح تبديل بجانبه.
7. **فعِّل المفتاح** ← ستظهر نافذة تحذير *Root Certificate — Warning* ← اضغط **Continue**.

الآن فقط يختفي التحذير في Safari.

> **عند النشر عبر MDM** (Jamf / Intune / Apple Business Manager): ادفع الجذر ضمن ملف تعريف بحمولة **Certificate** من نوع Root CA. الجذور المُنصَّبة عبر ملف تعريف MDM تُعتمد تلقائياً ولا تحتاج تبديل Certificate Trust Settings يدوياً — لكن تحقّق من ذلك على جهاز نموذجي بعد أول نشر قبل تعميمه.

---

## 6. Firefox — مخزن ثقة مستقل تماماً عن النظام

Firefox لا يقرأ مخزن ويندوز/macOS افتراضياً؛ يستخدم مخزن NSS الخاص به. لذلك سيستمر التحذير في Firefox حتى بعد نجاح التنصيب على مستوى النظام.

### 6.1 الحل الأسرع في بيئة مُدارة: اجعل Firefox يقرأ مخزن النظام

إن كنت قد نشرت الجذر أصلاً عبر GPO (القسم 2)، فعِّل هذا الخيار وانتهى الأمر:

- يدوياً: افتح `about:config` ← اقبل التحذير ← ابحث عن `security.enterprise_roots.enabled` ← اضبطه على **true** ← أعد تشغيل Firefox.
- هذا الخيار يجعل Firefox يستورد الجذور المُضافة محلياً من مخزن ويندوز/macOS تلقائياً.

### 6.2 الاستيراد اليدوي (جهاز مفرد)

1. **Settings** (أو `about:preferences`) ← **Privacy & Security**.
2. مرّر إلى قسم **Certificates** ← اضغط **View Certificates…**.
3. تبويب **Authorities** ← **Import…** ← اختر `kafaat-ca.crt`.
4. في نافذة *Downloading Certificate*: **فعّل الخيار** ☑ **Trust this CA to identify websites**.
   - اضغط **View** أولاً لمطابقة بصمة SHA-256 مع القسم 0.
5. **OK** ← أعد تحميل الصفحة.

### 6.3 النشر الجماعي عبر policies.json

أنشئ ملف `policies.json` بالمحتوى التالي:

```json
{
  "policies": {
    "Certificates": {
      "ImportEnterpriseRoots": true,
      "Install": ["kafaat-ca.crt"]
    }
  }
}
```

- `ImportEnterpriseRoots` — يجعل Firefox يثق بالجذور المُضافة في مخزن النظام (ويندوز وmacOS). هذا وحده كافٍ إن نشرتَ الجذر عبر GPO.
- `Install` — يستورد ملف الشهادة مباشرة إلى مخزن Firefox، ومفيد على لينكس أو حين لا يوجد نشر على مستوى النظام.

**موضع `policies.json`:**

| النظام | المسار |
|---|---|
| Windows | `C:\Program Files\Mozilla Firefox\distribution\policies.json` |
| macOS | `/Applications/Firefox.app/Contents/Resources/distribution/policies.json` |
| Linux | `/etc/firefox/policies/policies.json` (أو `/usr/lib/firefox/distribution/policies.json`) |

**مواضع ملفات الشهادات التي يبحث فيها مفتاح `Install`** (أو استخدم مساراً مطلقاً كاملاً داخل المصفوفة تجنّباً للالتباس):

| النظام | المجلدات |
|---|---|
| Windows | `%USERPROFILE%\AppData\Roaming\Mozilla\Certificates` و `%LOCALAPPDATA%\Mozilla\Certificates` |
| macOS | `/Library/Application Support/Mozilla/Certificates` و `~/Library/Application Support/Mozilla/Certificates` |
| Linux | `/usr/lib/mozilla/certificates` و `~/.mozilla/certificates` |

**التحقّق:** افتح `about:policies` ← تبويب **Active** ← يجب أن تظهر سياسة `Certificates` مطبَّقة.

> نفس المبدأ ينطبق على Thunderbird ومتصفّح Tor. أما Chrome/Edge/Safari فتعتمد مخزن النظام ولا تحتاج خطوة إضافية — ملاحظة: اعتماد Chrome على *Chrome Root Store* لا يؤثّر على الجذور المُضافة محلياً أو عبر سياسة المؤسسة، فهي تبقى موثوقة.

---

## 7. Android

### 7.1 التنصيب اليدوي

انقل `kafaat-ca.der` (أو `.crt`) إلى ذاكرة الجهاز، ثم:

```
Settings → Security & privacy → More security & privacy →
Encryption & credentials → Install a certificate → CA certificate
```

(على إصدارات أقدم: `Settings → Security → Encryption & credentials → Install from storage → CA certificate`. المسار يختلف بين Samsung/Xiaomi/Pixel — ابحث عن كلمة **certificate** في بحث الإعدادات.)

1. سيظهر تحذير أحمر *Your data won't be private* ← اضغط **Install anyway**.
2. أدخل رمز قفل الشاشة. **ملاحظة:** أندرويد يرفض تنصيب جذر ما لم يكن للجهاز قفل شاشة (PIN/نمط/بصمة) — إن لم يكن مضبوطاً سيطالبك بضبطه أولاً.
3. اختر الملف ← ستظهر رسالة *CA certificate installed*.
4. ظهور إشعار دائم *Network may be monitored by an unknown third party* سلوك طبيعي لأي جذر مُضاف من المستخدم، وليس دليل خطأ.

### 7.2 حدود مهمة على أندرويد

- **Chrome على أندرويد يثق بمخزن المستخدم** ← التحذير يختفي في التصفّح. هذا يغطي حالة استخدام المنصّة.
- **التطبيقات الأصلية (Native apps) منذ Android 7 لا تثق بمخزن المستخدم افتراضياً.** إن كان لكفاءات تطبيق أندرويد يتصل بالخادم، فلن يكفي تنصيب الجذر على الجهاز — يجب إضافة إعداد `network_security_config.xml` داخل التطبيق نفسه يعتمد الجذر (`<certificates src="@raw/kafaat_ca"/>`) أو يسمح بمخزن المستخدم (`<certificates src="user"/>`).
- **Firefox على أندرويد** يستخدم مخزنه الخاص؛ الإصدارات الحديثة تقرأ جذور المستخدم من النظام لكنها قد تحتاج تفعيلاً — اختبرها قبل الاعتماد عليها.
- **النشر المُدار:** عبر Intune / Android Enterprise أنشئ ملف تعريف **Trusted certificate** وارفع `kafaat-ca.der` — يُنصَّب دون تدخّل المستخدم ودون إشعار المراقبة.

---

## 8. كيف يتحقّق المستخدم أن التنصيب نجح

### 8.1 الاختبار الحاسم — المتصفّح

افتح `https://172.16.0.73/`:

- ✅ **نجاح:** الصفحة تُفتح مباشرة مع أيقونة قفل مغلق في شريط العنوان، ولا تظهر أي صفحة تحذير.
- ❌ **فشل:** ظهور *Your connection is not private* / *NET::ERR_CERT_AUTHORITY_INVALID* / *Warning: Potential Security Risk Ahead*.

إن كان المتصفّح مفتوحاً أثناء التنصيب، **أغلقه كلياً وأعد فتحه** — كثير من حالات «لم ينجح» سببها جلسة متصفّح قديمة تحتفظ بالنتيجة السابقة في الذاكرة.

### 8.2 التحقّق من سلسلة الثقة داخل المتصفّح

اضغط على أيقونة القفل ← **Connection is secure** ← **Certificate is valid** (Chrome/Edge) أو **More Information → View Certificate** (Firefox):

يجب أن ترى:
- **المُصدِر (Issuer):** `Tamkeen Alkafaat Internal Root CA`
- **الموضوع (Subject):** `172.16.0.73`
- **Subject Alternative Names:** `172.16.0.73، kafaat.internal.gov.sa، kafaat.local، localhost، 127.0.0.1`
- بعد إصدار شهادة الاسم الجديد يصير الموضوع `moitp.gov.sa` وتُضاف `moitp.gov.sa` إلى القائمة أعلاه.
- في تبويب سلسلة الشهادات: الجذر `Tamkeen Alkafaat Internal Root CA` وببصمة SHA-256 مطابقة للقسم 0.

### 8.3 التحقّق بالأوامر

**ويندوز** (PowerShell — لاحظ أن `Thumbprint` في PowerShell هو **SHA-1** لا SHA-256):

```powershell
Get-ChildItem Cert:\LocalMachine\Root |
  Where-Object { $_.Thumbprint -eq '5F1E8B484247CF8FA151F9EE881DFD61D6D62DCC' } |
  Format-List Subject, NotAfter, Thumbprint

# اختبار اتصال حقيقي عبر مخزن ويندوز (curl مدمج في Windows 10/11)
curl.exe -sI https://172.16.0.73/
```

نجاح يعني `HTTP/2 200` **بدون** الحاجة إلى `-k`.

**macOS / Linux:**

```bash
# يجب أن يعود 200 بدون -k وبدون --cacert
curl -sI https://172.16.0.73/

# فحص السلسلة تفصيلياً مقابل نسخة الجذر لديك
openssl s_client -connect 172.16.0.73:443 \
  -servername kafaat.internal.gov.sa -CAfile kafaat-ca.crt </dev/null 2>&1 |
  grep -E 'Verify return code|subject=|issuer='
```

النتيجة المتوقّعة: `Verify return code: 0 (ok)`.

**iOS/iPadOS:** `Settings → General → About → Certificate Trust Settings` ← يجب أن يكون مفتاح `Tamkeen Alkafaat Internal Root CA` **مُفعَّلاً (أخضر)**. مفتاح مطفأ = التنصيب تمّ والثقة لم تُفعَّل ← التحذير سيبقى.

**Firefox:** `about:preferences#privacy` ← **View Certificates…** ← تبويب **Authorities** ← ابحث عن `Tamkeen Alkafaat Center` ← يجب أن يظهر تحته `Tamkeen Alkafaat Internal Root CA`.

---

## 9. ملاحظات تشغيلية ومزالق

| الملاحظة | التفصيل |
|---|---|
| **الشهادة النهائية تنتهي 2027-09-06** | صلاحيتها 397 يوماً (ضمن حدّ Apple البالغ 398). يجب تجديدها عبر `deploy/scripts/issue-tls.sh` قبل انتهائها. **التجديد لا يتطلّب إعادة توزيع الجذر** طالما بقي المفتاح الخاص للجذر كما هو — الأجهزة ستقبل الشهادة الجديدة تلقائياً. |
| **الجذر ينتهي 2036-08-02** | خطّط لاستبداله قبل 2036، وإلا انقطعت الثقة على جميع الأجهزة دفعةً واحدة. |
| **لا توزّع المفتاح الخاص أبداً** | `/etc/ssl/kafaat/ca.key` بصلاحيات `600 root` على الخادم. الملف الوحيد الذي يُوزَّع هو `ca.crt`. تسريب `ca.key` يعني أن كل جهاز نُصِّب عليه الجذر أصبح قابلاً لانتحال أي موقع. |
| **لا تعتمد على HSTS كبديل عن التنصيب** | ترويسة HSTS معرَّفة في إعداد nginx لكنها لا تصل فعلياً إلا على مسار `/api/health` (بسبب تجاوز `add_header` داخل كتل `location` المتداخلة). النتيجة: المستخدم ما زال يستطيع تجاوز التحذير بالضغط على «متابعة» — وهذا بالضبط ما يجب منعه بالنشر المركزي عبر GPO. |
| **الرابط الجديد `moitp.gov.sa`** | `server_name` في المستودع صار `moitp.gov.sa 172.16.0.73` فيردّ nginx على الاسمين معاً، لكن **الشهادة وحدها هي الحاكم**: الشهادة المنشورة تغطّي `172.16.0.73` و`kafaat.internal.gov.sa` و`kafaat.local` فقط، ففتح `https://moitp.gov.sa/` بها يعطي `NET::ERR_CERT_COMMON_NAME_INVALID`. حتى تصدر شهادة تغطّي الاسم الجديد، **يبقى `https://172.16.0.73/` هو الرابط المعمّم على المستخدمين**. |
| **الجذر الداخلي لا يستطيع توقيع `moitp.gov.sa`** | قيود الأسماء في شهادة الجذر تسمح بـ`internal.gov.sa` و`kafaat.local` و`172.16.0.0/16` وحدها، وهي **مخبوزة في الجذر** فلا تتغيّر بتعديل السكربت. `kafaat-pki.sh issue` سيفشل الآن عند تحقّق السلسلة — وهو الفشل الصحيح لا عطل. المسار المعتمد: `kafaat-pki.sh moi-csr` وشهادة من سلطة إصدار الوزارة (تعمل بلا تنصيب جذرٍ على الأجهزة أصلاً). إعادة إنشاء الجذر بقيدٍ جديد بديلٌ أخير: يُبطل الثقة المنصَّبة على كل جهاز ويستلزم توزيعاً جديداً كاملاً. |
| **DNS** | يلزم سجل A لـ`moitp.gov.sa` يشير إلى `172.16.0.73` على المحلّل الداخلي قبل أن يعمل الاسم من أجهزة المستخدمين. |
| **انحراف بين المستودع والخادم** | الملف الفعّال هو `/etc/nginx/sites-available/kafaat.conf` (لا `kafaat-internal.conf`)، ونسخة المستودع تشير إلى مسارات شهادات مختلفة (`internal.fullchain.pem`). وحّدهما قبل أي نشر آلي لئلا يُكتب فوق الإعداد العامل. |
| **الشهادة الرسمية قيد الطلب** | يوجد `moi-request.csr` على الخادم. متى صدرت شهادة من سلطة معتمدة من الوزارة، تصبح هذه المنظومة الداخلية غير لازمة — عندها احذف الجذر من الأجهزة (أوامر التراجع مذكورة في كل قسم أعلاه) بدل تركه موثوقاً بلا حاجة. |

---

## 10. ملحق — نسخة الجذر (PEM)

للمطابقة المرجعية فقط. اعتمد دائماً النسخة المسحوبة من الخادم مباشرةً، وطابِق بصمة SHA-256 قبل الوثوق.

```
-----BEGIN CERTIFICATE-----
MIIFqjCCA5KgAwIBAgIUeK15+wtsFZR3oF3j7QOG2q/JMvQwDQYJKoZIhvcNAQEL
BQAwWzELMAkGA1UEBhMCU0ExIDAeBgNVBAoMF1RhbWtlZW4gQWxrYWZhYXQgQ2Vu
dGVyMSowKAYDVQQDDCFUYW1rZWVuIEFsa2FmYWF0IEludGVybmFsIFJvb3QgQ0Ew
HhcNMjYwODA1MjMxNTU1WhcNMzYwODAyMjMxNTU1WjBbMQswCQYDVQQGEwJTQTEg
MB4GA1UECgwXVGFta2VlbiBBbGthZmFhdCBDZW50ZXIxKjAoBgNVBAMMIVRhbWtl
ZW4gQWxrYWZhYXQgSW50ZXJuYWwgUm9vdCBDQTCCAiIwDQYJKoZIhvcNAQEBBQAD
ggIPADCCAgoCggIBAL2A0eiAdFvFmAf5rTiOpp+CS0AqRtolRHBQLSUNXaOx7McO
74vNDlpLql5PfFkjJ7mGFFoGbeH2RMTTQ0IUhyUSjuxSaCMALBuoQNxyNGsmPLzd
S2awrZrsSET3Bl9v3qw0BQZR2D/mZG/qc2DngGnXWnbG2ssakWZus7QYugdpBN+K
Eb3G/qsYXP30GwjOuu8K2/AbpgnZ0/6g+KfpiiHF+/bjKoELawEI/9v11ndW/ytE
d5+KFOwAMo2XSvu4xuDdtnsoX20cw4293D4WalnKBEQpS8SbAB2OqooaO/9dvbY9
Eak2SrlTh4fJanSJXcbcNZWExit3wQXd0P3yJpsT5B21u/KyPEQE65axQzGIW/Af
zRhMQ6jmyKF5zbisUe+2kiqFgGzHHkebq8Ts1rBFcZT05gjcBI5noAMfCm8mSY4S
S820pBi3L0tqCRMzwOxHTnp/BbfV+hvs6x48BFxh1vA4czsgBIZr5/YSQvx2ab4c
HGb6t2+mdbSvTygn2l3iifaqqdZZqnVDSFTUSb8un55mVXcw4MNOFTQKRPo1pp9A
m+8/BVDHIWBig7GZUMAq9MiIOVXerwDp/ugiihiS32S8RIifhSQ5B8PinFjsrV7G
6+GthAzqcGygS1IcjvUQjrWYiIswAHDXJVWjzjPHh2nzlA6cbAC/3tiYKRVFAgMB
AAGjZjBkMB8GA1UdIwQYMBaAFJ3781Tl7AsAAhePHwsGyNBFE/xWMBIGA1UdEwEB
/wQIMAYBAf8CAQAwDgYDVR0PAQH/BAQDAgEGMB0GA1UdDgQWBBSd+/NU5ewLAAIX
jx8LBsjQRRP8VjANBgkqhkiG9w0BAQsFAAOCAgEAZZWTwdQdkMhzJpZxRGT70wik
bzFzjEa0VUXrrBZVj0lIsdzDrSK60Bd4q7wqkU8u+EmvNYE+dykc1NRGVW/lXD6U
YvFDkKNWfxoU5ZMtsIL0VpYm3sKGn+UFem3wi8qcdShfPQ+SxhEvI6WUGTlmq0wZ
t45Ealp72RNLyfnasGYM7XP80rsi24CVGeg6ZVSxFF0tfFEr/A+IunrNobbrcfKY
xWgRnVEyXPZ0w6ZQgNbdb+hWg1u2ua0/OMB9lleGjV0dZycP4yAzWZqiiL/8FVWE
9sl2Bi68cGCVRxQQnXrkFAlgN8Z7tiYRSUDPYEyAas9OqTwCR8nEioaY5eh1qwqM
LVZDvEo6hGAADPFRwV0J/J+36jI8lFl2kOnR1pJu63KSqhBP8HCRtFPC4OHJB80v
INjtALsMKcazKHKD5Ae+TpZV4zlhsgCbVE01rCqs3zQ31CndhjuWbJ6RZD6jVM3m
9IjmsE84Yb5xuhDiwtIFGJ+kvbHeqSCRhNCL4hipQULI+/7juTYTDhBXNfk1IYFW
pfd3XrsrsNDSSM5sa3f5ELKpqqdoD602Emrjc3j816J5Kw6MxrluypDkzbSoBOIE
E7YWSJW9AswmMkOUlBb06KGaIsC7jHTMC65oeCIH9EsvTqF4ozr/MfFlyEEVVpk6
SdjvDkGoKY8VHMJIQts=
-----END CERTIFICATE-----
```
