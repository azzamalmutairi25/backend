#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════
#  نشرٌ بالدفع من جهاز المطوّر — للخادم الذي لا يملك بيانات اعتماد GitHub.
#
#  الأصل أن ينشر الخادم بنفسه (deploy.sh يستنسخ من المستودع)، لكنّ خادم
#  المركز داخل الشبكة ولا مفتاح نشر عليه بعد. فيُبنى الإصدار هنا ويُدفع.
#
#  يحافظ على الخصائص نفسها: إصدارات مرقّمة، وتبديل رابط ذرّي، ورجوعٌ في
#  ثانية، وفحص صحّة يُرجِع تلقائياً إن فشل.
#
#      ./push-release.sh                 # ينشر ما في مجلّد العمل
#      ./push-release.sh --dry-run       # يطبع الخطوات بلا تنفيذ
#      ./push-release.sh --skip-build    # لا يُعيد بناء الواجهة
#      ./push-release.sh --rollback      # يعود للإصدار السابق
# ════════════════════════════════════════════════════════════
set -Eeuo pipefail

HOST="${KAFAAT_HOST:-172.16.0.73}"
SSH_USER="${KAFAAT_SSH_USER:-tamkeenadmin}"
ASKPASS="${KAFAAT_ASKPASS:-/tmp/.ka}"   # مُساعد sudo على الخادم (انظر README)

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FRONTEND_DIR="${KAFAAT_FRONTEND_DIR:-$(cd "$BACKEND_DIR/../frontend" && pwd)}"

APP_DIR=/srv/kafaat
RELEASES="$APP_DIR/releases"
SHARED="$APP_DIR/shared"
CURRENT="$APP_DIR/current"
KEEP=5

DRY=0; SKIP_BUILD=0; ROLLBACK=0
for a in "$@"; do
  case "$a" in
    --dry-run)    DRY=1 ;;
    --skip-build) SKIP_BUILD=1 ;;
    --rollback)   ROLLBACK=1 ;;
    --host=*)     HOST="${a#*=}" ;;
    *) echo "خيار غير معروف: $a" >&2; exit 2 ;;
  esac
done

log()  { printf '\e[32m▸\e[0m %s\n' "$*"; }
warn() { printf '\e[33m!\e[0m %s\n' "$*"; }
die()  { printf '\e[31m✗\e[0m %s\n' "$*" >&2; exit 1; }

# كل أمر على الخادم يمرّ من هنا: نقطةٌ واحدة تحترم --dry-run، فلا ينفلت
# أمرٌ مدمّر أثناء تجربةٍ جافّة لأنّ أحدهم نسي الشرط في موضع.
remote() {
  if [[ $DRY == 1 ]]; then printf '   [dry] ssh: %s\n' "$*"; return 0; fi
  ssh "$SSH_USER@$HOST" "export SUDO_ASKPASS=$ASKPASS; set -Eeuo pipefail; $*"
}
# الاستعلامات القرائية تُنفَّذ حتى في التجربة الجافّة: تعطيلها يجعل المتغيّرات
# المشتقّة منها فارغة، فتُطبع خطواتٌ كاذبة لا تشبه ما سيجري فعلاً — وهو ما وقع:
# اسم خدمة FPM صار «php  [dry] ssh: …-fpm».
remote_read() { ssh "$SSH_USER@$HOST" "export SUDO_ASKPASS=$ASKPASS; $*"; }
# sudo كمستخدم التطبيق — الملفات يجب أن تُولد بملكيته لا بملكية حساب الدخول
as_app() { remote "sudo -A -u kafaat bash -c '$*'"; }

# ── سرد الإصدارات، الأحدث أولاً ──
#
# التوسيع داخل bash المرفوعة بـsudo لا خارجها. حساب الدخول (tamkeenadmin) لا
# يقرأ $RELEASES، فكان `ls $RELEASES/*/` يتوسّع إلى لا شيء ويُخرج «Permission
# denied» إلى stderr ويُرجع سلسلة فارغة — بلا أن يفشل الأمر.
#
# الأثر لم يكن تجميلياً: `--rollback` كان يقرأ فراغاً فيقول «لا يوجد إصدار
# سابق» ويخرج. أي أنّ **الرجوع كان معطّلاً**، ومعه الرجوع التلقائي عند فشل
# فحص الصحّة — وهو الحارس الذي يُفترض أن يعمل في أسوأ لحظة بالضبط.
# وتنظيف الإصدارات القديمة كان معطّلاً كذلك (تراكمت ستّة والحدّ خمسة).
releases_desc() { remote_read "sudo -A bash -c 'ls -1dt $RELEASES/*/ 2>/dev/null'"; }

