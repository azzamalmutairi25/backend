-- ═══════════════════════════════════════════════════════════════════════
--  ٠٥ — contract_v1: العقد المنشور
--
--  هذا هو كلُّ ما يراه أيُّ مستهلك. لا raw ولا curated يُمنحان لأحدٍ أبداً.
--  الفائدة ليست شكليّة: خطأٌ في النمذجة داخل curated يُصلَح بـ lake.replay()
--  دون أن يشعر به من يقرأ، ما دام العقد ثابتاً فوقه.
--
--  قاعدةُ التطوّر: لا يُكسر العقد أبداً. يُضاف عمودٌ في آخر العرض، أو
--  يُنشأ contract_v2 بجانب v1 ويعيشان معاً حتى ينتقل المستهلك.
--
--  الحقائق تحمل الرمز الخام دائماً (draft/approved/present…)؛ التسميةُ
--  العربية تُقرأ من dim_labels. هكذا لا تنكسر لوحةٌ حين تُصاغ التسمية.
-- ═══════════════════════════════════════════════════════════════════════

\set ON_ERROR_STOP on
SET search_path = contract_v1, curated, public;

-- ── ٥-٠ الصفّ المنشور: تعريفٌ واحد لا ستّة ────────────────────────────
--  «أيُّ لقطةٍ تُنشر لهذه الدورة؟» سؤالٌ له جوابٌ واحد، وكان مكتوباً
--  بصيغتين: بعضُ العروض يُزيل التكرار (لقطةُ الاعتماد وإلّا الحاليّة)
--  وبعضُها يقبل الاثنتين معاً (is_current OR is_approval_snapshot).
--  ما دامت اللقطةُ الواحدة حاليّةً ومعتمَدةً في آنٍ — وهي كذلك اليوم في
--  كل الصفوف — لا يظهر فرق. وأوّلُ تحريرٍ بعد الاعتماد يجعلهما لقطتين،
--  فتُرجع تلك العروضُ صفَّين لكل (دورة، ترتيب) بلا إنذار.
--  التعريف هنا واحدٌ، وكلُّ عرضٍ يبني عليه.
CREATE OR REPLACE VIEW contract_v1.published_snapshot AS
SELECT DISTINCT ON (s.source_assessment_id) s.*
FROM curated.report_snapshot s
WHERE s.is_current OR s.is_approval_snapshot
ORDER BY s.source_assessment_id,
         -- لقطةُ الاعتماد تسبق الحاليّة: المعتمَد يُنشر مُجمَّداً
         s.is_approval_snapshot DESC, s.occurred_at DESC;

-- ── ٥-١ التقارير ──────────────────────────────────────────────────────
--  الصفُّ المنشور لتقريرٍ معتمَد هو لقطةُ الاعتماد لا «الأحدث».
--  لو نشرنا الأحدث لَتغيّرت الأرقامُ المُجمَّدة تحت المستهلك كلّما حُرِّر
--  تقريرٌ أو أُعيد بعد اعتماده — أي لَنقض العقدُ التجميدَ الذي وُجد له.
CREATE OR REPLACE VIEW contract_v1.reports AS
SELECT
  s.source_assessment_id            AS assessment_ref,
  s.person_ref,
  s.participant_code,
  s.sector_id,
  s.rank_label,
  s.tier,
  s.gender,
  s.personnel_category,
  s.status,
  s.recommendation,
  s.behavioral_fit,
  s.technical_fit,
  s.overall_fit,
  s.return_count,
  s.approved_at,
  s.approved_at_inferred,
  s.occurred_at                     AS snapshot_at,
  s.event_type                      AS snapshot_reason,
  s.is_approval_snapshot,
  s.competency_dim_version,
  s.workflow_dim_version,
  s.settings_dim_version,
  s.lake_seq
FROM contract_v1.published_snapshot s;

COMMENT ON VIEW contract_v1.reports IS
  'صفٌّ واحد لكل دورة تقييم. المعتمَد يُنشر بلقطة اعتماده المُجمَّدة؛ وغيرُه بأحدث لقطة.';

-- الحالة الجارية كما هي الآن، لمن يريد المتابعة التشغيلية لا السجلّ المُجمَّد.
CREATE OR REPLACE VIEW contract_v1.reports_current AS
SELECT
  s.source_assessment_id AS assessment_ref, s.person_ref, s.participant_code,
  s.sector_id, s.rank_label, s.tier, s.gender, s.personnel_category,
  s.status, s.recommendation, s.behavioral_fit, s.technical_fit, s.overall_fit,
  s.return_count, s.approved_at, s.occurred_at AS snapshot_at, s.lake_seq
FROM curated.report_snapshot s
WHERE s.is_current;

