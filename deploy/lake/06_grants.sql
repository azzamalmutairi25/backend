-- ═══════════════════════════════════════════════════════════════════════
--  ٠٦ — الامتيازات
--
--  مبدأٌ واحد: المنحُ صريحٌ دائماً، والمنعُ هو الافتراض.
--
--  الفخّ الذي يُتجنَّب هنا: ALTER DEFAULT PRIVILEGES … GRANT … TO lake_reader
--  كان سيمنح تلقائياً كلَّ عرضٍ يُضاف مستقبلاً إلى contract_v1 — بما فيها
--  عرضٌ سرديّ يُضاف يوماً — فيَنقض الفصلَ الذي بُني له دورٌ منفصل.
--  المنح الافتراضي هنا لقارئ السرد وحده، وقارئ الأرقام يُمنح عرضاً عرضاً.
-- ═══════════════════════════════════════════════════════════════════════

\set ON_ERROR_STOP on

-- ── ٦-١ قارئ الأرقام ──────────────────────────────────────────────────
GRANT USAGE ON SCHEMA contract_v1 TO lake_reader;

GRANT SELECT ON
  contract_v1.reports,
  contract_v1.reports_current,
  contract_v1.report_competencies,
  contract_v1.report_measurements,
  contract_v1.report_activities,
  contract_v1.daily_reports,
  contract_v1.analytics_snapshots,
  contract_v1.sector_readiness,
  contract_v1.recommendation_mix,
  contract_v1.approval_trend,
  contract_v1.competency_heatmap,
  contract_v1.dim_sectors,
  contract_v1.dim_ranks,
  contract_v1.dim_competencies,
  contract_v1.dim_workflow_stages,
  contract_v1.dim_labels,
  contract_v1.freshness,
  contract_v1.contract_manifest,
  contract_v1.events_stream
TO lake_reader;

-- ── ٦-٢ قارئ السرد ────────────────────────────────────────────────────
--  يرث كلَّ ما سبق، ويضيف النصّ الحرّ. يُمنح بمراجعةٍ مكتوبة لا بالتبعيّة.
--  عضويّة lake_narrative_reader في lake_reader تُمنح في 00_bootstrap
--  بصلاحية postgres: منحُ عضويّةِ دورٍ يحتاج إدارتَه، و lake_owner لا يُديره.
GRANT USAGE ON SCHEMA contract_v1 TO lake_narrative_reader;
GRANT SELECT ON
  contract_v1.report_narratives,
  contract_v1.report_development_plan
TO lake_narrative_reader;

-- العروض المستقبلية تُمنح تلقائياً لقارئ السرد وحده — والقارئ العدديّ
-- يُمنح صراحةً في الهجرة التي تُنشئ العرض. الصمتُ هنا في صالح الحجب.
ALTER DEFAULT PRIVILEGES FOR ROLE lake_owner IN SCHEMA contract_v1
  GRANT SELECT ON TABLES TO lake_narrative_reader;

-- ── ٦-٣ الكاتب ────────────────────────────────────────────────────────
--  يقرأ تعدادَه عبر العقد لا عبر curated: أمرُ المطابقة يحتاج أن يعرف
--  ماذا وصل، ولا يحتاج أن يرى الإسقاط الداخلي.
GRANT SELECT ON contract_v1.reports, contract_v1.freshness TO lake_writer;

-- ── ٦-٤ ما لا يُمنح، ولمن ─────────────────────────────────────────────
--   curated  — لا يُمنح لأحد. الواجهة هي العقد وحده.
--   raw      — للكاتب إدراجاً وقراءةً فقط؛ لا تعديل ولا حذف (زنادٌ وامتياز).
--   lake.*   — EXECUTE ممنوعٌ عن العموم في 04؛ الكاتب يملك الإسقاط
--              والأقسام فقط، ولا يملك replay ولا apply_erasure.
--
--   ملاحظة على default_transaction_read_only: لا يُعتمد عليه ضابطاً —
--   يستطيع العميل إلغاءه بأمرٍ واحد. القراءةُ فقط مضمونةٌ بالامتيازات
--   أعلاه لا بإعدادِ جلسة.
-- ───────────────────────────────────────────────────────────────────────

-- ── ٦-٥ تسميات الرموز ─────────────────────────────────────────────────
INSERT INTO curated.dim_label (domain, code, label_ar, sort_order) VALUES
  ('report_status','draft','مسودّة',1),
  ('report_status','pending_evaluator','بانتظار المقيّم',2),
  ('report_status','pending_manager','بانتظار مدير التقييم',3),
  ('report_status','pending_dev_approval','بانتظار مدير التطوير',4),
  ('report_status','pending_center','بانتظار مدير المركز',5),
  ('report_status','approved','معتمَد',6),
  ('report_status','returned','مُعاد',7),
  ('report_status','cancelled','ملغى',8),
  ('activity','interview','المقابلة',1),
  ('activity','discussion','حلقة النقاش',2),
  ('activity','measurement','القياس',3),
  ('activity','integration','التكامل',4),
  ('attendance','present','حاضر',1),
  ('attendance','pending','لم يُسجَّل بعد',2),
  ('attendance','absent_excused','غائب بعذر',3),
  ('attendance','absent_unexcused','غائب بدون عذر',4),
  ('tier','upper','القيادات العليا',1),
  ('tier','middle','القيادات الوسطى',2),
  ('personnel_category','military','عسكري',1),
  ('personnel_category','civilian','مدني',2),
  ('gender','male','ذكر',1),
  ('gender','female','أنثى',2)
ON CONFLICT (domain, code) DO UPDATE SET label_ar = EXCLUDED.label_ar, sort_order = EXCLUDED.sort_order;
