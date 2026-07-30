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
PHP=/usr/bin/php
VERBOSE=0
[[ "${1:-}" == "--verbose" ]] && VERBOSE=1

PASS=0; FAIL=0; WARN=0
ok()   { printf '  \e[32m✓\e[0m %s\n' "$1"; ((PASS++)); }
bad()  { printf '  \e[31m✗\e[0m %s\n' "$1"; [[ -n "${2:-}" ]] && printf '      %s\n' "$2"; ((FAIL++)); }
soft() { printf '  \e[33m!\e[0m %s\n' "$1"; [[ -n "${2:-}" ]] && printf '      %s\n' "$2"; ((WARN++)); }
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

if $PHP -i 2>/dev/null | grep -q 'opcache.enable => On'; then
  ok "opcache مفعّل"
  if $PHP -i 2>/dev/null | grep -q 'opcache.validate_timestamps => Off'; then
    ok "opcache.validate_timestamps مطفأ (أداء الإنتاج)"
  else
    soft "opcache.validate_timestamps مفعّل" "يفحص كل ملف في كل طلب — أطفئه وأعد تحميل fpm عند النشر"
  fi
else
  bad "opcache مطفأ" "أكبر فارق أداء منفرد — تُعاد ترجمة كل ملف في كل طلب"
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

pending=$($PHP "$APP/artisan" migrate:status 2>/dev/null | grep -c "Pending" || echo 0)
[[ "$pending" == "0" ]] && ok "لا هجرات معلّقة" || bad "$pending هجرة معلّقة"

for svc in php8.3-fpm nginx kafaat-scheduler.timer; do
  systemctl is-active --quiet "$svc" && ok "الخدمة $svc تعمل" || bad "الخدمة $svc متوقّفة"
done
qw=$(systemctl list-units 'kafaat-queue@*' --state=running --no-legend 2>/dev/null | wc -l)
[[ "$qw" -ge 1 ]] && ok "$qw عامل طابور يعمل" || bad "لا عمّال طابور" "الرسائل لن تُرسَل أبداً"

sec "أذونات الملفات"
[[ "$(stat -c %a "$APP/.env" 2>/dev/null || stat -c %a "$(readlink -f "$APP/.env")")" == "600" ]] \
  && ok ".env بصلاحية 600" || bad ".env ليس 600" "يحوي مفتاح التشفير وكلمة قاعدة البيانات"
[[ -w "$APP/storage/logs" ]] && ok "storage/logs قابل للكتابة" || bad "storage/logs غير قابل للكتابة"
[[ -L "$APP/storage" ]] && ok "storage رابط للمشترك (ينجو من النشر)" || bad "storage ليس رابطاً" "السجلّات تضيع مع كل نشر"

sec "الشبكة والجدار الناري"
if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q "Status: active"; then
  ok "ufw مفعّل"
  ufw status 2>/dev/null | grep -q "5432" && soft "منفذ 5432 مفتوح في ufw" "قاعدة البيانات يجب ألّا تُرى إلا من خادم التطبيق"
else
  soft "ufw غير مفعّل"
fi
ss -lntp 2>/dev/null | grep -q '127.0.0.1:6379' && ok "Redis على الواجهة المحلية فقط" \
  || soft "راجِع ربط Redis" "يجب ألّا يستمع على 0.0.0.0"

printf '\n\e[1m── الخلاصة ──\e[0m\n'
printf '  ناجح: %d   تحذير: %d   \e[31mفاشل: %d\e[0m\n\n' "$PASS" "$WARN" "$FAIL"
[[ $FAIL -eq 0 ]] || { echo "لا تُطلق قبل معالجة الإخفاقات."; exit 1; }
echo "جاهز للإطلاق."