-- ── ٥-٢ تفصيل الكفاءات ────────────────────────────────────────────────
CREATE OR REPLACE VIEW contract_v1.report_competencies AS
SELECT
  s.source_assessment_id AS assessment_ref,
  c.competency_id, c.name_ar, c.type, c.group_domain,
  c.weight, c.max_level, c.avg_score, c.pct, c.target_level, c.gap, c.met, c.ord,
  s.is_approval_snapshot, s.lake_seq
FROM curated.report_competency c
JOIN contract_v1.published_snapshot s ON s.snapshot_id = c.snapshot_id;

-- ── ٥-٣ القياس والجدولة والحضور ───────────────────────────────────────
CREATE OR REPLACE VIEW contract_v1.report_measurements AS
SELECT s.source_assessment_id AS assessment_ref,
       m.tool_code, m.scale_code, m.score, m.band, m.ord, s.lake_seq
FROM curated.report_measurement m
JOIN contract_v1.published_snapshot s ON s.snapshot_id = m.snapshot_id;

CREATE OR REPLACE VIEW contract_v1.report_activities AS
SELECT s.source_assessment_id AS assessment_ref,
       s.sector_id, s.tier,
       a.activity_code, a.scheduled_date, a.session_slot,
       a.attendance_code, a.evaluation_status, a.ord, s.lake_seq
FROM curated.report_activity a
JOIN contract_v1.published_snapshot s ON s.snapshot_id = a.snapshot_id;

COMMENT ON VIEW contract_v1.report_activities IS
  'الجدولة والحضور بحبيبة الدورة: نشاطٌ لكل صفّ، برمزٍ خام لا بتسمية.';

-- ── ٥-٤ السرد — خلف دورٍ منفصل ────────────────────────────────────────
--  النصّ الحرّ للتقرير أعلى مخاطر إعادة التعرّف رغم التنقية: CvGuard
--  نفسه يُقرّ بأن شبهَ المُعرِّف ينجو. لذلك لا يُمنح لقارئ الأرقام.
--  مُعطَّل الالتقاط أصلاً في الإصدار الأول (LAKE_PUBLISH_NARRATIVE=false)،
--  والعرض قائمٌ ليعمل يوم يُقرَّر النشر دون هجرةٍ على قاعدةٍ حيّة.
CREATE OR REPLACE VIEW contract_v1.report_narratives AS
SELECT
  s.source_assessment_id AS assessment_ref,
  s.payload->'narrative'->>'overview'          AS overview_text,
  s.payload->'narrative'->>'executive_summary' AS executive_summary,
  s.payload->'narrative'->'strengths'          AS strengths,
  s.payload->'narrative'->'development_areas'  AS development_areas,
  s.lake_seq
FROM contract_v1.published_snapshot s
WHERE s.payload ? 'narrative';

CREATE OR REPLACE VIEW contract_v1.report_development_plan AS
SELECT s.source_assessment_id AS assessment_ref,
       d.area, d.action, d.priority, d.ord, s.lake_seq
FROM curated.report_development_item d
JOIN contract_v1.published_snapshot s ON s.snapshot_id = d.snapshot_id;

-- ── ٥-٥ التقرير اليومي ────────────────────────────────────────────────
--  أرقامٌ فقط. أسبابُ الغياب لا تصل البحيرة أصلاً (قد تكون طبّية)،
--  فتُسقَط عند التوليد لا عند العرض.
CREATE OR REPLACE VIEW contract_v1.daily_reports AS
SELECT report_date, captured_at,
       sessions_count, present_count, excused_count, absent_count,
       reports_created, reports_approved
FROM curated.daily_snapshot;

-- ── ٥-٦ لقطات التحليلات التنفيذية ─────────────────────────────────────
CREATE OR REPLACE VIEW contract_v1.analytics_snapshots AS
SELECT snapshot_date, kind, captured_at, payload
FROM curated.analytics_snapshot;

COMMENT ON VIEW contract_v1.analytics_snapshots IS
  'مخرجات /api/analytics/* المؤرَّخة. المنصّة تحسبها حيّةً ولا تخزّنها، فبدون هذه اللقطات تصير لوحاتُ الماضي غير قابلةٍ لإعادة البناء.';

-- ── ٥-٧ الأبعاد ───────────────────────────────────────────────────────
CREATE OR REPLACE VIEW contract_v1.dim_sectors     AS SELECT sector_id, name_ar, code FROM curated.dim_sector;
CREATE OR REPLACE VIEW contract_v1.dim_ranks       AS SELECT rank_id, name_ar, category, sort_order FROM curated.dim_rank;
CREATE OR REPLACE VIEW contract_v1.dim_competencies AS SELECT competency_id, name_ar, type, group_domain, weight, max_level FROM curated.dim_competency;
CREATE OR REPLACE VIEW contract_v1.dim_workflow_stages AS SELECT status_key, position, label_ar, role_code, is_active FROM curated.dim_workflow_stage;
CREATE OR REPLACE VIEW contract_v1.dim_labels      AS SELECT domain, code, label_ar, sort_order FROM curated.dim_label;

