-- ═══════════════════════════════════════════════════════════════════════
--  ٠٤ — lake: الدوالّ
--
--  الأقسام، الإسقاط، إعادة البناء، المحو.
--  كلُّ دالّةٍ هنا SECURITY DEFINER بـ search_path مثبَّت: بدونه يستطيع
--  المستدعي أن يزرع مخطّطاً في مساره فتُنفَّذ دالّتُه بامتياز المالك.
-- ═══════════════════════════════════════════════════════════════════════

\set ON_ERROR_STOP on
SET search_path = lake, public;

-- ═══ ٤-١ الأقسام ══════════════════════════════════════════════════════
--  SECURITY DEFINER ضرورةٌ لا تحسين: الدالّة تُنفّذ
--  CREATE TABLE … PARTITION OF raw.report_events، وهذا يحتاج CREATE على
--  المخطّط وملكيّةَ الأب — و lake_writer لا يملك أيّاً منهما. بدونها
--  تنجح كلُّ الاختبارات يومَ الإطلاق ثم تتوقّف التغذيةُ عند أوّل دورانِ شهر.
CREATE OR REPLACE FUNCTION lake.ensure_partitions_from(p_start date, p_months_ahead integer DEFAULT 15)
RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = raw, pg_catalog, pg_temp
AS $fn$
DECLARE
  m_start date := date_trunc('month', p_start)::date;
  m_end   date := (date_trunc('month', now()) + make_interval(months => p_months_ahead))::date;
  cur     date := m_start;
  made    integer := 0;
  pname   text;
BEGIN
  WHILE cur <= m_end LOOP
    pname := format('report_events_%s', to_char(cur, 'YYYY_MM'));
    IF NOT EXISTS (
      SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
      WHERE n.nspname = 'raw' AND c.relname = pname
    ) THEN
      EXECUTE format(
        'CREATE TABLE raw.%I PARTITION OF raw.report_events FOR VALUES FROM (%L) TO (%L)',
        pname, cur, (cur + interval '1 month')::date);
      made := made + 1;
    END IF;
    cur := (cur + interval '1 month')::date;
  END LOOP;
  RETURN made;
END $fn$;

COMMENT ON FUNCTION lake.ensure_partitions_from(date, integer) IS
  'يُهيّئ أقسام raw.report_events من تاريخٍ محدَّد. تستدعيه التعبئةُ التاريخية بأقدم تاريخٍ لديها.';

--  التشغيل اليومي: ثلاثةُ أشهر للخلف تحسّباً لحدثٍ متأخّر، والمدى المُعلَن للأمام.
CREATE OR REPLACE FUNCTION lake.ensure_partitions(p_months_ahead integer DEFAULT 15)
RETURNS integer
LANGUAGE sql SECURITY DEFINER SET search_path = raw, pg_catalog, pg_temp
AS $fn$
  SELECT lake.ensure_partitions_from((date_trunc('month', now()) - interval '3 months')::date, p_months_ahead);
$fn$;

-- ═══ ٤-١-ب حدّ الإخفاء ═══════════════════════════════════════════════
CREATE OR REPLACE FUNCTION lake.k_anonymity() RETURNS integer
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = meta, pg_catalog, pg_temp
AS $fn$
  SELECT coalesce((SELECT value::int FROM meta.lake_settings WHERE key = 'k_anonymity'), 5);
$fn$;

