#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════
#  فحص جاهزية الخادم قبل الإطلاق — يُشغَّل على خادم التطبيق.
#
#  كل بند هنا عطلٌ رأيتُه يمرّ إلى الإنتاج صامتاً: لا يرمي خطأً، ولا يظهر
#  في اختبار، ويُكتشف بعد أسابيع من شكوى مستخدم.
#
#      ./preflight.sh            # فحص
#      ./preflight.sh --verbose  # مع تفاصيل كل بند
# ════════════════════════════════════════════════════════════
set -uo pipefail

APP=/srv/kafaat/current
VERBOSE=0
[[ "${1:-}" == "--verbose" ]] && VERBOSE=1

# إصدار PHP يُكتشف ولا يُثبَّت: الخادم قد يحمل 8.3 أو 8.4 أو أحدث، وسكربتٌ
# يفترض إصداراً بعينه يُخفق على الجميع عداه.
PHPV=$(ls -1d /etc/php/*/fpm 2>/dev/null | sed 's|/etc/php/||;s|/fpm||' | sort -V | tail -1)
PHPV=${PHPV:-8.4}
PHP=$(command -v "php$PHPV" || command -v php)
FPM_SERVICE="php${PHPV}-fpm"
FPM_CONFD="/etc/php/${PHPV}/fpm/conf.d"

# بعض الفحوص تلزمها صلاحية الجذر (ufw، قراءة إعدادات). إن لم تتوفّر نُعلنها
# «غير مفحوصة» ولا نُعلنها فاشلة — إنذارٌ كاذب يُفقد السكربت مصداقيته كلها.
SUDO=""
sudo -n true 2>/dev/null && SUDO="sudo -n"

PASS=0; FAIL=0; WARN=0
# ⚠ لا تستعمل ((PASS++)) هنا: الزيادة اللاحقة تُرجِع القيمة القديمة كحالة خروج،
# فأول نجاح (PASS=0) يُرجِع 1 فيُنفَّذ فرع «||» بعده — فتُطبع ✓ ثم ✗ للبند نفسه.
# وهو ما وقع فعلاً: «PHP 8.4.24 ✓» يتلوها «php غير موجود ✗».
ok()   { printf '  \e[32m✓\e[0m %s\n' "$1"; PASS=$((PASS+1)); }
bad()  { printf '  \e[31m✗\e[0m %s\n' "$1"; [[ -n "${2:-}" ]] && printf '      %s\n' "$2"; FAIL=$((FAIL+1)); return 0; }
soft() { printf '  \e[33m!\e[0m %s\n' "$1"; [[ -n "${2:-}" ]] && printf '      %s\n' "$2"; WARN=$((WARN+1)); return 0; }
sec()  { printf '\n\e[1m── %s ──\e[0m\n' "$1"; }

envv() { grep -E "^$1=" "$APP/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"'; }
cfg()  { $PHP "$APP/artisan" tinker --execute="echo json_encode(config('$1'));" 2>/dev/null | tail -1; }

sec "PHP وإضافاته"
ver=$($PHP -r 'echo PHP_VERSION;' 2>/dev/null)
[[ -n "$ver" ]] && ok "PHP $ver" || bad "php غير موجود"
for ext in intl pdo_pgsql redis mbstring openssl curl dom; do
  if $PHP -m | grep -qx "$ext"; then ok "الإضافة $ext"
  else
    case $ext in
      intl) bad "الإضافة intl مفقودة" "التواريخ الهجرية تختفي من نموذج السيرة المطبوع بصمت — CvSheetService يرجع فراغاً" ;;
      *)    bad "الإضافة $ext مفقودة" ;;
    esac
  fi
done

# ⚠ إعدادات opcache تُقرأ من ملفات FPM لا من `php -i`: الأخيرة تعرض إعدادات
# سطر الأوامر، وopcache مطفأ فيها عمداً (enable_cli=0). فحصُ CLI يُنذر كذباً
# بأن الإنتاج بلا opcache وهو مفعّل — وهو ما وقع فعلاً في أول تشغيل.
if $PHP -m 2>/dev/null | grep -qi 'Zend OPcache'; then
  ok "امتداد opcache محمَّل"
  vt=$(grep -rhoP '^\s*opcache\.validate_timestamps\s*=\s*\K\S+' "$FPM_CONFD" 2>/dev/null | tail -1)
  case "${vt:-}" in
    0|off|Off|OFF) ok "opcache.validate_timestamps مطفأ في FPM (أداء الإنتاج)" ;;
    "")            soft "لم تُضبط opcache.validate_timestamps في $FPM_CONFD" "الافتراضي يفحص كل ملف في كل طلب" ;;
    *)             soft "opcache.validate_timestamps=$vt في FPM" "أطفئه (=0) وأعد تحميل fpm عند كل نشر" ;;
  esac
  en=$(grep -rhoP '^\s*opcache\.enable\s*=\s*\K\S+' "$FPM_CONFD" 2>/dev/null | tail -1)
  [[ "${en:-1}" =~ ^(0|off|Off)$ ]] && bad "opcache مطفأ في FPM" "أكبر فارق أداء منفرد"
else
  bad "امتداد opcache غير محمَّل" "تُعاد ترجمة كل ملف PHP في كل طلب"
fi

sec "إعدادات التطبيق"
[[ "$(envv APP_ENV)" == "production" ]] && ok "APP_ENV=production" || bad "APP_ENV ليس production"
[[ "$(envv APP_DEBUG)" == "false" ]] && ok "APP_DEBUG=false" || bad "APP_DEBUG ليس false" "يُفصح بمسارات الملفات وبنية الشيفرة في كل خطأ"
[[ "$(envv APP_KEY)" == base64:* ]] && ok "APP_KEY مضبوط" || bad "APP_KEY فارغ" "لا فكّ لأي بيانات مشفّرة"

# صلب المسألة: هل نجت إعدادات الأمان من config:cache؟
if [[ -f "$APP/bootstrap/cache/config.php" ]]; then
  ok "الإعدادات مخبوزة (config:cache)"
  proxies=$(cfg 'security.trusted_proxies')
  if [[ "$proxies" == "[]" || -z "$proxies" ]]; then
    bad "قائمة الوسطاء الموثوقين فارغة بعد التخزين" \
        "عنوان العميل يصير عنوان الوسيط ⇒ كل المستخدمين في دلو تقييد واحد، وسجل التدقيق يسجّل الوسيط"
  else
    ok "الوسطاء الموثوقون: $proxies"
  fi
  limit=$(cfg 'security.api_rate_limit')
  [[ -n "$limit" && "$limit" != "null" ]] && ok "حدّ المعدّل: $limit/دقيقة" || bad "حدّ المعدّل غير مقروء"
else
  soft "الإعدادات غير مخبوزة" "شغّل php artisan config:cache — الفارق ملموس"
fi

for c in routes views events; do
  [[ -f "$APP/bootstrap/cache/${c%s}s.php" || -d "$APP/storage/framework/views" ]] && ok "ذاكرة $c" || soft "ذاكرة $c غير مخبوزة"
done

sec "المحرّكات"
for pair in "SESSION_DRIVER:redis" "CACHE_STORE:redis" "QUEUE_CONNECTION:redis"; do
  k=${pair%%:*}; want=${pair##*:}; got=$(envv "$k")
  if [[ "$got" == "$want" ]]; then ok "$k=$got"
  else
    case $k in
      QUEUE_CONNECTION) bad "$k=$got (المتوقّع $want)" "sync يُنفّذ SendSmsJob داخل دورة الطلب — كل ترشيح ينتظر بوّابة الرسائل" ;;
      SESSION_DRIVER)   bad "$k=$got (المتوقّع $want)" "database يكتب صفّ جلسة لكل طلب على القاعدة نفسها" ;;
      *)                bad "$k=$got (المتوقّع $want)" ;;
    esac
  fi
done

sec "الخدمات"
if redis-cli ping 2>/dev/null | grep -q PONG; then
  ok "Redis يستجيب"
  pol=$(redis-cli config get maxmemory-policy 2>/dev/null | tail -1)
  [[ "$pol" == "noeviction" ]] && bad "سياسة Redis noeviction" "امتلاء الذاكرة يُسقط كتابة الجلسات والطابور — استعمل allkeys-lru للذاكرة المؤقّتة وقاعدة منفصلة للطابور"
  [[ "$pol" == "allkeys-lru" || "$pol" == "volatile-lru" ]] && ok "سياسة الذاكرة: $pol"
else
  bad "Redis لا يستجيب"
fi

if $PHP "$APP/artisan" db:show >/dev/null 2>&1; then ok "الاتصال بقاعدة البيانات"
else bad "تعذّر الاتصال بقاعدة البيانات"; fi

# grep -c يرجع صفراً بحالة خروج 1 حين لا يجد شيئاً، فـ`|| echo 0` كان يُلحق
# صفراً ثانياً فيصير الناتج "0\n0" ولا يساوي "0" — إخفاقٌ كاذب دائم.
pending=$($PHP "$APP/artisan" migrate:status 2>/dev/null | grep -c "Pending" || true)
pending=${pending:-0}
[[ "$pending" -eq 0 ]] && ok "لا هجرات معلّقة" || bad "$pending هجرة معلّقة"

for svc in "$FPM_SERVICE" nginx kafaat-scheduler.timer; do
  systemctl is-active --quiet "$svc" && ok "الخدمة $svc تعمل" || bad "الخدمة $svc متوقّفة"
done
qw=$(systemctl list-units 'kafaat-queue@*' --state=running --no-legend 2>/dev/null | wc -l)
[[ "$qw" -ge 1 ]] && ok "$qw عامل طابور يعمل" || bad "لا عمّال طابور" "الرسائل لن تُرسَل أبداً"

sec "أذونات الملفات"
# -L إلزامية: .env رابط رمزي للمشترك، وstat بلا -L يقيس الرابط نفسه (777 دائماً)
envmode=$(stat -Lc %a "$APP/.env" 2>/dev/null || echo "?")
[[ "$envmode" == "600" ]] \
  && ok ".env بصلاحية 600" || bad ".env بصلاحية $envmode لا 600" "يحوي مفتاح التشفير وكلمة قاعدة البيانات"
[[ -w "$APP/storage/logs" ]] && ok "storage/logs قابل للكتابة" || bad "storage/logs غير قابل للكتابة"
[[ -L "$APP/storage" ]] && ok "storage رابط للمشترك (ينجو من النشر)" || bad "storage ليس رابطاً" "السجلّات تضيع مع كل نشر"

sec "الشبكة والجدار الناري"
# ufw status يلزمه الجذر. بلا sudo نُعلنها «غير مفحوصة» لا «غير مفعّلة» —
# الحكم بالغياب على ما لم يُقرأ إنذارٌ كاذب.
if ! command -v ufw >/dev/null; then
  soft "ufw غير منصَّب"
elif [[ -z "$SUDO" ]]; then
  soft "تعذّر فحص ufw (يلزمه sudo)" "شغّل: sudo ufw status"
elif $SUDO ufw status 2>/dev/null | grep -q "Status: active"; then
  ok "ufw مفعّل"
  $SUDO ufw status 2>/dev/null | grep -qE "^(5432|6379)" \
    && soft "منفذ قاعدة/Redis مفتوح في ufw" "يجب ألّا يُرى إلا محلياً"
else
  bad "ufw غير مفعّل" "الخادم مكشوف على كل منافذه"
fi
ss -lntp 2>/dev/null | grep -q '127.0.0.1:6379' && ok "Redis على الواجهة المحلية فقط" \
  || soft "راجِع ربط Redis" "يجب ألّا يستمع على 0.0.0.0"

printf '\n\e[1m── الخلاصة ──\e[0m\n'
printf '  ناجح: %d   تحذير: %d   \e[31mفاشل: %d\e[0m\n\n' "$PASS" "$WARN" "$FAIL"
[[ $FAIL -eq 0 ]] || { echo "لا تُطلق قبل معالجة الإخفاقات."; exit 1; }
echo "جاهز للإطلاق."
