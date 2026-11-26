-- Optional. Run against a new v2 database after install.sql, before demo.sql.
-- Keep the v1 database and originals backed up. This script only reads v1.
USE deped_medical_v2;
START TRANSACTION;
SET @import_allowed = NOT EXISTS (SELECT 1 FROM employees) AND NOT EXISTS (SELECT 1 FROM reports);

INSERT INTO employees (id, employee_no, full_name, office)
SELECT id, employee_no, full_name, office FROM deped_medical_records.employees WHERE @import_allowed;

INSERT INTO reports (employee_id, ape_year, report_no, report_date, laboratory, review_status, storage_key,
                     source_mime, source_name, extraction_method, legacy_reviewer, reviewed_at, legacy_id, created_at)
SELECT r.employee_id, YEAR(r.report_date), r.report_no, r.report_date, r.laboratory, r.review_status, r.storage_key,
       r.source_mime, r.source_name, r.extraction_method, c.display_name, r.reviewed_at, r.id, r.created_at
FROM deped_medical_records.reports r LEFT JOIN deped_medical_records.clinic_users c ON c.id = r.reviewed_by WHERE @import_allowed;

INSERT INTO report_results (report_id, row_order, test_name, result_value, unit)
SELECT r.id, t.row_order, t.test_name, t.result_value, t.unit
FROM deped_medical_records.report_results t JOIN reports r ON r.legacy_id = t.report_id WHERE @import_allowed;

COMMIT;
