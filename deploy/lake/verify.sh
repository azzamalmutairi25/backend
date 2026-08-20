#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════════════
#  تحقّقُ بحيرة التقارير — يُشغَّل بعد كل تهيئة وبعد كل نشر
#
#  لا يفحص «هل تعمل» بل «هل ما مُنع ممنوعٌ فعلاً». كلُّ تأكيدٍ هنا يقابل
#  ضابطاً وُضع عمداً، ولو سقط أحدُها فالبحيرة مفتوحةٌ أكثر ممّا يظنّ أحد.
#
#  الاستخدام:
#    LAKE_HOST=127.0.0.1 LAKE_DB=kafaat_lake \
#    PW_OWNER=… PW_WRITER=… PW_READER=… PW_NARRATIVE=… ./verify.sh
# ════════════════════════════════════════════════════════════════════════
set -uo pipefail

PSQL="${PSQL:-psql}"
HOST="${LAKE_HOST:-127.0.0.1}"
PORT="${LAKE_PORT:-5432}"
DB="${LAKE_DB:-kafaat_lake}"

pass=0; fail=0

# يُنفّذ استعلاماً بدورٍ ما ويؤكّد النجاح أو الفشل المتوقَّع.
#   assert <expect: ok|deny> <role> <password> <label> <sql>
assert() {
  local expect="$1" role="$2" pw="$3" label="$4" sql="$5"
  local out rc
  out=$(PGPASSWORD="$pw" "$PSQL" -h "$HOST" -p "$PORT" -U "$role" -d "$DB" -Atc "$sql" 2>&1); rc=$?
  if [ "$expect" = ok ]; then
    if [ $rc -eq 0 ]; then echo "  ✅ $label"; pass=$((pass+1));
    else echo "  ❌ $label — كان يجب أن ينجح:"; echo "     $(echo "$out" | head -1)"; fail=$((fail+1)); fi
  else
    if [ $rc -ne 0 ]; then echo "  ✅ $label (مرفوض كما يجب)"; pass=$((pass+1));
    else echo "  ❌ $label — نجح وكان يجب أن يُرفض! النتيجة: $(echo "$out" | head -1)"; fail=$((fail+1)); fi
  fi
}

echo "═══ ١) العزل: القارئ لا يرى إلا العقد ═══"
assert ok   lake_reader "$PW_READER" "يقرأ contract_v1.reports"              "SELECT count(*) FROM contract_v1.reports;"
assert ok   lake_reader "$PW_READER" "يقرأ contract_v1.freshness"            "SELECT 1 FROM contract_v1.freshness;"
assert deny lake_reader "$PW_READER" "لا يرى curated.report_snapshot"        "SELECT 1 FROM curated.report_snapshot;"
assert deny lake_reader "$PW_READER" "لا يرى raw.report_events"              "SELECT 1 FROM raw.report_events;"
assert deny lake_reader "$PW_READER" "لا يقرأ السرد"                          "SELECT 1 FROM contract_v1.report_narratives;"
assert deny lake_reader "$PW_READER" "لا يقرأ خطة التطوير"                    "SELECT 1 FROM contract_v1.report_development_plan;"
assert deny lake_reader "$PW_READER" "لا يكتب في العقد"                       "CREATE TABLE contract_v1.x(i int);"

echo "═══ ٢) قارئ السرد يرث الأرقام ويضيف النصّ ═══"
assert ok   lake_narrative_reader "$PW_NARRATIVE" "يقرأ الأرقام بالوراثة"     "SELECT count(*) FROM contract_v1.reports;"
assert ok   lake_narrative_reader "$PW_NARRATIVE" "يقرأ السرد"                "SELECT count(*) FROM contract_v1.report_narratives;"
assert deny lake_narrative_reader "$PW_NARRATIVE" "لا يرى curated"            "SELECT 1 FROM curated.report_snapshot;"

