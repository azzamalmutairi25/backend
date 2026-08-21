# خطة النشر والإطلاق — منصّة مركز تمكين الكفاءات

نشرٌ على ثلاثة خوادم Ubuntu 24.04 LTS، لخدمة **٥٠+ مستخدماً متزامناً** بلا انقطاع،
مع عزل بوّابة المشارك العامة في الـDMZ.

```
deploy/
  nginx/     kafaat-internal.conf · kafaat-dmz.conf · kafaat-app-portal.conf · snippets/
  php/       kafaat-fpm-pool.conf · opcache.ini
  systemd/   kafaat-queue@.service · kafaat-scheduler.service · .timer
  postgres/  kafaat-tuning.conf
  env/       backend.env.production.example
  scripts/   deploy.sh (نشر بلا انقطاع + رجوع) · preflight.sh (فحص جاهزية)
```

---

## ✅ عائق الإطلاق الذي كُشف — وأُغلق

اختبار الحمل كشف عطلاً يُسقط الخدمة تحت التزامن، **وقد أُصلح**. يبقى مذكوراً
هنا لأنّ من يقرأ خطّة نشرٍ يحتاج أن يعرف ما جُرِّب وما ظهر، لا أن يجد صفحةً
نظيفة لا تقول شيئاً.

**سباق توليد رمز المشارك.** كان [`Assessment::generateParticipantCode()`](../app/Models/Assessment.php)
يقرأ أعلى رقم في ذاكرة PHP ثم يُدرج بلا قفل: طلبان متزامنان يقرآن القيمة
نفسها فيولّدان الرمز نفسه، ويسقط أحدهما على القيد الفريد بخطأ 500. وكان يجلب
**كل** رموز القطاع في كل إدراج، فالكلفة تنمو مع نجاح النظام.

**العلاج المُنفَّذ:** الترقيم صار في القاعدة بعبارةٍ ذرّية واحدة على جدول
`participant_code_counters` (`INSERT … ON CONFLICT DO UPDATE … RETURNING`)،
بكلفةٍ ثابتة لا تنمو بعدد المشاركين، والقاعدة تُسلسل المتزامنين بنفسها.

| قياس الحمل — ٨ كتّاب متزامنين | الطلبات | أخطاء الخادم |
|---|---|---|
| قبل الإصلاح | ٤٢٧ | **١٥٤ (٣٦٪)** |
| بعده | ٣٨٥ | **صفر** |

الوسم: `checkpoint/participant-code-fix`. التفاصيل في [`load-test/README.md`](../load-test/README.md).

---

## ⚠ ما يلزم هذا الخادم بعينه

الخادم المُقدِّم **لا يحمل `node` ولا `npm`** — وهو الصواب: أدوات البناء لا
تُنصَّب في الإنتاج. ومنفذ ٢٢ إلى `github.com` **محجوب** من الشبكة الداخلية،
ومستودع الواجهة **خاصّ**.

فالنشر يمرّ بحزمة واجهة تُبنى على جهاز التطوير وتُنسخ:

```bash
# على جهاز التطوير
cd frontend && VITE_API_URL= npm run build
rsync -az --delete dist/ tamkeenadmin@172.16.0.73:/tmp/kafaat-deploy/dist/

# على الخادم
sudo -u kafaat env KAFAAT_FRONTEND_DIST=/tmp/kafaat-deploy/dist \
  /srv/kafaat/current/deploy/scripts/deploy.sh --dry-run
```

وبهذا لا رمزَ وصولٍ على الخادم ولا أدوات بناء. واستنساخ الخادم نفسه يمرّ عبر
HTTPS (المستودع عام)، وهو الافتراضي في السكربت.

---

## ١ · الطوبولوجيا

```
            الإنترنت                          الشبكة الداخلية
               │                                     │
        ┌──────▼──────┐                       ┌──────▼──────┐
        │  خادم ١     │   8443/mTLS           │  الموظّفون   │
        │  DMZ        ├──────────────┐        │  (٥٠+)      │
        │  nginx فقط  │              │        └──────┬──────┘
        │  حزمة ساكنة │              │               │ 443
        └─────────────┘        ┌─────▼───────────────▼─────┐
         kafaat.gov.sa         │  خادم ٢ — التطبيق          │
         ٤ مسارات فقط          │  nginx · PHP-FPM · Redis   │
                               │  عمّال الطابور · المجدول    │
                               └─────────────┬─────────────┘
                                             │ 5432/TLS
                                     ┌───────▼───────┐
                                     │ خادم ٣ — القاعدة│
                                     │ PostgreSQL 16 │
                                     └───────────────┘
```