-- ── ٥-٨ التجميعات ─────────────────────────────────────────────────────
--  حدُّ الإخفاء يُعلَّم ولا يُحذف. حذفُ الخليّة الصغيرة كان يُفرغ اللوحة
--  الأولى للمستهلك — ١٩ قطاعاً على بضع مئات تقريرٍ سنوياً يعني أن معظم
--  الخلايا ١-٣ — فيقرأ الفراغَ عطلاً في التغذية. الإجمالي الوطني
--  (sector_id IS NULL) يبقى ظاهراً دائماً.
--  التوصية نصٌّ عربيٌّ حرّ في المنصّة لا رمزٌ مُعدَّد («مرشّح قوي — جاهز
--  للتكليف القيادي» …). أيُّ ترشيحٍ على رمزٍ مثل 'ready' كان لن يُطابق
--  صفّاً واحداً أبداً ويُخرج أصفاراً تبدو صحيحة. لذلك تُمرَّر كما هي،
--  ويُفصَل توزيعُها في عرضٍ مستقلّ.
CREATE OR REPLACE VIEW contract_v1.sector_readiness AS
WITH base AS (
  SELECT sector_id, tier, count(*) AS n,
         avg(overall_fit)    AS avg_fit,
         avg(behavioral_fit) AS avg_beh,
         avg(technical_fit)  AS avg_tech
  FROM contract_v1.reports
  WHERE status = 'approved'
  GROUP BY GROUPING SETS ((sector_id, tier), ())
)
SELECT
  sector_id, tier, n AS report_count,
  CASE WHEN sector_id IS NULL OR n >= lake.k_anonymity() THEN round(avg_fit,  2) END AS avg_overall_fit,
  CASE WHEN sector_id IS NULL OR n >= lake.k_anonymity() THEN round(avg_beh,  2) END AS avg_behavioral_fit,
  CASE WHEN sector_id IS NULL OR n >= lake.k_anonymity() THEN round(avg_tech, 2) END AS avg_technical_fit,
  (sector_id IS NOT NULL AND n < lake.k_anonymity()) AS suppressed
FROM base;

-- توزيع التوصيات بنصّها كما كتبته المنصّة.
CREATE OR REPLACE VIEW contract_v1.recommendation_mix AS
SELECT sector_id, tier, recommendation, count(*) AS report_count,
       (sector_id IS NOT NULL AND count(*) < lake.k_anonymity()) AS suppressed
FROM contract_v1.reports
WHERE status = 'approved' AND recommendation IS NOT NULL
GROUP BY GROUPING SETS ((sector_id, tier, recommendation), (recommendation));

CREATE OR REPLACE VIEW contract_v1.approval_trend AS
WITH base AS (
  SELECT date_trunc('month', approved_at)::date AS month, sector_id, count(*) AS n
  FROM contract_v1.reports
  WHERE status = 'approved' AND approved_at IS NOT NULL
  GROUP BY GROUPING SETS ((1, 2), (1))
)
SELECT month, sector_id, n AS approved_count,
       (sector_id IS NOT NULL AND n < lake.k_anonymity()) AS suppressed
FROM base;

COMMENT ON VIEW contract_v1.approval_trend IS
  'لحظةُ الاعتماد من حدث الاعتماد نفسه. تختلف عمداً عن /api/analytics/trends الذي يستنتج الشهر من updated_at فينجرف كلّما حُرِّر تقريرٌ بعد اعتماده — البحيرة أدقّ. راجع LAKE_CONTRACT_v1.md §الانحرافات المعروفة.';

CREATE OR REPLACE VIEW contract_v1.competency_heatmap AS
SELECT
  c.competency_id, c.name_ar, r.sector_id, r.tier,
  count(*) AS report_count,
  CASE WHEN count(*) >= lake.k_anonymity() THEN round(avg(c.pct), 2) END AS avg_pct,
  CASE WHEN count(*) >= lake.k_anonymity() THEN round(avg(c.gap), 3) END AS avg_gap,
  (count(*) < lake.k_anonymity()) AS suppressed
FROM contract_v1.report_competencies c
JOIN contract_v1.reports r ON r.assessment_ref = c.assessment_ref
WHERE r.status = 'approved'
GROUP BY c.competency_id, c.name_ar, r.sector_id, r.tier;

