"""Local OCR accuracy benchmark for held-out, de-identified hospital laboratory reports.

Required-field scoring rubric (before any staff corrections):
  - Header fields (3 per report): report_no, report_date (YYYY-MM-DD), laboratory
  - Result row fields (3 per row): test_name, result_value, unit
  - Exact match required for every required field (preserving decimals, inequality signs, and units).
  - Missing fields, wrong fields, missing rows, and extra spurious rows all count as errors.
  - Accuracy = exact_matches / total_required_fields (target: >= 97.0%).

Usage:
  1. Benchmark held-out de-identified hospital reports using a JSON manifest:
       python tools/benchmark-ocr.py --manifest path/to/manifest.json --ocr-url http://127.0.0.1:8765
  2. Run the built-in synthetic multi-layout check (separate from real hospital benchmarks):
       python tools/benchmark-ocr.py --synthetic-demo --ocr-url http://127.0.0.1:8765
"""

import argparse
import json
import mimetypes
import sys
import time
import urllib.request
from io import BytesIO
from pathlib import Path
from tempfile import TemporaryDirectory

HEADER_FIELDS = ("report_no", "report_date", "laboratory")
ROW_FIELDS = ("test_name", "result_value", "unit")


def norm(value):
    return " ".join(str(value or "").strip().split())


def score_report(expected, actual):
    errors = []
    exact = 0
    total = 0

    exp_fields = expected.get("fields", {})
    act_fields = actual.get("fields", {})
    for key in HEADER_FIELDS:
        total += 1
        e_val = norm(exp_fields.get(key, ""))
        a_val = norm(act_fields.get(key, ""))
        if e_val == a_val:
            exact += 1
        else:
            errors.append(f"field.{key}: expected {e_val!r}, got {a_val!r}")

    exp_rows = expected.get("results", [])
    act_rows = list(actual.get("results", []))
    used_actual = set()

    for idx, e_row in enumerate(exp_rows):
        e_name = norm(e_row.get("test_name", "")).lower()
        match_idx = None
        for j, a_row in enumerate(act_rows):
            if j not in used_actual and norm(a_row.get("test_name", "")).lower() == e_name:
                match_idx = j
                break
        if match_idx is not None:
            used_actual.add(match_idx)
            a_row = act_rows[match_idx]
            for key in ROW_FIELDS:
                total += 1
                e_val = norm(e_row.get(key, ""))
                a_val = norm(a_row.get(key, ""))
                if e_val == a_val:
                    exact += 1
                else:
                    errors.append(f"row[{idx}].{key}: expected {e_val!r}, got {a_val!r}")
        else:
            for key in ROW_FIELDS:
                total += 1
                errors.append(f"row[{idx}].{key}: missing row (expected {norm(e_row.get(key, ''))!r})")

    for j, a_row in enumerate(act_rows):
        if j not in used_actual:
            for key in ROW_FIELDS:
                total += 1
                errors.append(f"extra_row[{j}].{key}: unexpected value {norm(a_row.get(key, ''))!r}")

    return exact, total, errors


def call_ocr(ocr_url, file_bytes, mime):
    req = urllib.request.Request(
        ocr_url.rstrip("/") + "/extract",
        data=file_bytes,
        headers={"Content-Type": mime},
        method="POST",
    )
    t0 = time.perf_counter()
    with urllib.request.urlopen(req, timeout=60) as resp:
        payload = json.loads(resp.read().decode("utf-8"))
    elapsed_ms = (time.perf_counter() - t0) * 1000.0
    return payload, elapsed_ms


