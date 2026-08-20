#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════
#  بنية مفاتيح كفاءات — جذرٌ داخلي مقيَّد + شهادة خادم قصيرة.
#
#  ⚠ الفصل هو جوهر هذا السكربت، لا تفصيلٌ فيه:
#  مفتاح الجذر (ca.key) **لا يوضع على خادم الويب إطلاقاً**. خادم الويب أكثر
#  ما تُعرَّض له المنصّة، وتسريب مفتاح خادمٍ يُعالَج بإصدارٍ جديد، أمّا تسريب
#  مفتاح الجذر فيمنح المهاجم توقيعَ شهادةٍ لأي اسم تقبلها كل الأجهزة التي
#  نُصِّب عليها الجذر — ولا يُعالَج إلا بإعادة تنصيبٍ جماعية.
#
#  والجذر مقيَّد بـnameConstraints: بدونها يصير الجذر المُنصَّب على أجهزة
#  الوزارة موثوقاً لكل نطاقات الإنترنت لا لنطاقات المنصّة. وهو الضابط الوحيد
#  الذي تُنفّذه المتصفّحات فعلاً (NSS وCryptoAPI وSecurity.framework).
#
#  الاستعمال — الأمران الأوّلان على جهاز الإدارة لا على الخادم:
#      ./kafaat-pki.sh init-ca            # مرّة واحدة: ينشئ الجذر المقيَّد
#      ./kafaat-pki.sh issue              # يُصدر شهادة خادم ويطبع أمر النشر
#      ./kafaat-pki.sh verify             # يتحقّق من الخادم الحيّ
#      ./kafaat-pki.sh moi-csr            # طلب توقيع لسلطة إصدار الوزارة
# ════════════════════════════════════════════════════════════
set -Eeuo pipefail

# مجلّد الجذر: خارج المستودع وخارج الخادم. يُنقل إلى وسيط آمن بعد الإنشاء.
CA_DIR="${KAFAAT_CA_DIR:-$HOME/.kafaat-ca}"
OUT_DIR="${KAFAAT_OUT_DIR:-$CA_DIR/issued}"
HOST="${KAFAAT_HOST:-172.16.0.73}"
SSH_USER="${KAFAAT_SSH_USER:-tamkeenadmin}"
ASKPASS="${KAFAAT_ASKPASS:-/tmp/.ka}"

# أسماء الشهادة. الاسم الأول هو رابط المنصّة المعتمد، والعنوان العددي يبقى في
# SAN ليظل الوصول به عاملاً. localhost و127.0.0.1 محذوفة عمداً: لا تُستعمل في
# وصولٍ حقيقي، ووجودها يُخالف قيود الأسماء أدناه فتُرفض الشهادة كلّها.
# الاسم المعتمد كما يحلّه DNS فعلاً — لا النطاق المجرّد `moitp.gov.sa`،
# فهو لا يحلّ ولا يُقصد به وصول. وقيدُ الأسماء أدناه يغطّي النطاق كلّه
# فيشمل هذا الاسم وما تحته.
DNS1=tamkeentp.moitp.gov.sa
DNS2=kafaat.internal.gov.sa
DNS3=kafaat.local
SANS="IP:${HOST},DNS:${DNS1},DNS:${DNS2},DNS:${DNS3}"

# القيود: ما لا يقع تحتها لا يُقبل من هذا الجذر مهما وُقِّع به.
# النطاق العددي 172.16.0.0/16 يغطّي الشبكة الداخلية وحدها.
#
# ⚠ moitp.gov.sa لا يقع تحت قيود الجذر المُنشأ سابقاً (internal.gov.sa وحدها
#   كانت مسموحة)، والقيود مخبوزة في شهادة الجذر — فإضافتها هنا لا تسري على
#   جذرٍ قائم. المتصفّح يرفض أي شهادة لهذا الاسم موقّعة بالجذر الحالي مهما
#   صحّت. المسار الصحيح: `moi-csr` وشهادة من سلطة إصدار الوزارة — تعمل بلا
#   تنصيب جذرٍ على الأجهزة. إعادة إنشاء الجذر بديلٌ أخير: يُبطل الثقة
#   المنصَّبة على كل جهاز ويستلزم توزيعاً جديداً.
NAME_CONSTRAINTS="critical,permitted;DNS:moitp.gov.sa,permitted;DNS:internal.gov.sa,permitted;DNS:kafaat.local,permitted;IP:172.16.0.0/255.255.0.0"

