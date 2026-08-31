-- ═══════════════════════════════════════════════════════════════════════
--  ٠١ — raw: منطقة الهبوط
--
--  يُنفَّذ بصلاحية lake_owner على kafaat_lake.
--  كل ما يدخل البحيرة يمرّ من هنا أوّلاً ولا يُعدَّل بعدها أبداً. curated
--  و contract_v1 مشتقّان بالكامل ويمكن هدمُهما وإعادةُ بنائهما؛ هذا لا.
-- ═══════════════════════════════════════════════════════════════════════

\set ON_ERROR_STOP on
SET search_path = raw, public;

-- ── ١-١ دفعات الاستقبال ───────────────────────────────────────────────
--  صفٌّ لكل عملية شحن. يُكتب أوّلاً فيصير للأحداث أبٌ معروف، ويُستكمل
--  عدده بعد الإدراج الفعلي (لا بعدد ما نوى المُرسِل إرساله — الفرق بينهما
--  هو بالضبط ما يكشف الشحنةَ المبتورة).
CREATE TABLE IF NOT EXISTS raw.ingest_batches (
  batch_id        bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  opened_at       timestamptz NOT NULL DEFAULT now(),
  closed_at       timestamptz NULL,
  source_system   text        NOT NULL DEFAULT 'kafaat-prod',
  source_release  text        NULL,
  declared_count  integer     NOT NULL DEFAULT 0,
  event_count     integer     NOT NULL DEFAULT 0,
  projected_rows  integer     NOT NULL DEFAULT 0,
  first_emitter_seq bigint    NULL,
  last_emitter_seq  bigint    NULL,
  note            text        NULL
);
COMMENT ON TABLE raw.ingest_batches IS 'دفعةٌ لكل شحنة. event_count يُصحَّح بعد الإدراج ليطابق الواقع لا النيّة.';

-- ── ١-٢ سجلّ الأحداث ──────────────────────────────────────────────────
--  مُجزّأ شهرياً بـ occurred_at.
--
--  المفتاح (event_uuid, occurred_at) لا event_uuid وحده: في الجدول المُجزّأ
--  يجب أن يتضمّن كلُّ قيدٍ فريد عمودَ التجزئة. وعليه فـ occurred_at يُسكّ
--  مرّةً واحدة في صندوق الصادر على الخادم الأساسي ولا يُعاد ختمُه عند إعادة
--  المحاولة — وإلا لَما امتصّ ON CONFLICT التكرار وتضاعفت الأحداث بصمت.
--
--  و event_uuid اشتقاقيّ (UUIDv5 على معرّف التقرير والانتقال) لا عشوائيّ،
--  فإعادةُ تشغيل التعبئة التاريخية بعد فشلٍ جزئيّ لا تُنتج صفوفاً جديدة.
CREATE TABLE IF NOT EXISTS raw.report_events (
  lake_seq          bigint      GENERATED ALWAYS AS IDENTITY,
  event_uuid        uuid        NOT NULL,
  occurred_at       timestamptz NOT NULL,
  landed_at         timestamptz NOT NULL DEFAULT now(),
  batch_id          bigint      NOT NULL,
  source_system     text        NOT NULL DEFAULT 'kafaat-prod',
  source_release    text        NULL,
  emitter_seq       bigint      NOT NULL,
  contract_version  text        NOT NULL,
  event_type        text        NOT NULL,
  subject_type      text        NOT NULL,

  source_report_id      bigint  NULL,
  source_assessment_id  bigint  NULL,

  -- المعرّف البديل: HMAC على معرّف المشارك بفلفلٍ لا يغادر خادم التطبيق.
  -- لا يُكتب هنا معرّف المشارك الأصلي ولا رقم الهوية ولا تجزئتُه.
  person_ref        char(64)    NULL,

  -- رمز المشارك: مُعطّل افتراضياً بقرار المالك (مجهوليّة كاملة). العمود
  -- موجودٌ ليُملأ لاحقاً دون هجرة، لا لأنه يُملأ اليوم.
  participant_code  varchar(20) NULL,

  sector_id         integer     NULL,
  classification    text        NOT NULL DEFAULT 'normal',

  -- الحدث الذي تعذّر بناءُ حمولته كاملةً: يُحفظ هيكلاً ولا يُسقَط.
  -- الإسقاط يتخطّاه بدل أن يفشل عليه.
  degraded          boolean     NOT NULL DEFAULT false,

  payload           jsonb       NOT NULL,
  payload_sha256    char(64)    NOT NULL,
  payload_bytes     integer     NOT NULL,

  CONSTRAINT report_events_pk PRIMARY KEY (event_uuid, occurred_at),

  -- شريطُ تعثّرٍ لا زينة: التصنيف يحكم كلَّ مسار قراءةٍ في المنصّة
  -- (Controller.php:19-37 — إنكار وجود الصفّ، 404 لا 403). إن أفلت صفٌّ
  -- مُصنَّف من المُصدِّر يوماً، يجب أن يُرفَض هنا لا أن يهبط بهدوء.
  -- الشاحن يعزل الصفَّ الرافض في الحجر الصحّي بعد N محاولات، فلا تتعطّل
  -- التغذيةُ كلُّها بسبب صفٍّ واحد.
  CONSTRAINT report_events_normal_only CHECK (classification = 'normal'),

  CONSTRAINT report_events_type_known CHECK (event_type IN (
      'report.created', 'report.updated', 'report.stage_approved', 'report.approved',
      'report.returned', 'report.cancelled', 'report.resubmitted', 'report.exec_summary_saved',
      'report.backfilled', 'report.vanished_upstream', 'report.erased',
      'daily.snapshot', 'analytics.snapshot'))
) PARTITION BY RANGE (occurred_at);