-- ═══ ٤-٢ الإسقاط ══════════════════════════════════════════════════════
--  raw ← curated. يُستدعى بعد إدراج الدفعة في المعاملة نفسها.
--
--  ترتيبُ العمليتين في لقطة التقرير حاسم: يُغلَق الصفُّ الحاليُّ السابق
--  أوّلاً (is_current=false) ثم يُدرَج الجديد. العكسُ يصطدم بالفهرس الفريد
--  snap_current_uq على ثاني حدثٍ لأول تقرير، فتسقط الدفعة كلُّها وتُعاد
--  المحاولة إلى الأبد — أي أن التغذية تموت لحظةَ أن يمرّ أيُّ تقريرٍ
--  بمرحلتين، وهو ما يحدث فوراً لا نادراً.
--
--  والأحداثُ المبتورة (degraded) تُتخطّى: حمولتُها بلا subject، وإسقاطُها
--  كان سيُسقط الدفعة على قيدٍ إلزاميّ.
CREATE OR REPLACE FUNCTION lake.project_batch(p_batch_id bigint)
RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = curated, raw, meta, pg_catalog, pg_temp
AS $fn$
DECLARE
  e        record;
  p        jsonb;
  subj     jsonb;
  rep      jsonb;
  snap_id  bigint;
  n        integer := 0;
  item     jsonb;
  i        integer;