SUBJ_BASE="/C=SA/O=Tamkeen Alkafaat Center"
LEAF_DAYS=397     # دون حدّ Apple (٣٩٨ يوماً) بهامش
CA_DAYS=3650

log()  { printf '\e[32m▸\e[0m %s\n' "$*"; }
warn() { printf '\e[33m!\e[0m %s\n' "$*"; }
die()  { printf '\e[31m✗\e[0m %s\n' "$*" >&2; exit 1; }

cmd="${1:-}"; [[ -n "$cmd" ]] || die "استعمل: init-ca | issue | verify | moi-csr"

# حارسٌ صريح: هذا السكربت لا يُشغَّل على الخادم — وجود مفتاح الجذر عليه هو
# بالضبط ما بُني لمنعه.
if [[ "$cmd" == "init-ca" || "$cmd" == "issue" ]]; then
  [[ "$(hostname)" != "tamkeen" ]] || die "لا تُشغّله على خادم الويب — مفتاح الجذر لا يوضع عليه"
fi

case "$cmd" in

# ── إنشاء الجذر المقيَّد (مرّة واحدة) ──
init-ca)
  [[ ! -f "$CA_DIR/ca.key" ]] || die "الجذر موجود في $CA_DIR — إنشاء جذرٍ جديد يُبطل الثقة المنصَّبة على كل جهاز"
  mkdir -p "$CA_DIR"; chmod 700 "$CA_DIR"

  log "إنشاء الجذر المقيَّد…"
  openssl req -x509 -newkey rsa:4096 -sha256 -days "$CA_DAYS" -nodes \
    -keyout "$CA_DIR/ca.key" -out "$CA_DIR/ca.crt" \
    -subj "${SUBJ_BASE}/CN=Tamkeen Alkafaat Internal Root CA" \
    -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
    -addext "keyUsage=critical,keyCertSign,cRLSign" \
    -addext "nameConstraints=${NAME_CONSTRAINTS}" \
    -addext "subjectKeyIdentifier=hash" >/dev/null 2>&1
  chmod 600 "$CA_DIR/ca.key"; chmod 644 "$CA_DIR/ca.crt"

  log "الجذر: $CA_DIR/ca.crt"
  openssl x509 -noout -fingerprint -sha256 -in "$CA_DIR/ca.crt" | sed 's/^/   /'
  echo
  warn "انقل $CA_DIR/ca.key إلى وسيطٍ آمن غير متصل، واحذفه من هذا الجهاز بعد الإصدار."
  ;;

# ── إصدار شهادة الخادم ──
issue)
  [[ -f "$CA_DIR/ca.key" ]] || die "لا جذر في $CA_DIR — شغّل init-ca أولاً (أو أحضِر المفتاح من الوسيط الآمن)"
  mkdir -p "$OUT_DIR"; chmod 700 "$OUT_DIR"

  log "إصدار شهادة الخادم (${LEAF_DAYS} يوماً)…"
  openssl req -new -newkey rsa:2048 -sha256 -nodes \
    -keyout "$OUT_DIR/server.key" -out "$OUT_DIR/server.csr" \
    -subj "${SUBJ_BASE}/CN=${DNS1}" >/dev/null 2>&1

  cat > "$OUT_DIR/leaf.ext" <<EOF
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=${SANS}
subjectKeyIdentifier=hash
authorityKeyIdentifier=keyid,issuer
EOF

  openssl x509 -req -in "$OUT_DIR/server.csr" \
    -CA "$CA_DIR/ca.crt" -CAkey "$CA_DIR/ca.key" -CAcreateserial \
    -out "$OUT_DIR/server.crt" -days "$LEAF_DAYS" -sha256 \
    -extfile "$OUT_DIR/leaf.ext" >/dev/null 2>&1

  # ── تحقّقان لا واحد ──
  # الأول: السلسلة تتحقّق. الثاني — وهو الأهم — أن القيود تعمل فعلاً:
  # شهادةٌ لاسمٍ خارج النطاق المسموح يجب أن تُرفض. قيدٌ مكتوبٌ ولا يُنفَّذ
  # أسوأ من غيابه، لأنه يمنح ثقةً لا أساس لها.
  openssl verify -CAfile "$CA_DIR/ca.crt" "$OUT_DIR/server.crt" >/dev/null \
    || die "الشهادة لا تتحقّق من الجذر"
  log "السلسلة تتحقّق ✓"

  tmp=$(mktemp -d)
  openssl req -new -newkey rsa:2048 -sha256 -nodes -keyout "$tmp/x.key" -out "$tmp/x.csr" \
    -subj "${SUBJ_BASE}/CN=www.google.com" >/dev/null 2>&1
  printf 'basicConstraints=critical,CA:FALSE\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:www.google.com\n' > "$tmp/x.ext"
  openssl x509 -req -in "$tmp/x.csr" -CA "$CA_DIR/ca.crt" -CAkey "$CA_DIR/ca.key" \
    -CAcreateserial -out "$tmp/x.crt" -days 30 -sha256 -extfile "$tmp/x.ext" >/dev/null 2>&1
  if openssl verify -CAfile "$CA_DIR/ca.crt" "$tmp/x.crt" >/dev/null 2>&1; then
    rm -rf "$tmp"; die "قيود الأسماء لا تعمل — الجذر وقّع www.google.com وقُبِلت. لا تنشر هذا الجذر."
  fi
  log "قيود الأسماء تعمل ✓ (شهادة خارج النطاق رُفضت)"
  rm -rf "$tmp"

  chmod 600 "$OUT_DIR/server.key"; chmod 644 "$OUT_DIR/server.crt"
  rm -f "$OUT_DIR/server.csr" "$OUT_DIR/leaf.ext"

  echo
  log "جاهزة في $OUT_DIR — للنشر:"
  echo "   $0 deploy"
  ;;