COMMENT ON TABLE  raw.report_events IS 'سجلّ الأحداث — يُكتب ولا يُعدَّل. مصدر الحقيقة الوحيد للبحيرة.';
COMMENT ON COLUMN raw.report_events.person_ref IS 'HMAC-SHA256(candidate_id) بفلفل التطبيق. لا يُعكس، ولا يُطابَق خارج البحيرة.';
COMMENT ON COLUMN raw.report_events.degraded  IS 'تعذّر بناء الحمولة كاملةً؛ الهيكل محفوظ والإسقاط يتخطّاه.';

CREATE INDEX IF NOT EXISTS report_events_seq_idx     ON raw.report_events (lake_seq);
CREATE INDEX IF NOT EXISTS report_events_batch_idx   ON raw.report_events (batch_id);
CREATE INDEX IF NOT EXISTS report_events_report_idx  ON raw.report_events (source_report_id, occurred_at);
CREATE INDEX IF NOT EXISTS report_events_subject_idx ON raw.report_events (source_assessment_id, occurred_at);
CREATE INDEX IF NOT EXISTS report_events_type_idx    ON raw.report_events (event_type, occurred_at);
CREATE INDEX IF NOT EXISTS report_events_person_idx  ON raw.report_events (person_ref, occurred_at);
CREATE INDEX IF NOT EXISTS report_events_gin         ON raw.report_events USING gin (payload jsonb_path_ops);

-- قسم الاستقبال البعيد: يمنع فشلَ الإدراج على حدثٍ خارج النطاق المُهيّأ.
-- ليس مكاناً دائماً — تُنبّه المراقبةُ على أيّ صفٍّ يستقرّ فيه (§٦-٦).
CREATE TABLE IF NOT EXISTS raw.report_events_default PARTITION OF raw.report_events DEFAULT;

-- ── ١-٣ المستندات — مُعنوَنة بالمحتوى ─────────────────────────────────
--  النسخة المتطابقة تُخزَّن مرّةً واحدة. مُعطّلة افتراضياً بقرار المالك
--  (تحمل النصّ الحرّ كاملاً)؛ الجداول تُنشأ الآن ليُفعَّل الالتقاط لاحقاً
--  دون هجرةٍ على قاعدةٍ يقرؤها مستهلكٌ حيّ.
CREATE TABLE IF NOT EXISTS raw.documents (
  sha256        char(64)    PRIMARY KEY,
  media_type    text        NOT NULL,
  byte_length   integer     NOT NULL,
  body          bytea       NOT NULL,          -- gzip
  encoding      text        NOT NULL DEFAULT 'gzip',
  first_seen_at timestamptz NOT NULL DEFAULT now(),
  -- المحو النظاميّ يُفرّغ الجسد ويُبقي البصمة: الحذف يكسر المرجعيّة،
  -- وإبقاء البصمة يُثبت ماذا أُتلف ومتى (PDPL: إثبات التنفيذ).
  erased_at     timestamptz NULL
);
COMMENT ON COLUMN raw.documents.erased_at IS 'مُحي نظامياً: body مُفرَّغ والبصمة باقيةٌ دليلاً على ما أُتلف.';