BEGIN
  FOR e IN
    SELECT * FROM raw.report_events
    WHERE batch_id = p_batch_id AND NOT degraded
    ORDER BY occurred_at, lake_seq
  LOOP
    p    := e.payload;
    subj := coalesce(p->'subject', '{}'::jsonb);
    rep  := coalesce(p->'report',  '{}'::jsonb);

    -- ── الأبعاد: صورةُ البُعد وقت الحدث ──
    IF (subj->>'sector_id') IS NOT NULL THEN
      INSERT INTO curated.dim_sector (sector_id, name_ar, code)
      VALUES ((subj->>'sector_id')::int, coalesce(subj->>'sector_name_ar','—'), subj->>'sector_code')
      ON CONFLICT (sector_id) DO UPDATE
        SET name_ar = EXCLUDED.name_ar, code = EXCLUDED.code, updated_at = now();
    END IF;

    FOR item IN SELECT * FROM jsonb_array_elements(coalesce(p->'dimensions'->'workflow_stages','[]'::jsonb))
    LOOP
      INSERT INTO curated.dim_workflow_stage (status_key, position, label_ar, role_code, is_active)
      VALUES (item->>'status_key', (item->>'position')::int, item->>'label_ar',
              item->>'role_code', (item->>'is_active')::boolean)
      ON CONFLICT (status_key) DO UPDATE
        SET position = EXCLUDED.position, label_ar = EXCLUDED.label_ar,
            role_code = EXCLUDED.role_code, is_active = EXCLUDED.is_active, updated_at = now();
    END LOOP;

    -- ── اللقطة اليومية ──
    IF e.event_type = 'daily.snapshot' THEN
      INSERT INTO curated.daily_snapshot (
        report_date, event_uuid, captured_at, sessions_count, present_count,
        excused_count, absent_count, reports_created, reports_approved, payload)
      VALUES (
        (p->>'report_date')::date, e.event_uuid, e.occurred_at,
        (p->'totals'->>'sessions')::int, (p->'totals'->>'present')::int,
        (p->'totals'->>'excused')::int,  (p->'totals'->>'absent')::int,
        (p->'totals'->>'reports_created')::int, (p->'totals'->>'reports_approved')::int, p)
      ON CONFLICT (report_date) DO UPDATE
        SET event_uuid = EXCLUDED.event_uuid, captured_at = EXCLUDED.captured_at,
            sessions_count = EXCLUDED.sessions_count, present_count = EXCLUDED.present_count,
            excused_count = EXCLUDED.excused_count, absent_count = EXCLUDED.absent_count,
            reports_created = EXCLUDED.reports_created, reports_approved = EXCLUDED.reports_approved,
            payload = EXCLUDED.payload;
      n := n + 1;
      CONTINUE;
    END IF;

    -- ── لقطة التحليلات ──
    IF e.event_type = 'analytics.snapshot' THEN
      INSERT INTO curated.analytics_snapshot (snapshot_date, kind, event_uuid, captured_at, payload)
      VALUES ((p->>'snapshot_date')::date, coalesce(p->>'kind','executive'),
              e.event_uuid, e.occurred_at, p)
      ON CONFLICT (snapshot_date, kind) DO UPDATE
        SET event_uuid = EXCLUDED.event_uuid, captured_at = EXCLUDED.captured_at,
            payload = EXCLUDED.payload;
      n := n + 1;
      CONTINUE;
    END IF;

    -- ── أحداث التقرير ──
    IF e.source_assessment_id IS NULL THEN
      CONTINUE;   -- حدثُ تقريرٍ بلا دورة: لا مفتاح طبيعيّ له، يُترك في raw شاهداً
    END IF;

    -- الحدث المُعاد إرساله: أُسقط من قبل. لا يُعاد فتحُ صفٍّ مُغلق.
    IF EXISTS (SELECT 1 FROM curated.report_snapshot WHERE event_uuid = e.event_uuid) THEN
      CONTINUE;
    END IF;

    -- (١) يُغلَق السابق  ← قبل الإدراج، لا بعده.
    UPDATE curated.report_snapshot
       SET is_current = false, valid_to = e.occurred_at
     WHERE source_assessment_id = e.source_assessment_id
       AND is_current;

    -- (٢) ثم يُفتح الجديد.
    INSERT INTO curated.report_snapshot (
      event_uuid, lake_seq, occurred_at, event_type,
      source_report_id, source_assessment_id,
      person_ref, participant_code, sector_id, rank_label, tier, gender, personnel_category,
      status, recommendation, behavioral_fit, technical_fit, overall_fit, return_count,
      approved_at, approved_at_inferred, is_approval_snapshot, is_current, valid_from,
      competency_dim_version, workflow_dim_version, settings_dim_version, payload)
    VALUES (
      e.event_uuid, e.lake_seq, e.occurred_at, e.event_type,
      e.source_report_id, e.source_assessment_id,
      e.person_ref, e.participant_code, e.sector_id,
      subj->>'rank_label', subj->>'tier', subj->>'gender', subj->>'personnel_category',
      coalesce(rep->>'status', 'unknown'), rep->>'recommendation',
      (rep->>'behavioral_fit')::numeric, (rep->>'technical_fit')::numeric,
      (rep->>'overall_fit')::numeric, (rep->>'return_count')::int,
      CASE WHEN e.event_type = 'report.approved' THEN e.occurred_at
           WHEN rep->>'approved_at' IS NOT NULL THEN (rep->>'approved_at')::timestamptz END,
      (rep->>'approved_at_inferred')::boolean,
      -- التقرير المُعبَّأ تاريخياً وهو معتمَد هو لقطةُ اعتماده: لا حدثَ
      -- اعتمادٍ سيأتي له لاحقاً، فلو انتظرناه لَبقي خارج العقد المُجمَّد.
      (e.event_type = 'report.approved'
       OR (e.event_type = 'report.backfilled' AND rep->>'status' = 'approved')),
      true, e.occurred_at,
      p->'dimensions'->>'competency_version',
      p->'dimensions'->>'workflow_version',
      p->'dimensions'->>'settings_version',
      p)
    RETURNING snapshot_id INTO snap_id;

    -- تفصيل الكفاءات المُجمَّد
    i := 0;
    FOR item IN SELECT * FROM jsonb_array_elements(coalesce(p->'breakdown','[]'::jsonb))
    LOOP
      -- بُعدُ الكفاءات يُبنى من التفصيل نفسه: لا يصل البحيرةَ فهرسٌ
      -- مستقلّ للكفاءات، فلو لم يُشتقّ هنا لبقي contract_v1.dim_competencies
      -- فارغاً بلا سببٍ ظاهر للمستهلك.
      IF (item->>'competency_id') IS NOT NULL THEN
        INSERT INTO curated.dim_competency (competency_id, name_ar, type, group_domain, weight, max_level)
        VALUES ((item->>'competency_id')::int, coalesce(item->>'name_ar','—'), item->>'type',
                item->>'group_domain', (item->>'weight')::numeric, (item->>'max_level')::int)
        ON CONFLICT (competency_id) DO UPDATE
          SET name_ar = EXCLUDED.name_ar, type = EXCLUDED.type,
              group_domain = EXCLUDED.group_domain, weight = EXCLUDED.weight,
              max_level = EXCLUDED.max_level, updated_at = now();
      END IF;

      INSERT INTO curated.report_competency (
        snapshot_id, competency_id, name_ar, type, group_domain, weight, max_level,
        avg_score, pct, target_level, gap, met, ord)
      VALUES (snap_id, (item->>'competency_id')::int, item->>'name_ar', item->>'type',
              item->>'group_domain', (item->>'weight')::numeric, (item->>'max_level')::int,
              (item->>'avg_score')::numeric, (item->>'pct')::numeric,
              (item->>'target_level')::numeric, (item->>'gap')::numeric,
              (item->>'met')::boolean, i);
      i := i + 1;
    END LOOP;

    -- نتائج القياس
    i := 0;
    FOR item IN SELECT * FROM jsonb_array_elements(coalesce(p->'measurements','[]'::jsonb))
    LOOP
      INSERT INTO curated.report_measurement (snapshot_id, tool_code, scale_code, score, band, ord)
      VALUES (snap_id, item->>'tool_code', item->>'scale_code',
              (item->>'score')::numeric, item->>'band', i);
      i := i + 1;
    END LOOP;

    -- بنود خطة التطوير
    i := 0;
    FOR item IN SELECT * FROM jsonb_array_elements(coalesce(p->'development_plan','[]'::jsonb))
    LOOP
      INSERT INTO curated.report_development_item (snapshot_id, area, action, priority, ord)
      VALUES (snap_id, item->>'area', item->>'action', item->>'priority', i);
      i := i + 1;
    END LOOP;

    -- الجدولة والحضور بحبيبة التقرير
    i := 0;
    FOR item IN SELECT * FROM jsonb_array_elements(coalesce(p->'activities','[]'::jsonb))
    LOOP
      INSERT INTO curated.report_activity (
        snapshot_id, activity_code, scheduled_date, session_slot,
        attendance_code, evaluation_status, ord)
      VALUES (snap_id, item->>'activity_code',
              nullif(item->>'scheduled_date','')::date, item->>'session_slot',
              item->>'attendance_code', item->>'evaluation_status', i);
      i := i + 1;
    END LOOP;

    n := n + 1;
  END LOOP;

  UPDATE raw.ingest_batches
     SET projected_rows = coalesce(projected_rows, 0) + n,
         closed_at = now()
   WHERE batch_id = p_batch_id;

  RETURN n;