-- ── ٥-٩ وصفُ البحيرة لنفسها ───────────────────────────────────────────
--  المستهلك يسأل البحيرةَ عن حالها بدل أن يسأل مشغّلها.
CREATE OR REPLACE VIEW contract_v1.freshness AS
SELECT
  (SELECT max(occurred_at) FROM curated.report_snapshot)              AS last_report_event_at,
  (SELECT max(landed_at)   FROM raw.report_events)                    AS last_landed_at,
  (SELECT max(lake_seq)    FROM raw.report_events)                    AS max_lake_seq,
  (SELECT count(*)         FROM curated.report_snapshot WHERE is_current) AS reports_tracked,
  (SELECT max(report_date) FROM curated.daily_snapshot)               AS last_daily_snapshot,
  (SELECT max(snapshot_date) FROM curated.analytics_snapshot)         AS last_analytics_snapshot,
  (SELECT count(*)         FROM raw.report_events_default)            AS rows_in_default_partition,
  (SELECT version FROM meta.schema_version WHERE component = 'contract_v1') AS contract_version,
  now() AS observed_at,
  -- يُلحَق في الآخر لا يُدسّ في الوسط: CREATE OR REPLACE VIEW يرفض إعادةَ
  -- تسمية عمودٍ قائم، وهو ما يفرض على العقد قاعدتَه المُعلَنة — يُضاف
  -- العمود في الذيل أو يُنشأ contract_v2. القيدُ هنا في صالحنا.
  lake.k_anonymity() AS k_anonymity_threshold;

COMMENT ON VIEW contract_v1.freshness IS
  'حالةُ التغذية. rows_in_default_partition يجب أن يبقى صفراً — أيّ صفٍّ فيه يعني حدثاً خارج النطاق المُهيّأ.';

CREATE OR REPLACE VIEW contract_v1.contract_manifest AS
SELECT * FROM (VALUES
  ('reports',              'صفٌّ لكل دورة تقييم؛ المعتمَد بلقطة اعتماده المُجمَّدة', 'lake_reader'),
  ('reports_current',      'الحالة الجارية لكل دورة',                              'lake_reader'),
  ('report_competencies',  'تفصيل الكفاءات المُجمَّد',                              'lake_reader'),
  ('report_measurements',  'نتائج أدوات القياس',                                   'lake_reader'),
  ('report_activities',    'الجدولة والحضور بحبيبة الدورة',                        'lake_reader'),
  ('daily_reports',        'أرقام التقرير اليومي المؤرَّخة',                        'lake_reader'),
  ('analytics_snapshots',  'لقطات التحليلات التنفيذية',                            'lake_reader'),
  ('sector_readiness',     'الجاهزية بالقطاع والشريحة',                            'lake_reader'),
  ('recommendation_mix',   'توزيع التوصيات بنصّها',                                'lake_reader'),
  ('approval_trend',       'اتّجاه الاعتماد شهرياً',                                'lake_reader'),
  ('competency_heatmap',   'خريطة الكفاءات الحرارية',                              'lake_reader'),
  ('dim_sectors',          'بُعد القطاعات',                                        'lake_reader'),
  ('dim_ranks',            'بُعد الرتب',                                           'lake_reader'),
  ('dim_competencies',     'بُعد الكفاءات',                                        'lake_reader'),
  ('dim_workflow_stages',  'بُعد مراحل الاعتماد',                                  'lake_reader'),
  ('dim_labels',           'تسميات الرموز بالعربية',                               'lake_reader'),
  ('freshness',            'حالة التغذية',                                         'lake_reader'),
  ('contract_manifest',    'بيان العقد — هذا الجدول نفسه',                         'lake_reader'),
  ('events_stream',        'تدفّق الأحداث للترقيم بالمفتاح',                        'lake_reader'),
  ('published_snapshot',   'قاعدة اختيار الصفّ المنشور (داخليّ للعقد)',              'lake_reader'),
  ('report_narratives',    'نصّ التقرير الحرّ',                          'lake_narrative_reader'),
  ('report_development_plan','بنود خطة التطوير',                        'lake_narrative_reader')
) AS t(view_name, description_ar, granted_to);

-- ── ٥-١٠ تدفّق الأحداث — ترقيمٌ بالمفتاح لا بالإزاحة ──────────────────
--  المستهلك التالي يسحب ما بعد آخر lake_seq رآه. الإزاحة (OFFSET) تكذب
--  حين يهبط صفٌّ جديدٌ أثناء الصفحات؛ المفتاح المتصاعد لا يكذب.
CREATE OR REPLACE VIEW contract_v1.events_stream AS
SELECT lake_seq, occurred_at, landed_at, event_type,
       source_assessment_id AS assessment_ref, person_ref, sector_id, contract_version
FROM raw.report_events
WHERE NOT degraded;

COMMENT ON VIEW contract_v1.events_stream IS
  'للتغذية إلى خادمٍ تالٍ: WHERE lake_seq > :last ORDER BY lake_seq LIMIT :n';