# ── تحقّق مبكّر: بيئة الدفع سليمة قبل أن نلمس الخادم ──
command -v rsync >/dev/null || die "rsync غير موجود على هذا الجهاز"
ssh -o ConnectTimeout=8 -o BatchMode=yes "$SSH_USER@$HOST" true 2>/dev/null \
  || die "تعذّر الدخول إلى $SSH_USER@$HOST بمفتاح SSH"
remote "sudo -A -n true || sudo -A true" >/dev/null 2>&1 \
  || die "sudo غير متاح على الخادم — هيّئ $ASKPASS أولاً (انظر deploy/README.md)"

PHPV=$(remote_read "ls -1d /etc/php/*/fpm 2>/dev/null | sed 's|/etc/php/||;s|/fpm||' | sort -V | tail -1" || true)
PHPV=${PHPV:-8.4}
FPM_SERVICE="php${PHPV}-fpm"
log "PHP على الخادم: $PHPV"

# ── الرجوع: مُقدَّم لأنّ وقت الحاجة إليه ليس وقت قراءة سكربت ──
if [[ $ROLLBACK == 1 ]]; then
  prev=$(releases_desc | sed -n 2p || true)
  [[ -n "${prev:-}" ]] || die "لا يوجد إصدار سابق"
  log "الرجوع إلى $(basename "${prev%/}")"
  as_app "ln -sfn ${prev%/} $CURRENT.tmp && mv -Tf $CURRENT.tmp $CURRENT"
  remote "sudo -A systemctl reload $FPM_SERVICE"
  as_app "php $CURRENT/artisan queue:restart"
  warn "الهجرات لا تُرجَع تلقائياً — راجِعها إن كان الإصدار الفاشل قد هاجر."
  exit 0
fi

# ── ١) بناء الواجهة محلياً (لا node على الخادم) ──
if [[ $SKIP_BUILD == 0 ]]; then
  log "بناء الواجهة الداخلية…"
  [[ $DRY == 1 ]] || (cd "$FRONTEND_DIR" && npm run build >/dev/null)
  # بوّابة المرشح مُعطَّلة حتى إشعار آخر (config/features.php ⇒ candidate_portal).
  # لا تُبنى ولا تُشحن: حزمةٌ منشورةٌ على الإنترنت لخدمةٍ مغلقةِ المسارات سطحُ
  # هجومٍ بلا مقابل. لإعادتها: KAFAAT_PORTAL=1 مع تشغيل المفتاحين.
  if [[ ${KAFAAT_PORTAL:-0} == 1 ]]; then
    log "بناء بوّابة المرشح…"
    [[ $DRY == 1 ]] || (cd "$FRONTEND_DIR" && npm run build:public >/dev/null)
  else
    log "بوّابة المرشح مُعطَّلة — تُخطّى (KAFAAT_PORTAL=1 لإعادتها)"
  fi
fi
[[ $DRY == 1 || -f "$FRONTEND_DIR/dist/index.html" ]] || die "لا توجد حزمة واجهة مبنيّة في $FRONTEND_DIR/dist"

# ── ٢) مجلّد الإصدار الجديد ──
TS=$(date +%Y%m%d%H%M%S)
NEW="$RELEASES/$TS"
STAGE="/tmp/kafaat-release-$TS"
log "الإصدار الجديد: $TS"

remote "rm -rf $STAGE && mkdir -p $STAGE"

