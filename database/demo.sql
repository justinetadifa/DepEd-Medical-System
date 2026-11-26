-- Fictional local practice data. Import after install.sql. Reruns leave data alone.
USE deped_medical_v2;
START TRANSACTION;
SET @seed = NOT EXISTS (SELECT 1 FROM employees) AND NOT EXISTS (SELECT 1 FROM accounts);

INSERT INTO employees (id, employee_no, full_name, office, position_title, employment_status)
SELECT 1, 'DEMO-0147', 'Mara Santos', 'Curriculum Implementation Division', 'Education Program Specialist', 'Permanent' FROM DUAL WHERE @seed
UNION ALL SELECT 2, 'DEMO-0221', 'Nico Flores', 'Administrative Division', 'Administrative Assistant', 'Permanent' FROM DUAL WHERE @seed
UNION ALL SELECT 3, 'DEMO-0104', 'Alina Reyes', 'Education Support Services', 'Program Assistant', 'Contract of Service' FROM DUAL WHERE @seed
UNION ALL SELECT 4, 'DEMO-0317', 'Joel Garcia', 'Information and Communications Technology', 'Technical Assistant', 'Permanent' FROM DUAL WHERE @seed;

INSERT INTO accounts (id, email, display_name, role, employee_id)
SELECT 1, 'nurse@example.test', 'Celia Navarro', 'nurse', NULL FROM DUAL WHERE @seed
UNION ALL SELECT 2, 'doctor@example.test', 'Adrian Mercado', 'doctor', NULL FROM DUAL WHERE @seed
UNION ALL SELECT 3, 'personnel@example.test', 'Mara Santos', 'personnel', 1 FROM DUAL WHERE @seed
UNION ALL SELECT 4, 'ict@example.test', 'Iris Bautista', 'ict', NULL FROM DUAL WHERE @seed
UNION ALL SELECT 5, 'personnel2@example.test', 'Nico Flores', 'personnel', 2 FROM DUAL WHERE @seed;

INSERT INTO ape_profiles (employee_id, ape_year, profile_received_at, assessment_date, systolic, diastolic, pulse, weight_kg, height_m, conditions_reported, clinical_assessment, assessed_at, assessed_by)
SELECT 1, 2026, '2026-11-21 08:00:00', '2026-11-21', 118, 77, 71, 58.0, 1.62, 'None reported', '', NULL, NULL FROM DUAL WHERE @seed
UNION ALL SELECT 2, 2026, '2026-11-19 09:00:00', '2026-11-19', 124, 80, 74, 72.2, 1.67, '', '', NULL, NULL FROM DUAL WHERE @seed
UNION ALL SELECT 3, 2026, '2026-11-18 08:00:00', '2026-11-18', 116, 74, 68, 57.2, 1.65, '', 'Annual assessment recorded.', '2026-11-24 14:00:00', 2 FROM DUAL WHERE @seed;

INSERT INTO reports (id, employee_id, ape_year, report_no, report_date, laboratory, review_status, extraction_method, submitted_by, reviewed_by, reviewed_at, created_at)
SELECT 1, 2, 2026, 'LAB-1124-016', '2026-11-24', 'Northstar Diagnostic Lab', 'needs_review', 'demo', 5, NULL, NULL, '2026-11-24 10:30:00' FROM DUAL WHERE @seed
UNION ALL SELECT 2, 3, 2026, 'LAB-1123-009', '2026-11-23', 'Harbor Clinical Laboratory', 'reviewed', 'demo', 1, 2, '2026-11-24 13:45:00', '2026-11-23 11:30:00' FROM DUAL WHERE @seed
UNION ALL SELECT 3, 1, 2026, 'LAB-1121-006', '2026-11-21', 'Northstar Diagnostic Lab', 'reviewed', 'demo', 1, 1, '2026-11-21 13:00:00', '2026-11-21 11:00:00' FROM DUAL WHERE @seed
UNION ALL SELECT 4, 1, 2025, 'LAB-1119-004', '2025-11-19', 'Northstar Diagnostic Lab', 'reviewed', 'demo', 1, 1, '2025-11-20 08:15:00', '2025-11-19 14:00:00' FROM DUAL WHERE @seed;

INSERT INTO report_results (report_id, row_order, test_name, result_value, unit)
SELECT 1, 1, 'Hemoglobin', '13.7', 'g/dL' FROM DUAL WHERE @seed
UNION ALL SELECT 1, 2, 'Hematocrit', '41.2', '%' FROM DUAL WHERE @seed
UNION ALL SELECT 2, 1, 'Creatinine', '0.86', 'mg/dL' FROM DUAL WHERE @seed
UNION ALL SELECT 3, 1, 'Fasting blood glucose', '99', 'mg/dL' FROM DUAL WHERE @seed
UNION ALL SELECT 3, 2, 'Total cholesterol', '180', 'mg/dL' FROM DUAL WHERE @seed
UNION ALL SELECT 4, 1, 'FBS', '103', 'mg/dL' FROM DUAL WHERE @seed
UNION ALL SELECT 4, 2, 'Total cholesterol', '192', 'mg/dL' FROM DUAL WHERE @seed;

INSERT INTO visits (id, employee_id, office, visit_date, reason, chief_complaint, case_category, assessment, treatment, disposition, systolic, diastolic, pulse, created_by, provider_name, provider_role, share_guidance)
SELECT 1, 1, 'Curriculum Implementation Division', '2026-11-05', 'Chronic disease monitoring', 'Routine BP check', 'Cardiometabolic', 'Reading recorded during clinic visit.', 'Vital signs recorded', 'Advised', 123, 81, 73, 1, 'Celia Navarro', 'nurse', '' FROM DUAL WHERE @seed
UNION ALL SELECT 2, 1, 'Curriculum Implementation Division', '2026-11-22', 'Laboratory interpretation', 'Discuss recent lab report', 'Preventive care', 'Reviewed the report with personnel.', 'Results discussed', 'Advised', 118, 77, 71, 2, 'Adrian Mercado', 'doctor', 'Bring your original laboratory report to the clinic for filing.' FROM DUAL WHERE @seed
UNION ALL SELECT 3, 2, 'Administrative Division', '2026-11-24', 'Minor injury management', 'Finger abrasion', 'Minor injury', 'Visit recorded for follow-up.', 'Wound care documented', 'Treated', NULL, NULL, 76, 1, 'Celia Navarro', 'nurse', '' FROM DUAL WHERE @seed;
COMMIT;