| # | الدور | المواصفة المقترحة | يشغّل |
|---|---|---|---|
| ١ | DMZ | 2 vCPU / 4 GB | nginx وحده — **لا PHP ولا قاعدة بيانات** |
| ٢ | التطبيق | 8 vCPU / 16 GB | nginx · PHP-FPM 8.3 · Redis · عمّال · مجدول |
| ٣ | القاعدة | 4 vCPU / 16 GB / SSD | PostgreSQL 16 |

**لماذا الـDMZ بلا PHP؟** بوّابة المشارك مكشوفة للإنترنت. جعلها nginx يخدم ملفات
ساكنة ويمرّر **أربعة مسارات محدّدة بالاسم** يعني أن سطح الهجوم المكشوف = ملفات +
أربعة مسارات، لا تطبيق كامل بـ١٤٦ مساراً. وحزمة البوّابة (`build:public`) لا تستورد
أي شاشة داخلية ولا أي مفتاح صلاحية أصلاً.

**قواعد الجدار الناري** (ufw):

| من | إلى | المنفذ |
|---|---|---|
| الإنترنت | خادم ١ | 443 |
| خادم ١ | خادم ٢ | 8443 (mTLS) |
| الشبكة الداخلية | خادم ٢ | 443 |
| خادم ٢ | خادم ٣ | 5432 |
| الإدارة | الكل | 22 (من شبكة الإدارة وحدها) |

كل ما عدا ذلك مرفوض. **خادم ٣ لا يقبل 5432 إلا من خادم ٢.**

---

## ٢ · الحجم: هل يكفي لـ٥٠ مستخدماً؟

القياس على جهاز تطوير (٨ أنوية، `artisan serve` بلا opcache):

| التهيئة | الإنتاجية | p50 | p99 |
|---|---|---|---|
| عقدة واحدة | 8.4 ط/ث | 858ms | 2309ms |
| ٤ عقد + موزّع | 22.5 ط/ث | 269ms | 1233ms |

**الحساب**: ٥٠ مستخدماً يتصفّحون شاشة كل ٥–٨ ثوانٍ ⇒ **٦–١٧ طلباً/ثانية مستقرّة**.
ذروة بداية الدوام (الجميع يفتح اللوحة معاً) ⇒ **~٥٠ طلباً/ثانية لدقيقة**.

الإنتاج يختلف جوهرياً عن القياس أعلاه في ثلاثة:
opcache (لا إعادة ترجمة في كل طلب) · الجلسة والذاكرة المؤقّتة في Redis لا في
القاعدة · الرسائل خارج دورة الطلب.

**النتيجة**: 32 عامل FPM على 8 vCPU. بمتوسّط ٨٠ مللي للطلب يكفي **٤ عمّال**
لخمسين طلباً/ثانية — و32 هامشٌ ثمانية أضعاف يستوعب الذيل البطيء (اللوحة،
التحليلات، توليد المستندات).

> **لا تُصدّق هذا الرقم — تحقّق منه.** بعد تجهيز الاختباري شغّل:
> ```bash
> php artisan loadtest:prepare --readers=60 --writers=10 --candidates=2000
> node load-test/loadtest.mjs --url=https://staging --vus=60 --duration=300 --mix=mixed
> ```
> **بوّابة القبول**: p95 < 800ms · صفر 5xx · صفر مهلات. إن لم تتحقّق فارفع
> `pm.max_children` أو أضِف خادم تطبيق ثانياً — لا تُطلق على أمل.

---

## ٣ · التنصيب

### خادم ٢ (التطبيق)