END $fn$;

-- ═══ ٤-٣ إعادة البناء ═════════════════════════════════════════════════
--  curated قابلٌ للهدم بالكامل: raw كاملٌ و project_batch اشتقاقيّ.
--  هذا ما يجعل خطأ النمذجة أمام مستهلكٍ لم يُبنَ بعد قابلاً للتراجع.
CREATE OR REPLACE FUNCTION lake.replay(p_from_batch bigint DEFAULT NULL)
RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = curated, raw, meta, pg_catalog, pg_temp
AS $fn$
DECLARE
  b record;
  total integer := 0;
BEGIN
  IF p_from_batch IS NULL THEN
    TRUNCATE curated.report_snapshot,
             curated.report_competency, curated.report_measurement,
             curated.report_development_item, curated.report_activity,
             curated.daily_snapshot, curated.analytics_snapshot
      RESTART IDENTITY CASCADE;
    UPDATE raw.ingest_batches SET projected_rows = 0;
  END IF;

  FOR b IN
    SELECT DISTINCT batch_id FROM raw.report_events
    WHERE p_from_batch IS NULL OR batch_id >= p_from_batch
    ORDER BY batch_id
  LOOP
    total := total + lake.project_batch(b.batch_id);
  END LOOP;

  RETURN total;
END $fn$;