# ── ٣) دفع الشيفرة ──
# الاستثناءات مقصودة: vendor يُبنى على الخادم بنسخة PHP الخاصة به،
# و.env وstorage مشتركان بين الإصدارات ولا يُنسخان مع أيٍّ منها.
log "دفع شيفرة الخادم…"
if [[ $DRY == 0 ]]; then
  rsync -az --delete \
    --exclude '.git' --exclude 'node_modules' --exclude 'vendor' \
    --exclude '.env' --exclude '.env.*' \
    --exclude 'storage' --exclude 'public/assets' --exclude 'public/index.html' \
    --exclude 'load-test/tokens.json' --exclude 'load-test/report-*.json' \
    "$BACKEND_DIR/" "$SSH_USER@$HOST:$STAGE/"

  log "دفع حزمة الواجهة…"
  # ⚠ بلا --delete هنا مهما بدا مغرياً. حزمة الواجهة تُسكب فوق public/ الخاص
  # بلارافيل، و--delete يمسح كل ما ليس في dist/ — أي index.php نفسه. النتيجة
  # موقعٌ يبدو سليماً (nginx يخدم index.html الساكن) وواجهةُ برمجةٍ ميتة
  # بالكامل: "Primary script unknown" و404 على كل مسار. وقع هذا فعلاً.
  # و--delete غير لازم أصلاً: كل إصدار مجلّد جديد، فلا بقايا تُمسح.
  rsync -az "$FRONTEND_DIR/dist/" "$SSH_USER@$HOST:$STAGE/public/"
  # حزمة البوّابة تُشحن معه لتكون جاهزة لخادم الـDMZ — متى كانت مُشغَّلة
  [[ ${KAFAAT_PORTAL:-0} == 1 && -d "$FRONTEND_DIR/dist-public" ]] && \
    rsync -az "$FRONTEND_DIR/dist-public/" "$SSH_USER@$HOST:$STAGE/portal-dist/"

  # حارسٌ صريح قبل التركيب: مدخل لارافيل موجود في الحزمة المدفوعة
  ssh "$SSH_USER@$HOST" "test -f $STAGE/public/index.php" \
    || die "public/index.php غائب عن الإصدار المُجهَّز — لا تُركّب إصداراً بلا مدخل"
fi

# ── ٤) تركيب الإصدار ──
log "تركيب الإصدار…"
remote "sudo -A mkdir -p $NEW && sudo -A cp -a $STAGE/. $NEW/ && sudo -A chown -R kafaat:kafaat $NEW && rm -rf $STAGE"

# vendor: نسخة الإصدار السابق بروابط صلبة ثم مصالحة بـcomposer — أسرع بكثير
# من تنزيلٍ كامل، والمصالحة تضمن مطابقة composer.lock تماماً.
PREV=$(releases_desc | sed -n 2p || true)
if [[ -n "${PREV:-}" && $DRY == 0 ]]; then
  log "نسخ vendor من $(basename "${PREV%/}") ثم المصالحة…"
  as_app "cp -al ${PREV%/}/vendor $NEW/vendor 2>/dev/null || cp -a ${PREV%/}/vendor $NEW/vendor"
fi
as_app "cd $NEW && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress 2>&1 | tail -3"

# ── ٥) الربط بالمشترك ──
log "ربط .env وstorage…"
as_app "rm -rf $NEW/storage && ln -s $SHARED/storage $NEW/storage && ln -s $SHARED/.env $NEW/.env"

# ── ٦) لقطة القاعدة قبل الهجرة ──
# الرجوع في هذا السكربت يبدّل رابطاً رمزياً — والهجرات لا تُرجَع معه. فما لم
# تُؤخَذ لقطة هنا، فكل نشرة تحمل هجرةً هي عمليةٌ بلا رجعة. النسخ الاحتياطي
# اليومي لا يكفي: هو أقدم من كل ما وقع بين منتصف الليل والآن.
#
# تُؤخذ من خادم التطبيق عبر pg_dump على الشبكة (لا حاجة لدخول خادم القاعدة)،
# ويُفشَل النشر إن لم تُكتب — لأن نشرةً بلا شبكة أمان أسوأ من نشرةٍ مؤجَّلة.
if [[ $DRY == 1 ]]; then
  printf '   [dry] لقطة قاعدة إلى %s\n' "$SHARED/backups/pre-release-$TS.dump"