```bash
sudo apt update && sudo apt install -y \
  nginx redis-server postgresql-client-16 git curl unzip \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-redis php8.3-intl \
  php8.3-mbstring php8.3-xml php8.3-curl php8.3-bcmath php8.3-zip

# ⚠ intl إلزامية: بدونها تختفي التواريخ الهجرية من نموذج السيرة المطبوع
#   بلا خطأ — CvSheetService يرجع فراغاً صامتاً.
php -m | grep -qx intl || { echo "intl مفقودة"; exit 1; }

curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

sudo useradd -r -m -d /srv/kafaat -s /bin/bash kafaat
sudo usermod -aG kafaat www-data
sudo -u kafaat mkdir -p /srv/kafaat/{releases,shared/storage,shared/storage/logs}
sudo mkdir -p /var/log/{kafaat,php} /var/cache/php/opcache
sudo chown -R kafaat:kafaat /var/log/kafaat /srv/kafaat
sudo chown -R www-data:www-data /var/log/php /var/cache/php
```

الإعدادات:

```bash
sudo cp deploy/php/kafaat-fpm-pool.conf /etc/php/8.3/fpm/pool.d/kafaat.conf
sudo rm -f /etc/php/8.3/fpm/pool.d/www.conf          # احذف الافتراضي
sudo cp deploy/php/opcache.ini /etc/php/8.3/fpm/conf.d/99-kafaat-opcache.ini
sudo mkdir -p /etc/nginx/snippets
sudo cp deploy/nginx/snippets/kafaat-php.conf /etc/nginx/snippets/
sudo cp deploy/nginx/kafaat-internal.conf deploy/nginx/kafaat-app-portal.conf /etc/nginx/sites-available/
sudo ln -sf /etc/nginx/sites-available/kafaat-{internal,app-portal}.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo cp deploy/systemd/* /etc/systemd/system/
sudo cp deploy/env/backend.env.production.example /srv/kafaat/shared/.env
sudo chown kafaat:kafaat /srv/kafaat/shared/.env && sudo chmod 600 /srv/kafaat/shared/.env
# املأ .env، ثم:
sudo -u kafaat php artisan key:generate --show   # ← احفظه في خزنة المفاتيح
```

**Redis** — `/etc/redis/redis.conf`:
```
bind 127.0.0.1
requirepass <كلمة قوية>
maxmemory 2gb
maxmemory-policy allkeys-lru
appendonly no
```
`allkeys-lru` لا `noeviction`: امتلاء الذاكرة مع `noeviction` يُسقط كتابة الجلسات
والطابور. الطابور في قاعدة Redis منفصلة (`REDIS_QUEUE_DB`) كي لا يُطرَد بضغط الذاكرة المؤقّتة.

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now php8.3-fpm nginx redis-server
sudo systemctl enable --now kafaat-queue@1 kafaat-queue@2 kafaat-import@1 kafaat-scheduler.timer
```

**sudoers** — سكربت النشر يحتاج إعادة تحميل FPM وحدها:
```
kafaat ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm
```

### خادم ٣ (القاعدة)

```bash
sudo apt install -y postgresql-16
sudo cp deploy/postgres/kafaat-tuning.conf /etc/postgresql/16/main/conf.d/
sudo mkdir -p /var/backups/kafaat/wal && sudo chown postgres:postgres /var/backups/kafaat/wal
sudo -u postgres psql -c "CREATE ROLE kafaat LOGIN PASSWORD '<كلمة قوية>';"
sudo -u postgres psql -c "CREATE DATABASE kafaat OWNER kafaat ENCODING 'UTF8';"
sudo -u postgres psql -d kafaat -c "CREATE EXTENSION IF NOT EXISTS pg_stat_statements;"
```
`pg_hba.conf`: `hostssl kafaat kafaat 10.10.10.20/32 scram-sha-256` — **ولا سطر غيره** لهذه القاعدة.

### خادم ١ (DMZ)

```bash
sudo apt install -y nginx
sudo cp deploy/nginx/kafaat-dmz.conf /etc/nginx/sites-available/
sudo ln -sf /etc/nginx/sites-available/kafaat-dmz.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo mkdir -p /srv/kafaat-portal
```
حزمة البوّابة تُنسخ من خادم التطبيق بعد كل نشر:
```bash
rsync -a --delete kafaat@10.10.10.20:/srv/kafaat/current/portal-dist/ /srv/kafaat-portal/current/
```

---

## ٤ · النشر بلا انقطاع

```bash
sudo -u kafaat /srv/kafaat/deploy.sh              # نشر
sudo -u kafaat /srv/kafaat/deploy.sh --ref=v1.2.0 # وسم بعينه
sudo -u kafaat /srv/kafaat/deploy.sh --rollback   # رجوع فوري
```

**كيف ينعدم الانقطاع**: يُبنى الإصدار كاملاً بجانب العامل (شيفرة، اعتماديات،
حزمتا الواجهة، هجرات، ذاكرات مخبوزة، فحص دخان)، ثم يُبدَّل **رابط رمزي واحد**
بـ`mv -T` — عملية ذرّية في نظام الملفات. لا لحظة يرى فيها nginx مساراً ناقصاً.

بعدها `systemctl reload php8.3-fpm` — إعادة تحميل لا إعادة تشغيل: العمّال
الحاليون يُنهون طلباتهم ثم يخرجون، والجدد يبدؤون على الشيفرة الجديدة.

> **الفخّ**: `opcache.validate_timestamps=0` يعني أن PHP **لن يلاحظ الشيفرة
> الجديدة أبداً** بلا إعادة التحميل. السكربت يفعلها؛ لا تنشر يدوياً بدونها.

**قيدٌ على الهجرات**: تُشغَّل قبل التبديل، فالإصدار القديم يعمل على المخطّط الجديد
لثوانٍ. كل هجرة يجب أن تكون **متوافقة رجعياً**: تضيف عموداً ولا تحذف عموداً
يقرؤه العامل. حذف عمود = هجرتان في نشرتين (توقّف عن الكتابة، ثم احذف).

---

## ٥ · النسخ الاحتياطي والاستعادة

```bash
# يومي ٢:٠٠ — نسخة منطقية مضغوطة، محتفَظ بها ٣٠ يوماً
0 2 * * * postgres pg_dump -Fc kafaat | \
  openssl enc -aes-256-cbc -pbkdf2 -pass file:/etc/kafaat/backup.key \
  > /var/backups/kafaat/kafaat-$(date +\%F).dump.enc