echo "═══ ٣) الكاتب: يُدرج ولا يُعدّل ولا يُعيد البناء ═══"
assert ok   lake_writer "$PW_WRITER" "يقرأ raw"                               "SELECT count(*) FROM raw.report_events;"
assert ok   lake_writer "$PW_WRITER" "يقرأ تعدادَه عبر العقد"                 "SELECT count(*) FROM contract_v1.reports;"
assert deny lake_writer "$PW_WRITER" "لا يحذف من raw"                         "DELETE FROM raw.report_events WHERE true;"
assert deny lake_writer "$PW_WRITER" "لا يُعدّل raw"                           "UPDATE raw.report_events SET degraded=true;"
assert deny lake_writer "$PW_WRITER" "لا يقتطع raw"                           "TRUNCATE raw.report_events;"
assert deny lake_writer "$PW_WRITER" "لا يرى curated"                         "SELECT 1 FROM curated.report_snapshot;"
assert deny lake_writer "$PW_WRITER" "لا يُعيد البناء (replay)"                "SELECT lake.replay(NULL);"
assert deny lake_writer "$PW_WRITER" "لا يمحو (apply_erasure)"                 "SELECT lake.apply_erasure(repeat('0',64));"

echo "═══ ٤) الأقسام تُهيَّأ بامتياز الكاتب ═══"
# الضابط الحرج: ensure_partitions تُنفّذ CREATE TABLE، والكاتب لا يملك
# CREATE على raw. بغير SECURITY DEFINER تنجح كلُّ الاختبارات يوم الإطلاق
# ثم تتوقّف التغذية عند أوّل دورانِ شهر.
assert ok   lake_writer "$PW_WRITER" "يُهيّئ الأقسام (SECURITY DEFINER)"       "SELECT lake.ensure_partitions(15);"
assert ok   lake_writer "$PW_WRITER" "يُهيّئ من تاريخٍ سابق"                   "SELECT lake.ensure_partitions_from('2026-01-01');"

echo "═══ ٥) البيان يطابق المنح فعلاً ═══"
# البيان وعدٌ للمستهلك، والمنحُ هو الواقع. انفراجُهما لا يظهر في أيّ اختبار
# آخر: العرضُ موجود، والبيانُ يذكره، والقراءةُ تُرفض — فيقرأ المستهلك
# وعداً ويصطدم بمنع. وقع هذا فعلاً مع published_snapshot، وهو عرضٌ
# داخليّ يُخرج عمود payload بسرده كاملاً، فكان الوعدُ به تسريباً لا خطأً
# في التوثيق.
promised=$(PGPASSWORD="$PW_READER" "$PSQL" -h "$HOST" -p "$PORT" -U lake_reader -d "$DB" -Atc \
  "SELECT view_name FROM contract_v1.contract_manifest WHERE granted_to = 'lake_reader';" 2>/dev/null)
broken=0
for v in $promised; do
  PGPASSWORD="$PW_READER" "$PSQL" -h "$HOST" -p "$PORT" -U lake_reader -d "$DB" \
    -Atc "SELECT 1 FROM contract_v1.$v LIMIT 1;" >/dev/null 2>&1 || {
      echo "  ❌ البيان يَعِد بـ$v والقراءة مرفوضة"; broken=$((broken+1)); }
done
if [ "$broken" -eq 0 ]; then
  echo "  ✅ كل ما يَعِد به البيان مقروءٌ فعلاً ($(echo "$promised" | wc -w | tr -d ' ') عرضاً)"
  pass=$((pass+1))
else
  fail=$((fail+broken))
fi

# والعكس: العرضُ الداخليّ لا يُذكر في البيان ولا يُقرأ.
assert deny lake_reader "$PW_READER" "العرض الداخليّ published_snapshot محجوب" \
  "SELECT 1 FROM contract_v1.published_snapshot LIMIT 1;"

echo "═══ ٦) شريط التصنيف ═══"
assert deny lake_writer "$PW_WRITER" "يرفض صفّاً مُصنَّفاً" \
  "INSERT INTO raw.report_events (event_uuid,occurred_at,batch_id,emitter_seq,contract_version,event_type,subject_type,classification,payload,payload_sha256,payload_bytes)
   VALUES (gen_random_uuid(),now(),1,1,'report.v1','report.approved','report','secret','{}'::jsonb,repeat('a',64),2);"

echo
echo "═══════════════════════════════════"
echo "  ناجح: $pass   ساقط: $fail"
echo "═══════════════════════════════════"
[ "$fail" -eq 0 ] || exit 1