def render_synthetic_report_png(lines, output_path, width=1650, height=2200):
    from PIL import Image, ImageDraw, ImageFont

    img = Image.new("RGB", (width, height), (255, 255, 255))
    draw = ImageDraw.Draw(img)
    font_candidates = [
        r"C:\Windows\Fonts\arial.ttf",
        r"C:\Windows\Fonts\calibri.ttf",
        r"C:\Windows\Fonts\segoeui.ttf",
    ]
    font_path = next((p for p in font_candidates if Path(p).is_file()), None)
    title_font = ImageFont.truetype(font_path, 44) if font_path else ImageFont.load_default()
    body_font = ImageFont.truetype(font_path, 34) if font_path else ImageFont.load_default()

    y = 90
    for idx, line in enumerate(lines):
        font = title_font if idx == 0 else body_font
        draw.text((100, y), line, fill=(20, 20, 20), font=font)
        y += 74 if idx == 0 else 58
    img.save(output_path, format="PNG")


def build_synthetic_suite(work_dir):
    """Build 6 diverse synthetic hospital-style reports (PNG, JPG, scanned PDF) distinct from Northstar sample."""
    from PIL import Image

    cases = [
        {
            "id": "SYN-HOSP-01-chemistry-png",
            "format": "png",
            "lines": [
                "Metro Regional Medical Center",
                "Laboratory: Metro Regional Medical Center",
                "Patient Name: De-identified Case 01",
                "Report No.: MRMC-2026-1042",
                "Date Collected: 14-Nov-2026 07:30 AM",
                "Test   Result   Unit   Reference",
                "Fasting blood sugar   108.5   mg/dL   70-100",
                "Total cholesterol   214   mg/dL   <200",
                "HDL cholesterol   52.4   mg/dL   >40",
                "LDL cholesterol   <=130   mg/dL   <130",
                "Triglycerides   148   mg/dL   <150",
            ],
            "expected": {
                "fields": {
                    "report_no": "MRMC-2026-1042",
                    "report_date": "2026-11-14",
                    "laboratory": "Metro Regional Medical Center",
                },
                "results": [
                    {"test_name": "Fasting blood sugar", "result_value": "108.5", "unit": "mg/dL"},
                    {"test_name": "Total cholesterol", "result_value": "214", "unit": "mg/dL"},
                    {"test_name": "HDL cholesterol", "result_value": "52.4", "unit": "mg/dL"},
                    {"test_name": "LDL cholesterol", "result_value": "<=130", "unit": "mg/dL"},
                    {"test_name": "Triglycerides", "result_value": "148", "unit": "mg/dL"},
                ],
            },
        },
        {
            "id": "SYN-HOSP-02-cbc-jpg",
            "format": "jpg",
            "lines": [
                "Saint Jude Clinical Laboratory",
                "Patient: De-identified Case 02",
                "Accession No.: SJCL-88219",
                "Result Date: November 18, 2026",
                "Hematology Panel",
                "Hemoglobin   14.2   g/dL   12.0-16.0",
                "Hematocrit   42.6   %   36.0-46.0",
                "Platelet count   245,000   /uL   150000-450000",
                "WBC   6.4   x10^9/L   4.0-10.0",
                "MCV   88.5   fL   80-100",
            ],
            "expected": {
                "fields": {
                    "report_no": "SJCL-88219",
                    "report_date": "2026-11-18",
                    "laboratory": "Saint Jude Clinical Laboratory",
                },
                "results": [
                    {"test_name": "Hemoglobin", "result_value": "14.2", "unit": "g/dL"},
                    {"test_name": "Hematocrit", "result_value": "42.6", "unit": "%"},
                    {"test_name": "Platelet count", "result_value": "245,000", "unit": "/uL"},
                    {"test_name": "WBC", "result_value": "6.4", "unit": "x10^9/L"},
                    {"test_name": "MCV", "result_value": "88.5", "unit": "fL"},
                ],
            },
        },
        {
            "id": "SYN-HOSP-03-renal-liver-scanned-pdf",
            "format": "scanned_pdf",
            "lines": [
                "Cordillera Diagnostic Center",
                "Laboratory: Cordillera Diagnostic Center",
                "Patient Name: De-identified Case 03   Report ID: CDC-2026-309",
                "Date Released: 11/22/2026",
                "Creatinine   0.86   mg/dL   0.60-1.20",
                "Uric acid   6.1   mg/dL   3.5-7.2",
                "BUN   14.5   mg/dL   7.0-20.0",
                "SGPT   28   U/L   <40",
                "SGOT   24   U/L   <35",
            ],
            "expected": {
                "fields": {
                    "report_no": "CDC-2026-309",
                    "report_date": "2026-11-22",
                    "laboratory": "Cordillera Diagnostic Center",
                },
                "results": [
                    {"test_name": "Creatinine", "result_value": "0.86", "unit": "mg/dL"},
                    {"test_name": "Uric acid", "result_value": "6.1", "unit": "mg/dL"},
                    {"test_name": "BUN", "result_value": "14.5", "unit": "mg/dL"},
                    {"test_name": "SGPT", "result_value": "28", "unit": "U/L"},
                    {"test_name": "SGOT", "result_value": "24", "unit": "U/L"},
                ],
            },
        },
        {
            "id": "SYN-HOSP-04-inequality-electrolytes-png",
            "format": "png",
            "lines": [
                "Ilocos Valley Hospital Laboratory",
                "Laboratory: Ilocos Valley Hospital Laboratory",
                "Report Number: IVH-2026-7741",
                "Report Date: 2026/11/25",
                "PSA   <0.05   ng/mL",
                "HbA1c   5.8   %",
                "Sodium   140   mEq/L",
                "Potassium   4.2   mEq/L",
                "TSH   >=4.50   mIU/L",
            ],
            "expected": {
                "fields": {
                    "report_no": "IVH-2026-7741",
                    "report_date": "2026-11-25",
                    "laboratory": "Ilocos Valley Hospital Laboratory",
                },
                "results": [
                    {"test_name": "PSA", "result_value": "<0.05", "unit": "ng/mL"},
                    {"test_name": "HbA1c", "result_value": "5.8", "unit": "%"},
                    {"test_name": "Sodium", "result_value": "140", "unit": "mEq/L"},
                    {"test_name": "Potassium", "result_value": "4.2", "unit": "mEq/L"},
                    {"test_name": "TSH", "result_value": ">=4.50", "unit": "mIU/L"},
                ],
            },
        },
        {
            "id": "SYN-HOSP-05-urinalysis-png",
            "format": "png",
            "lines": [
                "La Union Health Diagnostic Clinic",
                "Laboratory: La Union Health Diagnostic Clinic",
                "Lab No.: LUHD-5501",
                "Specimen Date: 20 Nov 2026",
                "Urine - Protein   Negative",
                "Urine - Glucose   Negative",
                "Urine - Nitrite   Negative",
                "Urine - Blood   Trace",
                "Urine - WBC   0-2   /hpf",
            ],
            "expected": {
                "fields": {
                    "report_no": "LUHD-5501",
                    "report_date": "2026-11-20",
                    "laboratory": "La Union Health Diagnostic Clinic",
                },
                "results": [
                    {"test_name": "Urine - Protein", "result_value": "Negative", "unit": ""},
                    {"test_name": "Urine - Glucose", "result_value": "Negative", "unit": ""},
                    {"test_name": "Urine - Nitrite", "result_value": "Negative", "unit": ""},
                    {"test_name": "Urine - Blood", "result_value": "Trace", "unit": ""},
                    {"test_name": "Urine - WBC", "result_value": "0-2", "unit": "/hpf"},
                ],
            },
        },
        {
            "id": "SYN-HOSP-06-ambiguous-date-flag-png",
            "format": "png",
            "lines": [
                "Pangasinan Care Diagnostic Lab",
                "Laboratory: Pangasinan Care Diagnostic Lab",
                "Report No.: PCDL-2026-091",
                "Report Date: 04/05/2026",
                "Fasting blood glucose   128 H   mg/dL",
                "Total cholesterol   242 H   mg/dL",
                "Creatinine   1.02   mg/dL",
            ],
            "expected": {
                "fields": {
                    "report_no": "PCDL-2026-091",
                    "report_date": "",
                    "laboratory": "Pangasinan Care Diagnostic Lab",
                },
                "results": [
                    {"test_name": "Fasting blood glucose", "result_value": "128", "unit": "mg/dL"},
                    {"test_name": "Total cholesterol", "result_value": "242", "unit": "mg/dL"},
                    {"test_name": "Creatinine", "result_value": "1.02", "unit": "mg/dL"},
                ],
            },
        },
    ]

    entries = []
    for case in cases:
        png_path = work_dir / f"{case['id']}.png"
        render_synthetic_report_png(case["lines"], png_path)
        if case["format"] == "png":
            file_path, mime = png_path, "image/png"
        elif case["format"] == "jpg":
            jpg_path = work_dir / f"{case['id']}.jpg"
            with Image.open(png_path) as im:
                im.convert("RGB").save(jpg_path, format="JPEG", quality=92)
            file_path, mime = jpg_path, "image/jpeg"
        else:
            pdf_path = work_dir / f"{case['id']}.pdf"
            with Image.open(png_path) as im:
                im.convert("RGB").save(pdf_path, format="PDF", resolution=200.0)
            file_path, mime = pdf_path, "application/pdf"
        entries.append({
            "id": case["id"],
            "file": str(file_path),
            "mime": mime,
            "expected": case["expected"],
        })
    return entries