else
  log "لقطة القاعدة قبل الهجرة…"
  as_app "mkdir -p $SHARED/backups && cd $NEW && \
    set -a && . $SHARED/.env && set +a && \
    PGPASSWORD=\"\$DB_PASSWORD\" pg_dump -Fc \
      -h \"\$DB_HOST\" -p \"\${DB_PORT:-5432}\" -U \"\$DB_USERNAME\" \"\$DB_DATABASE\" \
      > $SHARED/backups/pre-release-$TS.dump"

  SNAP_BYTES=$(remote_read "sudo -A stat -c%s $SHARED/backups/pre-release-$TS.dump 2>/dev/null" || echo 0)
  # مِلفٌّ أصغر من ١٠٠ كيلوبايت ليس قاعدةَ إنتاج: غالباً pg_dump سقط وكتب
  # ترويسةً فارغة. نتوقّف قبل الهجرة، والإصدار الجديد لم يُقدَّم بعد.
  [[ ${SNAP_BYTES:-0} -gt 102400 ]] \
    || die "اللقطة لم تُكتب أو حجمها مريب (${SNAP_BYTES:-0} بايت) — أوقفتُ النشر قبل الهجرة"
  log "اللقطة: $((SNAP_BYTES/1024/1024))MB في $SHARED/backups/pre-release-$TS.dump"

  # يُحتفَظ بآخر عشر لقطات — تحمل بياناتٍ شخصية، فلا تُترك تتراكم بلا حدّ
  remote "sudo -A bash -c 'ls -1dt $SHARED/backups/pre-release-*.dump 2>/dev/null | tail -n +11 | xargs -r rm -f'" || true
fi

# ── ٧) الهجرات ──
log "الهجرات…"
as_app "cd $NEW && php artisan migrate --force --no-interaction 2>&1 | tail -5"

# ── ٨) خبز الذاكرة ──
# يُخبَز على الإصدار الجديد قبل التبديل: خبزُه بعده يترك نافذةً يُقدَّم فيها
# الإصدار الجديد بإعدادات غير مخبوزة.
log "خبز الإعدادات والمسارات…"
as_app "cd $NEW && php artisan config:cache && php artisan route:cache && php artisan view:cache"

# ── ٩) التبديل الذرّي ──
log "تبديل الرابط…"
as_app "ln -sfn $NEW $CURRENT.tmp && mv -Tf $CURRENT.tmp $CURRENT"
# إلزامي مع opcache.validate_timestamps=0: بدونه يبقى العمّال على شيفرة قديمة
remote "sudo -A systemctl reload $FPM_SERVICE"
as_app "cd $CURRENT && php artisan queue:restart"

# ── ١٠) فحص الصحّة، ورجوعٌ تلقائي عند الفشل ──
if [[ $DRY == 0 ]]; then
  log "فحص الصحّة…"
  sleep 2
  # ثلاثة فحوص لا واحد. الواجهة وحدها تكذب: nginx يخدم index.html ساكناً
  # فيردّ 200 حتى وPHP لا يعمل إطلاقاً. الحكم من مسارٍ يمرّ بـPHP ومسارٍ
  # يمرّ بموجّه الـAPI.
  up=$(curl -sk -o /dev/null -w '%{http_code}' "https://$HOST/up" || echo 000)
  spa=$(curl -sk -o /dev/null -w '%{http_code}' "https://$HOST/" || echo 000)
  # دخولٌ بجسمٍ فارغ: 422 تعني أن الموجّه والتحقّق يعملان (401/419 مقبولة أيضاً)
  api=$(curl -sk -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d '{}' "https://$HOST/api/login" || echo 000)
  log "الصحّة: /up=$up  /=$spa  /api/login=$api"
  if [[ "$up" != "200" || "$spa" != "200" || "$api" == "404" || "$api" == "000" ]]; then
    warn "فشل فحص الصحّة — رجوعٌ تلقائي"
    "$0" --rollback --host="$HOST"
    die "فشل النشر ورُجِع إلى الإصدار السابق"
  fi
fi

# ── ١١) تنظيف الإصدارات القديمة ──
remote "sudo -A bash -c 'ls -1dt $RELEASES/*/' | tail -n +$((KEEP+1)) | xargs -r sudo -A rm -rf"

log "تمّ النشر: $TS"