```

النسخة **مشفّرة**: تحوي أسماء المشاركين وهوياتهم وسِيَرهم. أرشفة WAL مفعّلة
(راجع ملف الضبط) فالاستعادة تبلغ **أي لحظة** لا نسخة الليلة وحدها.

**احفظ `APP_KEY` في خزنة المفاتيح.** فقدانه = بياناتٌ مشفّرة لا تُفكّ أبداً،
والنسخة الاحتياطية عديمة الفائدة.

**تمرين استعادة إلزامي قبل الإطلاق**: استعِد على خادم منفصل، شغّل `preflight.sh`،
وافتح ملف مشارك وتأكّد أن اسمه يُقرأ. نسخة لم تُختبَر استعادتها ليست نسخة.

---

## ٦ · المراقبة

| ماذا | العتبة | لماذا |
|---|---|---|
| `/up` | فشل مرّتين متتاليتين | التطبيق ساقط |
| p95 للاستجابة | > 800ms خمس دقائق | تشبّع قادم |
| نسبة 5xx | > 0.5٪ | أعطال حقيقية |
| عمّال FPM المشغولون | > ٨٠٪ من 32 | ارفع الحدّ أو أضِف خادماً |
| طول طابور Redis | > 100 | العمّال متوقّفون أو متأخّرون |
| **آخر تشغيل للمجدول** | > ساعتين | **تعطُّله صامت**: يتوقّف كشف الغياب وتصعيد التقارير بلا خطأ |
| مساحة القرص | > ٨٥٪ | سجلّات ونسخ |
| اتصالات القاعدة | > ٨٠ من 100 | تسريب اتصالات |
| استعلامات > 500ms | أي زيادة مطّردة | استعلام ينمو مع البيانات |

سجلّات: `/var/log/kafaat/` · `/var/log/php/kafaat-slow.log` · `postgresql-*.log`.

---

## ٧ · الأمن والامتثال

| الضابط | الحالة |
|---|---|
| تشفير البيانات الحساسة في الحقول | ✅ قائم (`Crypt` على الاسم/الهوية/الجوال/البريد/السيرة) |
| التشفير أثناء النقل | ✅ TLS في الطبقات الثلاث + mTLS بين الـDMZ والتطبيق |
| سجل تدقيق كامل | ✅ قائم — **يعتمد على `TRUSTED_PROXIES`** لتسجيل العنوان الصحيح |
| فصل الواجهة العامة | ✅ حزمة منفصلة + ٤ مسارات + DMZ بلا PHP |
| صلاحيات دقيقة | ✅ مُدقَّقة (١٤٦ مساراً) — راجع `RouteAuthorizationSweepTest` |
| تقييد المعدّل | ✅ طبقتان: nginx عند الحافة + لارافيل لكل مستخدم |
| أقلّ امتياز على النظام | ✅ `open_basedir` · `disable_functions` · تقييد systemd |

**⚠ لا تنشر بـ`TRUSTED_PROXIES` فارغة.** خلف الوسيط، عنوان كل عميل يصير عنوان
الوسيط: كل مستخدمي المنصّة في دلو تقييد واحد، **وكل مشاركي البوّابة يتقاسمون
٢٠ طلباً في الدقيقة** — الخدمة تحجب نفسها. وسجل التدقيق يسجّل عنوان الوسيط
بدل صاحب الفعل، وهو إخلال بالمساءلة. `preflight.sh` يفحص هذا صراحةً.

---

## ٨ · تسلسل الإطلاق

**قبل بأسبوع** — أصلِح سباق رمز المشارك · جهّز الاختباري بنسخة من بيانات
الإنتاج · شغّل اختبار الحمل واعبر بوّابة القبول · نفّذ تمرين الاستعادة ·
دقّق الجدار الناري.

**قبل بيوم** — `preflight.sh` على الاختباري ⇐ صفر إخفاقات · جرّب النشر والرجوع
مرّتين على الأقل · تأكّد أن `APP_KEY` في الخزنة · صحّح ساعة الخوادم (`chrony`).

**يوم الإطلاق** — نسخة احتياطية كاملة أولاً · انشر · `preflight.sh` ⇐ صفر
إخفاقات · دخول تجريبي بكل دور · ترشيح مشارك تجريبي من بوّابة خارجية · تأكّد
من وصول رسالة نصية · راقب لساعة.

**بعد الإطلاق** — اليوم الأول: راقب p95 و5xx كل ساعة · الأسبوع الأول: راجع
الاستعلامات البطيئة يومياً · بعد أسبوعين: أعِد اختبار الحمل ببيانات حقيقية
وقارن بخطّ الأساس.

---

## ٩ · إن وقعت الخدمة

```bash
# ١) هل التطبيق حيّ؟
curl -sk https://localhost/up -w '\n%{http_code}\n'

