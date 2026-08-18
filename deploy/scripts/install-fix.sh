#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════
#  تهيئة النشر لمرّةٍ واحدة — يُشغَّل بصلاحية الجذر.
#
#  بعده يصير النشر ممكناً بلا كلمة مرور عبر قاعدة sudo القائمة أصلاً:
#      (kafaat) NOPASSWD: /srv/kafaat/current/deploy/scripts/deploy.sh
#
#  ما يفعله:
#   ١) يضع سكربت النشر المُصلَح مكان المنشور (المنشور يستنسخ عبر SSH المحجوب،
#      ويشترط npm غير المنصَّب، ويفحص حزمة بوّابةٍ لا تُبنى — فيموت بعد الهجرات)
#   ٢) يكتب إعدادات النشر في ملفٍّ بجوار .env — القاعدة لا تقبل متغيّرات بيئة
#   ٣) ينسخ حزمة الواجهة المبنيّة على جهاز التطوير إلى الحالة المشتركة
#
#  ولا يمسّ قاعدة البيانات ولا .env ولا الإصدار المُقدَّم حالياً.
# ════════════════════════════════════════════════════════════
set -Eeuo pipefail
[[ $(id -u) == 0 ]] || { echo "شغّله بصلاحية الجذر (sudo)" >&2; exit 1; }

SRC=/tmp/kafaat-deploy
SHARED=/srv/kafaat/shared
TARGET=$(readlink -f /srv/kafaat/current)/deploy/scripts/deploy.sh

[[ -f "$SRC/deploy.sh" ]]       || { echo "لا يوجد $SRC/deploy.sh" >&2; exit 1; }
[[ -f "$SRC/dist/index.html" ]] || { echo "لا توجد حزمة واجهة في $SRC/dist" >&2; exit 1; }

# ١) نسخة من المنشور قبل استبداله — الرجوع نسخٌ عكسي لا إعادة بناء
cp -a "$TARGET" "$TARGET.before-fix.$(date +%Y%m%d%H%M%S)"
install -m 755 -o kafaat -g kafaat "$SRC/deploy.sh" "$TARGET"
echo "▸ سكربت النشر حُدِّث: $TARGET"

# ٢) إعدادات النشر
install -d -m 755 -o kafaat -g kafaat "$SHARED"
cat > "$SHARED/deploy.conf" <<'CONF'
# فرع النشر ومصدر حزمة الواجهة — قرارُ تنصيبٍ يبقى، لا وسيطُ سطرِ أمرٍ يُنسى
# فيُنشر من الفرع الخطأ بصمت.
KAFAAT_BRANCH=moi-identity
KAFAAT_FRONTEND_DIST=/srv/kafaat/shared/frontend-dist
CONF
chown kafaat:kafaat "$SHARED/deploy.conf"
chmod 640 "$SHARED/deploy.conf"
echo "▸ الإعدادات كُتبت: $SHARED/deploy.conf"

# ٣) حزمة الواجهة — تُبنى على جهاز التطوير لأن الخادم بلا node ولا npm،
#    وهو الصواب: أدوات البناء لا تُنصَّب في الإنتاج
rm -rf "$SHARED/frontend-dist"
cp -a "$SRC/dist" "$SHARED/frontend-dist"
chown -R kafaat:kafaat "$SHARED/frontend-dist"
echo "▸ حزمة الواجهة نُسخت: $SHARED/frontend-dist"

echo
echo "✓ تمّت التهيئة. النشر الآن بلا كلمة مرور:"
echo "    sudo -n -u kafaat /srv/kafaat/current/deploy/scripts/deploy.sh --dry-run"
