-- ═══════════════════════════════════════════════════════════════════════
--  ٠٢ — curated: الإسقاط المشتقّ
--
--  كل صفٍّ هنا مُشتقٌّ من raw ولا شيء غيره. يجوز هدمُ المخطّط كاملاً
--  وإعادةُ بنائه بـ lake.replay() دون فقد حقيقةٍ واحدة — وهذا بالضبط ما
--  يجعل الخطأ في النمذجة رخيصاً أمام مستهلكٍ لم يُبنَ بعد.
--
--  قاعدة NOT NULL هنا مقيَّدة عمداً: الحدث المبتور (degraded) يجب أن
--  يُتخطّى لا أن يُسقِط الدفعة. عمودٌ إلزاميٌّ في غير محلّه يُحوّل صفّاً
--  واحداً تالفاً إلى تغذيةٍ متوقّفة إلى الأبد.
-- ═══════════════════════════════════════════════════════════════════════

\set ON_ERROR_STOP on
SET search_path = curated, public;

-- ── ٢-١ الأبعاد ───────────────────────────────────────────────────────
--  تُحدَّث بالإحلال (upsert) من كل حدث: البُعد يتغيّر ببطء والحدث يحمل
--  صورته وقت وقوعه.

CREATE TABLE IF NOT EXISTS curated.dim_sector (
  sector_id    integer PRIMARY KEY,
  name_ar      text    NOT NULL,
  code         text    NULL,
  updated_at   timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS curated.dim_rank (
  rank_id      integer PRIMARY KEY,
  name_ar      text    NOT NULL,
  category     text    NULL,          -- military | civilian
  sort_order   integer NULL,
  updated_at   timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS curated.dim_competency (
  competency_id integer PRIMARY KEY,
  name_ar       text    NOT NULL,
  type          text    NULL,
  group_domain  text    NULL,
  weight        numeric(6,3) NULL,
  max_level     integer NULL,
  updated_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS curated.dim_workflow_stage (
  status_key   text    PRIMARY KEY,
  position     integer NULL,
  label_ar     text    NULL,
  role_code    text    NULL,
  is_active    boolean NULL,
  updated_at   timestamptz NOT NULL DEFAULT now()
);

-- ترجمةُ الرموز إلى عربية. الحقائق تحمل الرمز الخام دائماً؛ التسميةُ
-- تُقرأ من هنا. هكذا لا يُكسر المستهلك حين تتغيّر صياغةُ التسمية.
CREATE TABLE IF NOT EXISTS curated.dim_label (
  domain     text NOT NULL,           -- report_status | recommendation | activity | attendance | tier | …
  code       text NOT NULL,
  label_ar   text NOT NULL,
  sort_order integer NULL,
  PRIMARY KEY (domain, code)
);

-- ── ٢-٢ لقطة التقرير — SCD-2 متسامحة ──────────────────────────────────
CREATE TABLE IF NOT EXISTS curated.report_snapshot (
  snapshot_id           bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  event_uuid            uuid        NOT NULL UNIQUE,
  lake_seq              bigint      NULL,
  occurred_at           timestamptz NOT NULL,
  event_type            text        NOT NULL,

  source_report_id      bigint      NULL,
  source_assessment_id  bigint      NOT NULL,

  -- كلها قابلةٌ للعدم عمداً (راجع رأس الملف): الحدث الهيكليّ لا يحملها.
  person_ref            char(64)    NULL,
  participant_code      varchar(20) NULL,
  sector_id             integer     NULL,
  -- candidates.rank_label نصٌّ حرّ لا مفتاحٌ أجنبيّ — يُحمل كما هو،
  -- و dim_rank يبقى فهرساً للرتب المُعرَّفة لا قيداً على هذا العمود.
  rank_label            text        NULL,
  tier                  varchar(10) NULL,     -- upper | middle
  gender                varchar(10) NULL,
  personnel_category    varchar(20) NULL,

  status                text        NOT NULL,
  recommendation        text        NULL,
  behavioral_fit        numeric(5,2) NULL,
  technical_fit         numeric(5,2) NULL,
  overall_fit           numeric(5,2) NULL,
  return_count          integer     NULL,

  -- لحظةُ الاعتماد مُشتقّة من الحدث لا من updated_at.
  -- المنصّة تستنتج الشهر من updated_at (AnalyticsController.php:192) فتنجرف
  -- كلّما حُرِّر تقريرٌ بعد اعتماده؛ البحيرة لا تنجرف. الفرق موثّقٌ في العقد.
  approved_at           timestamptz NULL,
  -- التعبئة التاريخية تستنتج اللحظة من updated_at؛ الحدث الحيّ يعرفها.
  -- الفرق يُعلَّم ولا يُخفى.
  approved_at_inferred  boolean     NULL,

  -- لقطةُ الاعتماد بالذات — لا «الأحدث». التجميد يعمل في raw، وكان
  -- المستهلك سيراه ينقض لو نشرنا is_current لتقريرٍ حُرِّر بعد اعتماده.
  is_approval_snapshot  boolean     NOT NULL DEFAULT false,

  is_current            boolean     NOT NULL DEFAULT true,
  valid_from            timestamptz NOT NULL,
  valid_to              timestamptz NULL,

  -- إصدارات الأبعاد وقت التجميد: بها وحدها يُفهم رقمٌ حُسب قبل تعديل
  -- الكفاءات أو سلسلة الاعتماد أو إعدادات الشرائح.
  competency_dim_version text NULL,
  workflow_dim_version   text NULL,
  settings_dim_version   text NULL,

  payload               jsonb       NOT NULL
);

CREATE INDEX IF NOT EXISTS snap_assessment_idx ON curated.report_snapshot (source_assessment_id, occurred_at DESC);
CREATE INDEX IF NOT EXISTS snap_person_idx     ON curated.report_snapshot (person_ref);
CREATE INDEX IF NOT EXISTS snap_status_idx     ON curated.report_snapshot (status);
CREATE INDEX IF NOT EXISTS snap_sector_idx     ON curated.report_snapshot (sector_id, tier);
CREATE INDEX IF NOT EXISTS snap_approved_idx   ON curated.report_snapshot (approved_at) WHERE approved_at IS NOT NULL;

-- صفٌّ حاليٌّ واحدٌ لكل دورة تقييم. الإسقاط يُغلق السابق قبل أن يفتح
-- التالي — الترتيب المعكوس يُسقط الدفعة على ثاني حدثٍ لأول تقرير.
CREATE UNIQUE INDEX IF NOT EXISTS snap_current_uq
  ON curated.report_snapshot (source_assessment_id) WHERE is_current;

-- لقطةُ اعتمادٍ واحدةٌ لكل دورة.
CREATE UNIQUE INDEX IF NOT EXISTS snap_approval_uq
  ON curated.report_snapshot (source_assessment_id) WHERE is_approval_snapshot;

-- ── ٢-٣ تفصيل الكفاءات المُجمَّد ───────────────────────────────────────
--  يُجمَّد لأن weight / max_level / target_* قابلةٌ للتحرير من الشاشة،
--  فيُنتج التقريرُ نفسُه أرقاماً مختلفةً قبل التعديل وبعده.
CREATE TABLE IF NOT EXISTS curated.report_competency (
  snapshot_id   bigint  NOT NULL REFERENCES curated.report_snapshot(snapshot_id) ON DELETE CASCADE,
  competency_id integer NULL,
  name_ar       text    NULL,
  type          text    NULL,
  group_domain  text    NULL,
  weight        numeric(6,3) NULL,
  max_level     integer NULL,
  avg_score     numeric(6,3) NULL,
  pct           numeric(6,2) NULL,
  target_level  numeric(6,3) NULL,
  gap           numeric(6,3) NULL,
  met           boolean NULL,
  ord           integer NOT NULL,
  PRIMARY KEY (snapshot_id, ord)
);
CREATE INDEX IF NOT EXISTS rc_comp_idx ON curated.report_competency (competency_id);

-- ── ٢-٤ نتائج القياس ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS curated.report_measurement (
  snapshot_id bigint NOT NULL REFERENCES curated.report_snapshot(snapshot_id) ON DELETE CASCADE,
  tool_code   text   NULL,
  scale_code  text   NULL,
  score       numeric(8,3) NULL,
  band        text   NULL,
  ord         integer NOT NULL,
  PRIMARY KEY (snapshot_id, ord)
);

-- ── ٢-٥ بنود خطة التطوير ──────────────────────────────────────────────
--  النصّ (area/action) مُنقّى بـ CvGuard قبل الوصول، ويُنشر خلف دور
--  السرد وحده. العدد وحده متاحٌ للقارئ العدديّ.
CREATE TABLE IF NOT EXISTS curated.report_development_item (
  snapshot_id bigint NOT NULL REFERENCES curated.report_snapshot(snapshot_id) ON DELETE CASCADE,
  area        text   NULL,
  action      text   NULL,
  priority    text   NULL,
  ord         integer NOT NULL,
  PRIMARY KEY (snapshot_id, ord)
);

-- ── ٢-٦ نشاط الدورة: الجدولة والحضور بحبيبة التقرير ───────────────────
CREATE TABLE IF NOT EXISTS curated.report_activity (
  snapshot_id     bigint NOT NULL REFERENCES curated.report_snapshot(snapshot_id) ON DELETE CASCADE,
  activity_code   text   NULL,          -- interview | discussion | measurement | integration
  scheduled_date  date   NULL,
  session_slot    text   NULL,
  attendance_code text   NULL,          -- present | excused | absent
  evaluation_status text NULL,          -- draft | submitted | approved
  ord             integer NOT NULL,
  PRIMARY KEY (snapshot_id, ord)
);
CREATE INDEX IF NOT EXISTS ra_date_idx ON curated.report_activity (scheduled_date);

-- ── ٢-٧ لقطة التقرير اليومي ───────────────────────────────────────────
--  غير قابلةٍ للاستخراج بأثرٍ رجعيّ: DailyReportService::gather يختار
--  بـ created_at OR updated_at = التاريخ، فإعادةُ تشغيله لتاريخٍ مضى
--  تُعطي جواباً مختلفاً عمّا أعطى يومَها. تُلتقط وقت التشغيل أو تُفقد.
CREATE TABLE IF NOT EXISTS curated.daily_snapshot (
  report_date   date        PRIMARY KEY,
  event_uuid    uuid        NOT NULL UNIQUE,
  captured_at   timestamptz NOT NULL,
  sessions_count      integer NULL,
  present_count       integer NULL,
  excused_count       integer NULL,
  absent_count        integer NULL,
  reports_created     integer NULL,
  reports_approved    integer NULL,
  payload       jsonb       NOT NULL
);

-- ── ٢-٨ لقطة التحليلات التنفيذية ──────────────────────────────────────
--  كل مخرجات /api/analytics/* و /api/dashboard/overview تُحسب حيّةً ولا
--  تُخزَّن. بدون لقطةٍ مؤرَّخة تصير لوحاتُ الماضي غير قابلةٍ لإعادة البناء،
--  لأنها تعتمد على أعمدةِ حالةٍ راهنةٍ متغيّرة.
CREATE TABLE IF NOT EXISTS curated.analytics_snapshot (
  snapshot_date date        NOT NULL,
  kind          text        NOT NULL,   -- executive | executive_overview | dashboard | reports
  event_uuid    uuid        NOT NULL UNIQUE,
  captured_at   timestamptz NOT NULL,
  payload       jsonb       NOT NULL,
  PRIMARY KEY (snapshot_date, kind)
);

-- ── ٢-٩ لا امتيازات هنا ───────────────────────────────────────────────
--  curated لا يُمنح لأحد — لا للقارئ ولا للكاتب. العقد وحده هو الواجهة.
--  (lake_writer يطالع تعدادَه عبر contract_v1 لا عبر هذه الجداول.)