# ── نشر الشهادة والمفتاح إلى الخادم (بلا مفتاح الجذر) ──
deploy)
  [[ -f "$OUT_DIR/server.crt" ]] || die "لا شهادة مُصدَرة — شغّل issue أولاً"
  log "نشر الشهادة والمفتاح فقط (مفتاح الجذر يبقى هنا)…"
  scp -q "$OUT_DIR/server.crt" "$OUT_DIR/server.key" "$SSH_USER@$HOST:/tmp/"
  ssh "$SSH_USER@$HOST" "export SUDO_ASKPASS=$ASKPASS
    set -e
    sudo -A install -o root -g root -m 644 /tmp/server.crt /etc/ssl/kafaat/server.crt
    sudo -A install -o root -g root -m 600 /tmp/server.key /etc/ssl/kafaat/server.key
    shred -u /tmp/server.crt /tmp/server.key 2>/dev/null || rm -f /tmp/server.crt /tmp/server.key
    sudo -A nginx -t >/dev/null 2>&1 && sudo -A systemctl reload nginx && echo '✓ أُعيد تحميل nginx'"
  ;;

# ── التحقّق من الخادم الحيّ كما يفعل المتصفّح ──
verify)
  [[ -f "$CA_DIR/ca.crt" ]] || die "لا جذر في $CA_DIR"
  log "الشهادة المُقدَّمة:"
  echo | openssl s_client -connect "$HOST:443" 2>/dev/null \
    | openssl x509 -noout -subject -issuer -dates | sed 's/^/   /'
  echo
  code=$(curl -s --cacert "$CA_DIR/ca.crt" -o /dev/null -w '%{http_code}' "https://$HOST/" || echo 000)
  [[ "$code" == "200" ]] && log "التحقّق الكامل بالجذر: 200 ✓" || die "التحقّق الكامل أرجع $code"
  ;;

# ── طلب توقيع من سلطة إصدار الوزارة (المسار النهائي الصحيح) ──
moi-csr)
  mkdir -p "$OUT_DIR"; chmod 700 "$OUT_DIR"
  openssl req -new -newkey rsa:2048 -sha256 -nodes \
    -keyout "$OUT_DIR/moi.key" -out "$OUT_DIR/moi.csr" \
    -subj "${SUBJ_BASE}/CN=${DNS1}" \
    -addext "subjectAltName=${SANS}" \
    -addext "extendedKeyUsage=serverAuth" >/dev/null 2>&1
  chmod 600 "$OUT_DIR/moi.key"
  log "طلب التوقيع: $OUT_DIR/moi.csr"
  echo "   سلّمه لفريق البنية التحتية. الشهادة الموقّعة من سلطة الوزارة تُغني"
  echo "   عن تنصيب أي جذر على الأجهزة، لأن جذر الوزارة منصَّبٌ فيها أصلاً."
  ;;

*) die "أمر غير معروف: $cmd" ;;
esac