def main():
    parser = argparse.ArgumentParser(description="Benchmark local OCR accuracy against required fields.")
    parser.add_argument("--ocr-url", default="http://127.0.0.1:8765")
    parser.add_argument("--manifest", help="Path to JSON manifest of held-out de-identified hospital reports.")
    parser.add_argument("--synthetic-demo", action="store_true", help="Run built-in synthetic multi-layout check.")
    args = parser.parse_args()

    if not args.manifest and not args.synthetic_demo:
        parser.error("Provide --manifest <path> for held-out hospital reports, or --synthetic-demo.")

    with TemporaryDirectory(prefix="deped-ocr-bench-") as tmp:
        if args.manifest:
            manifest_path = Path(args.manifest).resolve()
            raw_entries = json.loads(manifest_path.read_text(encoding="utf-8"))
            entries = []
            for item in raw_entries:
                fpath = (manifest_path.parent / item["file"]).resolve()
                mime = item.get("mime") or mimetypes.guess_type(str(fpath))[0] or "application/pdf"
                entries.append({"id": item["id"], "file": str(fpath), "mime": mime, "expected": item["expected"]})
            label = f"Held-out manifest ({manifest_path.name})"
        else:
            entries = build_synthetic_suite(Path(tmp))
            label = "Synthetic multi-layout suite (NOT real patient hospital reports)"

        total_exact = 0
        total_required = 0
        print(f"=== OCR Accuracy Benchmark: {label} ===")
        print(f"Required fields: header={HEADER_FIELDS}, per-row={ROW_FIELDS}")
        for entry in entries:
            data = Path(entry["file"]).read_bytes()
            actual, ms = call_ocr(args.ocr_url, data, entry["mime"])
            exact, total, errors = score_report(entry["expected"], actual)
            total_exact += exact
            total_required += total
            pct = (100.0 * exact / total) if total else 0.0
            print(f"- {entry['id']:38s} | engine={actual.get('engine','?'):9s} | {ms:6.1f} ms | {exact:2d}/{total:2d} ({pct:5.1f}%) | warnings={len(actual.get('warnings', []))}")
            for err in errors:
                print(f"    MISMATCH: {err}")

        overall = (100.0 * total_exact / total_required) if total_required else 0.0
        print(f"TOTAL: {total_exact}/{total_required} exact matches = {overall:.2f}% (target >= 97.00%)")
        return 0 if overall >= 97.0 else 1


if __name__ == "__main__":
    sys.exit(main())