CREATE TABLE IF NOT EXISTS raw.document_refs (
  sha256      char(64)    NOT NULL REFERENCES raw.documents(sha256) ON DELETE RESTRICT,
  kind        text        NOT NULL,             -- report_document | report_brief
  event_uuid  uuid        NOT NULL,
  occurred_at timestamptz NOT NULL,
  PRIMARY KEY (sha256, kind, event_uuid)
);

-- ── ١-٤ حصانة raw ─────────────────────────────────────────────────────
--  امتيازٌ أوّلاً (lake_writer لا يملك UPDATE/DELETE أصلاً)، ثم زنادٌ
--  دفاعاً في العمق.
--
--  الزناد على مستوى الصفّ لا الجملة، ومُعرَّفٌ على الأب المُجزَّأ: منذ
--  PostgreSQL 13 تُستنسَخ زنادُ الصفّ إلى كل قسمٍ قائم وإلى كل قسمٍ
--  يُنشأ لاحقاً — فيغطّي الحذفَ الموجَّه إلى القسم مباشرةً، وهو ما لا
--  تفعله زنادُ الجملة. (يُتحقَّق منه في 08_verify.sql).
--  ثغرةٌ واحدة مقصودة: المحو النظاميّ. حقُّ المحو تحت PDPL يعلو على
--  حصانة السجلّ، وبغير هذا الباب يصير المحو مستحيلاً تقنياً — وهو أسوأ
--  من كونه مسموحاً ومُراقَباً. الباب لا يُفتح إلا من داخل
--  lake.apply_erasure()، ولا ينفع من يملك الامتياز أصلاً (lake_writer
--  ليس لديه UPDATE ولا DELETE على raw أساساً)، وكلُّ فتحةٍ تُسجَّل في
--  meta.erasure_log.
CREATE OR REPLACE FUNCTION raw.deny_mutation() RETURNS trigger
LANGUAGE plpgsql AS $fn$
BEGIN
  IF coalesce(current_setting('lake.erasure', true), 'off') = 'on' THEN
    RETURN CASE TG_OP WHEN 'DELETE' THEN OLD ELSE NEW END;
  END IF;

  RAISE EXCEPTION 'raw هي منطقة هبوطٍ غير قابلة للتعديل — % مرفوض على %',
        TG_OP, TG_TABLE_NAME
        USING ERRCODE = 'insufficient_privilege';
END $fn$;

DROP TRIGGER IF EXISTS report_events_immutable ON raw.report_events;
CREATE TRIGGER report_events_immutable
  BEFORE UPDATE OR DELETE ON raw.report_events
  FOR EACH ROW EXECUTE FUNCTION raw.deny_mutation();

DROP TRIGGER IF EXISTS document_refs_immutable ON raw.document_refs;
CREATE TRIGGER document_refs_immutable
  BEFORE UPDATE OR DELETE ON raw.document_refs
  FOR EACH ROW EXECUTE FUNCTION raw.deny_mutation();

-- ── ١-٥ امتيازات lake_writer على raw ──────────────────────────────────
--  إدراجٌ فقط. لا تحديث، لا حذف، لا اقتطاع.
GRANT INSERT                 ON raw.report_events, raw.documents, raw.document_refs TO lake_writer;
GRANT INSERT, UPDATE, SELECT ON raw.ingest_batches TO lake_writer;
GRANT USAGE                  ON ALL SEQUENCES IN SCHEMA raw TO lake_writer;
-- القراءة لازمة لـ ON CONFLICT ولحساب ما هبط فعلاً بعد الإدراج.
GRANT SELECT                 ON raw.report_events, raw.documents, raw.document_refs TO lake_writer;

-- الأقسام تُنشأ لاحقاً: الامتياز الافتراضي يمنحها الحقوقَ نفسها تلقائياً.
ALTER DEFAULT PRIVILEGES FOR ROLE lake_owner IN SCHEMA raw GRANT INSERT, SELECT ON TABLES TO lake_writer;
