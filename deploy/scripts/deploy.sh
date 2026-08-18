#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════
#  نشر بلا انقطاع — إصدارات مرقّمة + تبديل رابط رمزي ذرّي.
#
#  المبدأ: يُبنى الإصدار الجديد كاملاً بجانب العامل، ولا يُلمس المسار
#  المُقدَّم إلا بتبديل رابطٍ واحد — عمليةٌ ذرّية في نظام الملفات. لا توجد
#  لحظةٌ يكون فيها الموقع نصف منشور، والرجوع تبديلٌ عكسي في ثانية.
#
#  الاستعمال:
#      ./deploy.sh                 # ينشر أحدث ما على الفرع المضبوط
#      ./deploy.sh --ref=v1.2.0    # وسمٌ أو إصدارٌ بعينه
#      ./deploy.sh --rollback      # يعود للإصدار السابق فوراً
#      ./deploy.sh --dry-run       # يطبع الخطوات بلا تنفيذ
# ════════════════════════════════════════════════════════════
set -Eeuo pipefail

APP_DIR=/srv/kafaat
RELEASES="$APP_DIR/releases"
SHARED="$APP_DIR/shared"
CURRENT="$APP_DIR/current"
REPO="${KAFAAT_REPO:-git@github.com:azzamalmutairi25/backend.git}"
FRONTEND_REPO="${KAFAAT_FRONTEND_REPO:-git@github.com:azzamalmutairi25/kafaat-frontend.git}"
BRANCH="${KAFAAT_BRANCH:-Production}"
KEEP=5
PHP=/usr/bin/php
# النسخة تُكتشف ولا تُثبَّت: الخادم يحمل 8.4 (يشترطها composer.lock)، وسكربتٌ
# يُعيد تحميل خدمةً باسمٍ خاطئ ينشر شيفرةً جديدة على عمّالٍ يحملون القديمة
# — بلا خطأ ظاهر، لأن opcache.validate_timestamps=0.
PHPV=$(ls -1d /etc/php/*/fpm 2>/dev/null | sed 's|/etc/php/||;s|/fpm||' | sort -V | tail -1)
FPM_SERVICE="php${PHPV:-8.4}-fpm"

REF=""; ROLLBACK=0; DRY=0
for a in "$@"; do
  case "$a" in
    --ref=*)    REF="${a#*=}" ;;
    --rollback) ROLLBACK=1 ;;
    --dry-run)  DRY=1 ;;
    *) echo "خيار غير معروف: $a" >&2; exit 2 ;;
  esac
done

log()  { printf '\e[32m▸\e[0m %s\n' "$*"; }
warn() { printf '\e[33m!\e[0m %s\n' "$*"; }
die()  { printf '\e[31m✗\e[0m %s\n' "$*" >&2; exit 1; }
run()  { if [[ $DRY == 1 ]]; then printf '   [dry] %s\n' "$*"; else eval "$@"; fi }

[[ $(id -un) == kafaat ]] || die "شغّله بمستخدم kafaat لا بـ$(id -un)"

# ── الرجوع ──
# يُقدَّم على كل شيء: لحظةَ الحاجة إليه لا وقت لقراءة سكربت
if [[ $ROLLBACK == 1 ]]; then
  prev=$(ls -1dt "$RELEASES"/*/ 2>/dev/null | sed -n 2p) || true
  [[ -n "${prev:-}" ]] || die "لا يوجد إصدار سابق للرجوع إليه"
  log "الرجوع إلى $(basename "$prev")"
  run "ln -sfn '${prev%/}' '$CURRENT.tmp' && mv -Tf '$CURRENT.tmp' '$CURRENT'"
  run "sudo systemctl reload $FPM_SERVICE"
  run "$PHP '$CURRENT/artisan' queue:restart"
  log "تمّ الرجوع. ⚠ الهجرات لا تُرجَع تلقائياً — راجِعها يدوياً إن كان الإصدار الفاشل قد هاجر."
  exit 0
fi

# ── فحوص ما قبل النشر ──
[[ -f "$SHARED/.env" ]] || die "لا يوجد $SHARED/.env"
grep -q '^APP_KEY=base64:' "$SHARED/.env" || die "APP_KEY غير مضبوط في $SHARED/.env"
grep -q '^APP_DEBUG=false' "$SHARED/.env" || die "APP_DEBUG ليس false — لا تنشر بوضع التنقيح"
grep -qE '^TRUSTED_PROXIES=.+' "$SHARED/.env" || die "TRUSTED_PROXIES فارغ — سيسقط تقييد المعدّل خلف الوسيط"
command -v $PHP >/dev/null || die "php غير موجود"
$PHP -m | grep -qx intl || die "إضافة intl مفقودة — التواريخ الهجرية ستختفي من المستندات بصمت"
$PHP -m | grep -qx pdo_pgsql || die "إضافة pdo_pgsql مفقودة"
$PHP -m | grep -qx redis || die "إضافة redis مفقودة"

STAMP=$(date +%Y%m%d%H%M%S)
NEW="$RELEASES/$STAMP"
log "إصدار جديد: $STAMP"

# ── ١) جلب الشيفرة ──
run "mkdir -p '$NEW'"
if [[ -n "$REF" ]]; then
  run "git clone --depth 1 --branch '$REF' '$REPO' '$NEW/src'"
else
  run "git clone --depth 1 --branch '$BRANCH' '$REPO' '$NEW/src'"
fi
run "shopt -s dotglob && mv '$NEW/src'/* '$NEW/' && rmdir '$NEW/src'"
DEPLOYED_SHA=$( [[ $DRY == 1 ]] && echo "dry" || git -C "$NEW" rev-parse --short HEAD )