# ٢) آخر الأخطاء
sudo tail -50 /srv/kafaat/shared/storage/logs/laravel.log
sudo tail -50 /var/log/nginx/kafaat-internal.error.log

# ٣) هل العمّال مشبعون؟  (active = max_children ⇒ إشباع)
sudo curl -s --unix-socket /run/php/kafaat.sock 'http://localhost/fpm-status'

# ٤) هل القاعدة هي العنق؟
sudo -u postgres psql -d kafaat -c \
  "SELECT mean_exec_time, calls, left(query,80) FROM pg_stat_statements ORDER BY mean_exec_time DESC LIMIT 10;"

# ٥) الرجوع — أسرع علاج إن كان السبب نشرةً جديدة
sudo -u kafaat /srv/kafaat/deploy.sh --rollback
```

| العرَض | السبب الأرجح |
|---|---|
| 502 من nginx | PHP-FPM ساقط أو المقبس بأذونات خاطئة |
| بطء عام مفاجئ | opcache مطفأ بعد نشر يدوي، أو إشباع العمّال |
| **429 للجميع** | **`TRUSTED_PROXIES` فارغة** — الكلّ في دلو واحد |
| رسائل لا تصل | لا عمّال طابور، أو `QUEUE_CONNECTION=sync` |
| 500 عند إضافة مشاركين معاً | **سباق رمز المشارك — العطل أعلاه** |
| التواريخ الهجرية فارغة | إضافة `intl` مفقودة |
| كشف الغياب توقّف | `kafaat-scheduler.timer` متوقّف |
