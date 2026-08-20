-- ═══════════════════════════════════════════════════════════════════════
--  ٠٠ — بحيرة تقارير كفاءات: الأدوار وقاعدة البيانات والمخطّطات
--
--  يُنفَّذ مرّةً واحدة بصلاحية postgres، قبل أيّ شيءٍ آخر:
--    psql -U postgres -v p_owner=… -v p_writer=… -v p_reader=… \
--         -v p_narrative=… -f 00_bootstrap.sql
--
--  الملف قابلٌ لإعادة التنفيذ: كل إنشاءٍ محروسٌ بفحص وجود.
--  ما لا يُعاد: كلمات المرور — تُعيَّن عند الإنشاء فقط، وتُدار بعدها بـ
--  ALTER ROLE يدوياً من الخزنة.
-- ═══════════════════════════════════════════════════════════════════════

\set ON_ERROR_STOP on

-- ── الأدوار ───────────────────────────────────────────────────────────
--  أربعة أدوار لأربع صلاحيات مختلفة. الفصل هو الضابط نفسه، لا زينة:
--    lake_owner            — يملك كل شيء، ينفّذ الهجرات. لا يستخدمه التطبيق.
--    lake_writer           — يُدرج في raw ويستدعي الإسقاط. لا يحذف ولا يُعدّل.
--    lake_reader           — يقرأ العقد المنشور وحده. لا يرى curated ولا raw.
--    lake_narrative_reader — يضيف نصّ التقرير الحرّ. يُمنح بمراجعةٍ منفصلة.
--
--  lake_publisher (النسخ المنطقي إلى خادمٍ تالٍ) لا يُنشأ هنا:
--  يُنشأ يوم يوجد ذلك الخادم فعلاً، لا قبله.
-- ───────────────────────────────────────────────────────────────────────
--  ملاحظة تنفيذية: psql لا يستبدل متغيّراته داخل النصوص المُقتبَسة بالدولار،
--  فلا يصلح DO $$…$$ هنا. البناء بـ format ثم \gexec هو الطريق الوحيد
--  الذي يُبقي كلمةَ المرور خارج ملفّ SQL وخارج سجلّ الأوامر.
SELECT format('CREATE ROLE lake_owner LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION', :'p_owner')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lake_owner') \gexec

SELECT format('CREATE ROLE lake_writer LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION', :'p_writer')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lake_writer') \gexec

SELECT format('CREATE ROLE lake_reader LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION', :'p_reader')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lake_reader') \gexec

SELECT format('CREATE ROLE lake_narrative_reader LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION', :'p_narrative')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lake_narrative_reader') \gexec

-- قارئ السرد يرث قارئَ الأرقام. منحُ العضويّة يحتاج إدارةَ الدور،
-- فموضعُه هنا (postgres) لا في 06_grants الذي ينفّذه lake_owner.
GRANT lake_reader TO lake_narrative_reader;

-- ── القاعدة ───────────────────────────────────────────────────────────
--  الترتيب اللغوي C مطابقٌ لقاعدة المنصّة (datcollate=C) عمداً: تطابقُ
--  السلوك بين النظامين أهمّ من ترتيبٍ عربيٍّ صحيحٍ في القاعدة نفسها.
--  الترتيب العربي مسؤوليةُ المستهلك: ORDER BY … COLLATE "ar-SA-x-icu"
--  (موثّق في LAKE_CONTRACT_v1.md §الترتيب).
-- ───────────────────────────────────────────────────────────────────────
SELECT 'CREATE DATABASE kafaat_lake OWNER lake_owner ENCODING ''UTF8'' TEMPLATE template0 LC_COLLATE ''C'' LC_CTYPE ''C'''
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'kafaat_lake') \gexec

\connect kafaat_lake

ALTER DATABASE kafaat_lake SET timezone TO 'Asia/Riyadh';
-- صندوقٌ تحليليّ لا معاملاتيّ: الاستعلام الطويل هنا طبيعيّ، بخلاف 60s في المنصّة.
ALTER DATABASE kafaat_lake SET statement_timeout TO '300s';

REVOKE ALL ON DATABASE kafaat_lake FROM PUBLIC;
REVOKE ALL ON SCHEMA  public       FROM PUBLIC;

GRANT CONNECT ON DATABASE kafaat_lake TO lake_owner, lake_writer, lake_reader, lake_narrative_reader;

CREATE SCHEMA IF NOT EXISTS raw         AUTHORIZATION lake_owner;
CREATE SCHEMA IF NOT EXISTS curated     AUTHORIZATION lake_owner;
CREATE SCHEMA IF NOT EXISTS contract_v1 AUTHORIZATION lake_owner;
CREATE SCHEMA IF NOT EXISTS meta        AUTHORIZATION lake_owner;
CREATE SCHEMA IF NOT EXISTS lake        AUTHORIZATION lake_owner;

COMMENT ON SCHEMA raw         IS 'منطقة الهبوط — تُكتب ولا تُعدَّل. مصدر الحقيقة الوحيد في البحيرة.';
COMMENT ON SCHEMA curated     IS 'إسقاط مشتقّ بالكامل — يُهدم ويُعاد بناؤه بـ lake.replay() دون فقد.';
COMMENT ON SCHEMA contract_v1 IS 'العقد المنشور — الواجهة الوحيدة لأيّ مستهلك. لا يُكسر؛ يُضاف contract_v2 بجانبه.';
COMMENT ON SCHEMA meta        IS 'تشغيل: الدفعات، المطابقة، هجرات Laravel.';
COMMENT ON SCHEMA lake        IS 'الدوالّ: الإسقاط، الأقسام، إعادة البناء، المحو.';

-- ── حصانة أولى: لا أحد ينفّذ دالّةً لمجرّد وجودها ─────────────────────
--  PostgreSQL يمنح EXECUTE للعموم على كل دالّةٍ جديدة افتراضياً. تركُ ذلك
--  يعني أن lake_writer يستطيع استدعاء lake.replay() فيمسح curated كاملاً.
--  المنعُ هنا عامّ، والمنحُ لاحقاً فرديّ.
-- ───────────────────────────────────────────────────────────────────────
ALTER DEFAULT PRIVILEGES FOR ROLE lake_owner IN SCHEMA lake        REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE lake_owner IN SCHEMA contract_v1 REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE lake_owner IN SCHEMA curated     REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE lake_owner IN SCHEMA raw         REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE lake_owner IN SCHEMA meta        REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;

-- المخطّطات: الاستخدام يُمنح صراحةً لمن يحتاجه فقط.
GRANT USAGE ON SCHEMA raw, meta, lake TO lake_writer;
GRANT USAGE ON SCHEMA contract_v1     TO lake_writer;   -- للمطابقة عبر العقد لا عبر curated
GRANT USAGE ON SCHEMA contract_v1     TO lake_reader, lake_narrative_reader;