# ── ٢) الاعتماديات ──
# --no-dev: أدوات التطوير ليست في الإنتاج. --classmap-authoritative: لا بحث
# عن الأصناف في نظام الملفات وقت التشغيل.
run "cd '$NEW' && composer install --no-dev --prefer-dist --no-interaction \
      --optimize-autoloader --classmap-authoritative --no-progress"

# ── ٣) بناء الواجهتين ──
# حزمتان: المنصّة الداخلية (index.html) وبوّابة المشارك (public.html).
# تُبنيان هنا لا على الخادم المُقدِّم: أدوات البناء لا تُنصَّب في الإنتاج.
FE="$NEW/.frontend"
run "git clone --depth 1 --branch '${KAFAAT_FRONTEND_BRANCH:-$BRANCH}' '$FRONTEND_REPO' '$FE'"
run "cd '$FE' && npm ci --no-audit --no-fund"
# VITE_API_URL فارغ ⇒ المسار النسبي /api ⇒ نفس الأصل ⇒ لا CORS
run "cd '$FE' && VITE_API_URL= npm run build"
run "cp -r '$FE/dist/.' '$NEW/public/'"
# بوّابة المشارك مُعطَّلة حتى إشعار آخر — لا تُبنى ولا تُنسَخ إلى portal-dist.
# KAFAAT_PORTAL=1 يعيدها، ولا بدّ معها من CANDIDATE_PORTAL_ENABLED=true في .env
# وcandidatePortal:true في features.js — وإلا نُشرت حزمةٌ تصطدم بمساراتٍ مغلقة.
if [[ ${KAFAAT_PORTAL:-0} == 1 ]]; then
  run "cd '$FE' && VITE_API_URL= npm run build:public"
  run "mkdir -p '$NEW/portal-dist' && cp -r '$FE/dist-public/.' '$NEW/portal-dist/'"
fi
run "rm -rf '$FE'"

# ── ٤) الحالة المشتركة ──
# .env والتخزين خارج الإصدار: يبقيان عبر النشرات ولا يُستنسخان
run "rm -rf '$NEW/storage' && ln -s '$SHARED/storage' '$NEW/storage'"
run "ln -sfn '$SHARED/.env' '$NEW/.env'"

# ── ٥) الهجرات ──
# قبل التبديل عمداً: الشيفرة الجديدة تفترض المخطّط الجديد. وهذا يُلزم أن
# تكون كل هجرة متوافقة رجعياً (تضيف ولا تحذف عموداً يقرؤه الإصدار العامل)،
# وإلا كسر الإصدارُ القديم بين الهجرة والتبديل.
run "cd '$NEW' && $PHP artisan migrate --force --no-interaction"

# ── ٦) خبز الذاكرات المؤقّتة ──
# ⚠ config:cache يُلغي قراءة .env وقت التشغيل: كل env() خارج ملفات الإعدادات
#   يرجع افتراضيَّه. لهذا تُقرأ إعدادات الأمان من config/security.php.
run "cd '$NEW' && $PHP artisan config:cache"
run "cd '$NEW' && $PHP artisan route:cache"
run "cd '$NEW' && $PHP artisan view:cache"
run "cd '$NEW' && $PHP artisan event:cache"

# ── ٧) فحص دخان قبل التقديم ──
# الإصدار يُختبر وهو خارج الخدمة: عطبٌ هنا لا يمسّ مستخدماً واحداً
run "cd '$NEW' && $PHP artisan about --only=environment >/dev/null"
run "cd '$NEW' && $PHP -r 'require \"vendor/autoload.php\"; \$app = require \"bootstrap/app.php\"; exit(0);'"
[[ -f "$NEW/public/index.html" ]] || [[ $DRY == 1 ]] || die "حزمة الواجهة لم تُبنَ"
[[ -f "$NEW/portal-dist/public.html" ]] || [[ $DRY == 1 ]] || die "حزمة البوّابة لم تُبنَ"

run "chmod -R g+w '$NEW/bootstrap/cache'"

# ── ٨) التبديل الذرّي ──
# mv -T على رابط رمزي عملية ذرّية: لا لحظة يرى فيها nginx مساراً ناقصاً
log "تبديل current → $STAMP"
run "ln -sfn '$NEW' '$CURRENT.tmp' && mv -Tf '$CURRENT.tmp' '$CURRENT'"

# ── ٩) إعادة تحميل المنفّذين ──
# opcache.validate_timestamps=0 يعني أن PHP لن يلاحظ الشيفرة الجديدة أبداً
# بلا إعادة التحميل. reload لا restart: يُنهي العمّال بلطف بعد إتمام طلباتهم.
run "sudo systemctl reload $FPM_SERVICE"
# queue:restart إشارةٌ للعمّال بالخروج بعد المهمة الجارية، فيعودون على الشيفرة الجديدة
run "$PHP '$CURRENT/artisan' queue:restart"

# ── ١٠) التحقّق بعد التقديم ──
sleep 2
if [[ $DRY == 0 ]]; then
  code=$(curl -sk -o /dev/null -w '%{http_code}' https://localhost/up --resolve "localhost:443:127.0.0.1" || echo 000)
  [[ "$code" == "200" ]] || { warn "فحص الحياة ردّ $code — راجِع فوراً، والرجوع: ./deploy.sh --rollback"; exit 1; }
fi

# ── ١١) تشذيب الإصدارات القديمة ──
run "ls -1dt '$RELEASES'/*/ | tail -n +$((KEEP+1)) | xargs -r rm -rf"

log "تمّ النشر: $STAMP ($DEPLOYED_SHA)"
log "الرجوع عند الحاجة: $0 --rollback"
