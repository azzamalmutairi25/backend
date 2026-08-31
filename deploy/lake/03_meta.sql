-- ═══════════════════════════════════════════════════════════════════════
--  ٠٣ — meta: التشغيل
--
--  ما تحتاجه العمليةُ لتعرف حالَها: هل وصل كلُّ شيء، وماذا نقص، وماذا
--  مُحي ومتى، وأيُّ إصدارٍ من المخطّط يعمل الآن.
--  هنا أيضاً يهبط جدول migrations الخاص بـ Laravel (search_path يضع meta
--  أوّلاً على اتصال الـDDL) — بعيداً عن raw التي أُعلنت غيرَ قابلةٍ للتعديل.
-- ═══════════════════════════════════════════════════════════════════════

\set ON_ERROR_STOP on
SET search_path = meta, public;

-- ── ٣-١ إصدار المخطّط ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS meta.schema_version (
  component   text        PRIMARY KEY,
  version     text        NOT NULL,
  applied_at  timestamptz NOT NULL DEFAULT now(),
  note        text        NULL
);

INSERT INTO meta.schema_version (component, version, note) VALUES
  ('lake',        '1.0.0', 'بحيرة تقارير كفاءات — الإصدار الأول'),
  ('contract_v1', '1.0.0', 'العقد المنشور — مجهوليّة كاملة، أرقامٌ ومؤشراتٌ وقطاعات')
ON CONFLICT (component) DO NOTHING;

-- ── ٣-٢ جولات المطابقة ────────────────────────────────────────────────
--  المصالحة تُجيب سؤالاً واحداً: هل ما في المنصّة موجودٌ في البحيرة؟
--  الفروق ثلاثة أنواع، ولكلٍّ منها معنى مختلف تماماً:
--    missing   — في المنصّة وليس في البحيرة  → نقصُ تغذية، يُعاد إرساله
--    divergent — في الاثنين والقيمة مختلفة   → انجرافُ نمذجة، يُحقَّق فيه
--    vanished  — في البحيرة وليس في المنصّة  → حُذف من المصدر (لا حذفَ ناعماً
--                في المنصّة إطلاقاً) — وهذا متوقَّعٌ لا خطأ، ويُسجَّل شاهداً.
CREATE TABLE IF NOT EXISTS meta.reconciliation_runs (
  run_id            bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  started_at        timestamptz NOT NULL DEFAULT now(),
  finished_at       timestamptz NULL,
  source_count      integer     NULL,
  lake_count        integer     NULL,
  missing_count     integer     NULL,
  divergent_count   integer     NULL,
  vanished_count    integer     NULL,
  -- التقارير المُصنَّفة ناقصةٌ بالتصميم لا بالخطأ. تُحصى مرّةً واحدة
  -- في الجولة، ولا تُسجَّل صفّاً صفّاً في سجلّ تدقيق المنصّة كل ليلة.
  suppressed_count  integer     NULL,
  repaired_count    integer     NULL,
  detail            jsonb       NULL
);

-- ── ٣-٣ سجلّ المحو ────────────────────────────────────────────────────
--  حقُّ المحو تحت PDPL يحتاج إثباتَ تنفيذ. الصفوف تُمحى والبصمةُ تبقى:
--  «أُتلف هذا، في هذا الوقت، بهذا الطلب».
CREATE TABLE IF NOT EXISTS meta.erasure_log (
  erasure_id     bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  requested_at   timestamptz NOT NULL DEFAULT now(),
  person_ref     char(64)    NOT NULL,
  reason         text        NULL,
  requested_by   text        NULL,
  events_erased  integer     NULL,
  snapshots_erased integer   NULL,
  documents_blanked integer  NULL
);
CREATE INDEX IF NOT EXISTS erasure_person_idx ON meta.erasure_log (person_ref);

-- ── ٣-٣-ب إعدادات البحيرة ────────────────────────────────────────────
--  حدُّ الإخفاء الإحصائي مقروءٌ من هنا لا محفورٌ في العروض. كان رقماً
--  مكرَّراً في أربعة مواضع من SQL بينما يُوهم مفتاحٌ في config/lake.php
--  أنه قابلٌ للضبط — فتغييرُ المفتاح لا يُغيّر شيئاً، وهو أسوأ من ثابتٍ
--  معلَن. مصدر الحقيقة هنا، وتغييرُه يسري على العروض فوراً.
CREATE TABLE IF NOT EXISTS meta.lake_settings (
  key        text PRIMARY KEY,
  value      text NOT NULL,
  note       text NULL,
  updated_at timestamptz NOT NULL DEFAULT now()
);

INSERT INTO meta.lake_settings (key, value, note) VALUES
  ('k_anonymity', '5', 'أقلّ عددٍ في الخليّة قبل حجب قيمتها. الإجمالي الوطني لا يُحجب أبداً.')
ON CONFLICT (key) DO NOTHING;

-- ── ٣-٤ الحجر الصحّي ──────────────────────────────────────────────────
--  الصفّ الذي رفضته القاعدة (تصنيفٌ أفلت، حمولةٌ لا تُسقَط) يُعزل بعد N
--  محاولات بدل أن يُوقف التغذية إلى الأبد. صفٌّ واحدٌ تالف لا يُسكت
--  المنصّةَ كلَّها — وهذا الفرق بين «يفشل بصوتٍ مسموع» و«يتوقّف بصمت».
--  ملاحظة: الحجر الصحّي الفعليّ يقع على الخادم الأساسي في
--  report_lake_outbox.failed_at — الصفّ الذي ترفضه البحيرة لا يصلها أصلاً،
--  فلا يمكن حجزُه فيها. هذا الجدول يستقبل ما تُقرّر عزلَه بعد الهبوط
--  (حمولةٌ هبطت ثم تعذّر إسقاطها)، ويبقى فارغاً ما دام ذلك لم يقع.
CREATE TABLE IF NOT EXISTS meta.quarantine (
  quarantine_id bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  quarantined_at timestamptz NOT NULL DEFAULT now(),
  emitter_seq   bigint      NULL,
  event_uuid    uuid        NULL,
  event_type    text        NULL,
  attempts      integer     NULL,
  last_error    text        NULL,
  payload       jsonb       NULL
);

GRANT USAGE  ON SCHEMA meta TO lake_writer;
GRANT SELECT, INSERT, UPDATE ON meta.reconciliation_runs, meta.quarantine TO lake_writer;
GRANT SELECT ON meta.schema_version, meta.lake_settings TO lake_writer, lake_reader, lake_narrative_reader;
GRANT USAGE  ON ALL SEQUENCES IN SCHEMA meta TO lake_writer;