-- ═══ ٤-٤ المحو ════════════════════════════════════════════════════════
--  البحيرة لا تحمل اسماً ولا رقم هوية ولا جوالاً — فطلبُ المحو يتحقّق
--  بقطع الرابط (person_ref) وتفريغ أيّ نصٍّ حرّ، لا بمحو حقائق مجهولة.
--
--  المستندات تُفرَّغ ولا تُحذف: raw.document_refs يشير إليها بـ
--  ON DELETE RESTRICT، والحذفُ كان سينقض المرجعيّة ويُفقد الدليلَ على
--  ما أُتلف. البصمة تبقى شاهداً؛ الجسدُ يُفرَّغ.
CREATE OR REPLACE FUNCTION lake.apply_erasure(
  p_person_ref char(64), p_reason text DEFAULT NULL, p_by text DEFAULT NULL)
RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path = curated, raw, meta, pg_catalog, pg_temp
AS $fn$
DECLARE
  n_docs  integer := 0;
  n_snaps integer := 0;
  n_evts  integer := 0;
  rid     bigint;
BEGIN
  IF p_person_ref IS NULL THEN
    RAISE EXCEPTION 'المحو يحتاج معرّفاً بديلاً محدَّداً';
  END IF;

  -- (١) تفريغ المستندات المرتبطة
  WITH refs AS (
    SELECT DISTINCT dr.sha256
    FROM raw.document_refs dr
    JOIN raw.report_events ev ON ev.event_uuid = dr.event_uuid
    WHERE ev.person_ref = p_person_ref
  )
  UPDATE raw.documents d
     SET body = ''::bytea, byte_length = 0, erased_at = now()
    FROM refs WHERE d.sha256 = refs.sha256 AND d.erased_at IS NULL;
  GET DIAGNOSTICS n_docs = ROW_COUNT;

  -- (٢) الإسقاط المشتقّ يُحذف كاملاً — لا حصانة عليه
  DELETE FROM curated.report_snapshot WHERE person_ref = p_person_ref;
  GET DIAGNOSTICS n_snaps = ROW_COUNT;

  -- (٣) raw: يُقطع الرابط ويُفرَّغ النصّ، والحقيقةُ المجهولة تبقى.
  --     الباب المقصود في raw.deny_mutation() يُفتح هنا وحده.
  PERFORM set_config('lake.erasure', 'on', true);
  UPDATE raw.report_events
     SET person_ref = NULL,
         participant_code = NULL,
         payload = payload
                   - 'narrative' - 'development_plan'
                   || jsonb_build_object('erased', true, 'erased_at', now())
   WHERE person_ref = p_person_ref;
  GET DIAGNOSTICS n_evts = ROW_COUNT;
  PERFORM set_config('lake.erasure', 'off', true);

  INSERT INTO meta.erasure_log (person_ref, reason, requested_by,
                                events_erased, snapshots_erased, documents_blanked)
  VALUES (p_person_ref, p_reason, p_by, n_evts, n_snaps, n_docs)
  RETURNING erasure_id INTO rid;

  RETURN rid;
END $fn$;

-- ═══ ٤-٥ الامتيازات على الدوالّ ═══════════════════════════════════════
--  المنع عامّ أوّلاً: PostgreSQL يمنح EXECUTE للعموم على كل دالّةٍ جديدة،
--  وتركُ ذلك كان يعني أن خادم التطبيق (lake_writer) يستطيع استدعاء
--  lake.replay() فيمسح curated، أو apply_erasure() فيمحو من شاء.
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA lake FROM PUBLIC;

--  ثم المنح فرديّاً: الكاتب يُسقط ويُهيّئ الأقسام. لا يُعيد البناء، ولا يمحو.
GRANT EXECUTE ON FUNCTION lake.project_batch(bigint)                  TO lake_writer;
GRANT EXECUTE ON FUNCTION lake.k_anonymity()                          TO lake_writer, lake_reader, lake_narrative_reader;
GRANT EXECUTE ON FUNCTION lake.ensure_partitions(integer)             TO lake_writer;
GRANT EXECUTE ON FUNCTION lake.ensure_partitions_from(date, integer)  TO lake_writer;
