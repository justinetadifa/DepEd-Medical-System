"""Local laboratory report extraction service (default: http://127.0.0.1:8765).

Endpoints:
- GET  /health  -> {"status": "ready", "image_engine": <cli arg>, "active_image_engine": <resolved>}
- POST /extract -> raw PDF/JPG/PNG body with matching Content-Type header.
                   Returns JSON {"fields", "results", "warnings", "engine", optional "preview_png"}.
                   Returns HTTP 422 with {"message", "retry": true} when the scan is blurry, blank,
                   corrupted, encrypted, or exceeds MAX_PDF_PAGES so PHP prompts the user to retake.

Troubleshooting:
- If image OCR says unavailable, check locate_tesseract() or install PaddleOCR per README.md.
- If a date is left blank with a warning, check parse_date() (ambiguous MM/DD vs DD/MM dates are
  intentionally left blank for nurse/doctor review).
"""

import argparse
from base64 import b64encode
import json
import os
import re
import shutil
import subprocess
import sys
from contextlib import closing
from datetime import datetime
from functools import lru_cache
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from importlib.util import find_spec
from io import BytesIO
from pathlib import Path
from tempfile import TemporaryDirectory


# Hard limits mirrored by PHP (app/scans.php) and client-side JS (public/assets/app.js).
MAX_BYTES = 8 * 1024 * 1024
MAX_PDF_PAGES = 5
MIME_EXTENSIONS = {"application/pdf": ".pdf", "image/jpeg": ".jpg", "image/png": ".png"}

# Supported lab test names matched at the start of a line (longest-first so e.g.
# "fasting blood glucose" matches before "glucose"). Add new local lab test aliases here.
TESTS = (
    "fasting blood glucose", "fasting blood sugar", "fasting glucose", "random blood sugar",
    "total cholesterol", "HDL cholesterol", "LDL cholesterol", "VLDL cholesterol", "triglycerides",
    "white blood cell count", "red blood cell count", "platelet count", "WBC count", "RBC count",
    "hemoglobin", "hematocrit", "platelets", "neutrophils", "lymphocytes", "monocytes",
    "eosinophils", "basophils", "RDW", "MPV", "ESR",
    "serum creatinine", "creatinine", "blood uric acid", "uric acid", "blood urea nitrogen", "BUN",
    "total bilirubin", "direct bilirubin", "indirect bilirubin", "total protein", "albumin", "globulin",
    "alkaline phosphatase", "ALP", "SGPT", "SGOT", "ALT", "AST",
    "sodium", "potassium", "chloride", "glucose", "WBC", "RBC", "FBS", "FBG", "RBS",
    "HGB", "HCT", "MCV", "MCH", "MCHC", "PLT", "TC", "TG", "HDL-C", "LDL-C", "HDL", "LDL", "VLDL",
    "CREA", "BUA", "HbA1c", "TSH", "FT4", "FT3", "PSA",
    "urine - WBC", "urine - RBC", "urine - protein", "urine - glucose", "urine - nitrite", "urine - blood",
    "urine WBC", "urine RBC", "urine protein", "urine glucose", "urine nitrite", "urine blood",
    "pus cells", "specific gravity", "chest x-ray", "chest xray",
)
LABELS = "|".join(re.escape(test) for test in sorted(TESTS, key=len, reverse=True))
# Regex components for structured lab lines:
# - PAREN_ALIAS allows labels like "Fasting Blood Sugar (FBS)"
# - NUM_VALUE preserves printed inequality signs (<, >, <=, >=, ≤, ≥), commas, and decimals
# - UNIT_TOKEN accepts common clinical units while rejecting unrecognized/garbled OCR tokens
PAREN_ALIAS = r"(?:\s*\([A-Za-z0-9/\- ]{1,16}\))?"
NUM_VALUE = r"(?:(?:<=|>=|[<>≤≥])\s*)?[+-]?(?:\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:[.,]\d+)?)"
UNIT_TOKEN = (
    r"(?:%|"
    r"(?:mg|mmol|g|ng|pg|[µμu]g|[µμu]mol|mEq|meq|mIU|miu|[µμu]IU|uIU|u|iu)\s*/\s*(?:dl|ml|l)|"
    r"mm\s*/\s*(?:hr|h)|"
    r"(?:[x×]\s*10\^[0-9]+|10\^[0-9]+|cells|[KM])?\s*/\s*(?:[µμu]l|l|mm3|cumm|hpf|lpf)|"
    r"fl|pg)"
)
RESULT = re.compile(
    rf"^({LABELS}{PAREN_ALIAS})(?![A-Za-z0-9])(?:\s*[:|]\s*|\s+)"
    rf"({NUM_VALUE})"
    rf"(?:\s*\|\s*|\s+)?"
    rf"(?:(?:High|Low|[HL*]|(?:\([HL]\)))\s+)?"
    rf"({UNIT_TOKEN})?(?=$|\s|\|)",
    re.IGNORECASE,
)
RANGE_HPF = re.compile(
    rf"^({LABELS}{PAREN_ALIAS})(?![A-Za-z0-9])(?:\s*[:|]\s*|\s+)"
    r"(\d+\s*[-–]\s*\d+)\s*(/[hHlL][pP][fF])(?=$|\s|\|)",
    re.IGNORECASE,
)
QUALITATIVE = re.compile(
    rf"^({LABELS}{PAREN_ALIAS})(?![A-Za-z0-9])(?:\s*[:|]\s*|\s+)"
    r"(not detected|none seen|non-reactive|nonreactive|reactive|abnormal|normal|negative|positive|trace|rare|few|moderate|many)"
    r"(?:\s*(?:\||$).*)$",
    re.IGNORECASE,
)
UNIT = re.compile(rf"^{UNIT_TOKEN}$", re.IGNORECASE)

FIELDS = (
    ("laboratory", r"(?:laboratory|lab name|diagnostic center|medical center|facility|hospital)"),
    ("employee_no", r"(?:employee id|employee no\.?|employee ref\.?|patient id|hospital no\.?)"),
    ("name", r"(?:patient name|employee name|patient)"),
    ("report_no", r"(?:report number|report no\.?|report id|lab(?:oratory)?\s*(?:no\.?|number|id)|accession\s*(?:no\.?|number|#)|reference\s*(?:no\.?|number))"),
    ("collected_on", r"(?:collection date|date collected|test date|specimen date|date taken|exam(?:ination)? date)"),
    ("report_date", r"(?:report date|date reported|result date|date released|release date|date printed)"),
)
ALL_FIELD_LABELS = "|".join(pattern for _, pattern in FIELDS)
FIELD_SPLIT = re.compile(rf"(?=(?:^|\s+|\s*\|\s*)(?:{ALL_FIELD_LABELS})\s*[:|])", re.IGNORECASE)
FACILITY_BANNER = re.compile(r"\b(?:laboratory|diagnostic|medical center|hospital|clinic)\b", re.IGNORECASE)
SUBTITLE_SKIP = re.compile(r"\b(?:report|chemistry|hematology|urinalysis|fictional|synthetic|summary)\b", re.IGNORECASE)


def parse_date(value):
    """Parse unambiguous report dates to ISO YYYY-MM-DD.

    Returns (iso_date, is_uncertain). Numeric slash/dash dates where both day and month are <= 12
    and differ (e.g. 04/05/2026) return ("", True) so the date is left blank and flagged for review.
    """
    cleaned = re.sub(r"\s+\d{1,2}:\d{2}(?::\d{2})?(?:\s*[AP]M)?$", "", value.strip(), flags=re.IGNORECASE)
    cleaned = re.sub(r"\s+", " ", cleaned)
    for fmt in (
        "%Y-%m-%d", "%Y/%m/%d", "%d %b %Y", "%d %B %Y",
        "%b %d, %Y", "%B %d, %Y", "%b %d %Y", "%B %d %Y",
        "%d-%b-%Y", "%d-%B-%Y",
    ):
        try:
            parsed = datetime.strptime(cleaned, fmt).date()
            if 1900 <= parsed.year <= 2099:
                return parsed.isoformat(), False
        except ValueError:
            continue

    slash = re.fullmatch(r"(\d{1,2})[/-](\d{1,2})[/-](\d{4})", cleaned)
    if slash:
        first, second, year = map(int, slash.groups())
        if 1900 <= year <= 2099:
            if first > 12 and 1 <= second <= 12:
                try:
                    return datetime(year, second, first).date().isoformat(), False
                except ValueError:
                    return "", True
            if second > 12 and 1 <= first <= 12:
                try:
                    return datetime(year, first, second).date().isoformat(), False
                except ValueError:
                    return "", True
            if first == second and 1 <= first <= 12:
                try:
                    return datetime(year, first, second).date().isoformat(), False
                except ValueError:
                    return "", True
            if 1 <= first <= 12 and 1 <= second <= 12:
                return "", True
    return "", bool(cleaned)


def parse_report(text, engine):
    """Extract header fields, measurement rows, and review warnings from plain/OCR text.

    Prefers collection/test date over report/print date when both appear, leaves missing or
    uncertain values blank, and adds human-readable warnings shown on the clinic review screen.
    """
    fields = {}
    results = []
    warnings = []
    ambiguous_dates = []
    lines = [" ".join(raw.strip().split()) for raw in text.splitlines()]
    lines = [line for line in lines if line]

    for line in lines:
        segments = [seg.strip(" |") for seg in FIELD_SPLIT.split(line) if seg.strip(" |")]
        matched_field = False
        for seg in segments:
            for key, label in FIELDS:
                match = re.match(rf"^{label}\s*[:|]\s*(.+)$", seg, re.IGNORECASE)
                if match:
                    matched_field = True
                    if key not in fields:
                        raw_val = match[1].strip()
                        if key in ("report_date", "collected_on"):
                            iso, uncertain = parse_date(raw_val)
                            if iso:
                                fields[key] = iso
                            elif uncertain:
                                ambiguous_dates.append(raw_val[:40])
                        elif raw_val:
                            fields[key] = raw_val[:120]
                    break
        if matched_field and len(segments) >= 1 and not RESULT.match(line):
            continue

        if len(results) >= 40:
            continue
        if range_match := RANGE_HPF.match(line):
            results.append({
                "test_name": range_match[1].strip(),
                "result_value": re.sub(r"\s*", "", range_match[2]),
                "unit": range_match[3].strip(),
            })
        elif match := RESULT.match(line):
            unit = (match[3] or "").strip()
            if unit and not UNIT.fullmatch(unit):
                unit = ""
            results.append({
                "test_name": match[1].strip(),
                "result_value": match[2].strip(),
                "unit": unit,
            })
        elif qualitative := QUALITATIVE.match(line):
            results.append({
                "test_name": qualitative[1].strip(),
                "result_value": qualitative[2].strip(),
                "unit": "",
            })

    if "laboratory" not in fields:
        for header_line in lines[:3]:
            if ":" not in header_line and FACILITY_BANNER.search(header_line) and not SUBTITLE_SKIP.search(header_line):
                fields["laboratory"] = header_line[:120]
                break

    if "collected_on" in fields:
        fields["report_date"] = fields.pop("collected_on")

    if ambiguous_dates and "report_date" not in fields:
        warnings.append(
            f"The printed date ({ambiguous_dates[0]}) is ambiguous or uncertain and was left blank. Confirm the result date against the original."
        )

    if not results:
        warnings.append("No supported result rows were found. Compare the original and enter the results manually.")
    elif any(not result["unit"] and re.search(r"\d", result["result_value"]) for result in results):
        warnings.append("Some numeric results are missing units. Check each unit against the original.")

    missing_labels = [
        label for key, label in (
            ("laboratory", "laboratory"),
            ("report_no", "report number"),
            ("report_date", "result date"),
        ) if not fields.get(key)
    ]
    if missing_labels:
        warnings.append("Missing report details need review: " + ", ".join(missing_labels) + ".")

    return {"fields": fields, "results": results, "warnings": warnings, "engine": engine}


@lru_cache(maxsize=1)
def paddle_model():
    from paddleocr import PaddleOCR

    return PaddleOCR(
        lang="en", device="cpu", use_doc_orientation_classify=False,
        use_doc_unwarping=False, use_textline_orientation=True, enable_mkldnn=False,
    )


def paddle_text(path):
    tokens = []
    for item in paddle_model().predict(str(path)):
        data = item.json["res"]
        for text, box in zip(data["rec_texts"], data["rec_boxes"]):
            if not text.strip():
                continue
            x, y, _, bottom = map(float, box)
            tokens.append((x, (y + bottom) / 2, bottom - y, text))
    lines = []
    for token in sorted(tokens, key=lambda entry: entry[1]):
        if lines and abs(lines[-1][0][1] - token[1]) <= max(5, token[2] * 0.55):
            lines[-1].append(token)
        else:
            lines.append([token])
    return "\n".join(" ".join(token[3] for token in sorted(line)) for line in lines)


def locate_tesseract():
    """Find tesseract on PATH or in standard Conda/Windows install locations so VS Code runs work without manual PATH tweaks."""
    found = shutil.which("tesseract")
    candidates = [Path(found)] if found else []
    prefix = Path(sys.prefix)
    candidates.extend([
        prefix / "Library" / "bin" / "tesseract.exe",
        prefix / "bin" / "tesseract",
        Path(r"C:\Program Files\Tesseract-OCR\tesseract.exe"),
        Path(r"C:\Program Files (x86)\Tesseract-OCR\tesseract.exe"),
    ])
    for candidate in candidates:
        if candidate and candidate.is_file():
            return candidate
    return None


def tesseract_env(executable):
    env = os.environ.copy()
    bin_dir = str(executable.parent)
    if bin_dir not in env.get("PATH", ""):
        env["PATH"] = bin_dir + os.pathsep + env.get("PATH", "")
    if not env.get("TESSDATA_PREFIX"):
        prefix = Path(sys.prefix)
        for tess_dir in (
            prefix / "share" / "tessdata",
            prefix / "Library" / "share" / "tessdata",
            executable.parent / "tessdata",
            executable.parent.parent / "share" / "tessdata",
        ):
            if (tess_dir / "eng.traineddata").is_file():
                env["TESSDATA_PREFIX"] = str(tess_dir)
                break
    return env


class RetakeScan(ValueError):
    """A file that should be replaced before review."""


def check_image_quality(image):
    """Reject low-resolution, blank/dark/overexposed, or heavily blurred scans with RetakeScan (HTTP 422 retry=true)."""
    from PIL import ImageFilter, ImageOps, ImageStat

    if min(image.size) < 350:
        raise RetakeScan("Try again. Capture the full page at a higher resolution.")
    gray = ImageOps.grayscale(image)
    gray.thumbnail((1100, 1100))
    w, h = gray.size
    gray = gray.crop((w // 20, h // 20, w - w // 20, h - h // 20))
    gray_stat = ImageStat.Stat(gray)
    mean_brightness = gray_stat.mean[0]
    gray_stddev = gray_stat.stddev[0]
    laplacian = ImageFilter.Kernel((3, 3), [0, 1, 0, 1, -4, 1, 0, 1, 0], scale=1, offset=128)
    edges = gray.filter(laplacian).crop((2, 2, gray.width - 2, gray.height - 2))
    edge_stddev = ImageStat.Stat(edges).stddev[0]

    if mean_brightness < 25 or gray_stddev < 4.0:
        raise RetakeScan(
            "Try again. The page looks blank, too dark, or overexposed. Place the report flat under even light and capture the full page."
        )
    if edge_stddev < 2.5:
        raise RetakeScan(
            "Try again. The page looks blurry or out of focus. Hold the camera steady, keep the page flat under even light, and retake."
        )


def check_readable_ocr_text(text):
    """Ensure image OCR found legible words before accepting the page; sharp unfamiliar layouts pass this check and open manual review."""
    alnum = re.findall(r"[A-Za-z0-9]", text)
    words = re.findall(r"[A-Za-z]{2,}", text)
    if len(alnum) < 15 or len(words) < 2:
        raise RetakeScan(
            "Try again. No readable text could be extracted from the scan. Flatten the page under even light, focus clearly, and retake."
        )


def prepare_image(source_path, normalized_path):
    from PIL import Image, ImageOps, UnidentifiedImageError

    try:
        with Image.open(source_path) as raw:
            if raw.width * raw.height > 30_000_000:
                raise ValueError("Choose a scan under 30 megapixels.")
            image = ImageOps.exif_transpose(raw)
            check_image_quality(image)
            if image.mode not in ("RGB", "L"):
                image = image.convert("RGB")
            image.save(normalized_path, format="PNG")
    except RetakeScan:
        raise
    except UnidentifiedImageError as error:
        raise RetakeScan("Try again. The image file could not be opened. Upload a clear JPG or PNG scan.") from error


def image_text(path, setting, work_dir):
    normalized = work_dir / (path.stem + "-clean.png")
    prepare_image(path, normalized)

    if setting in ("auto", "paddle") and find_spec("paddleocr") and find_spec("paddle"):
        text = paddle_text(normalized)
        check_readable_ocr_text(text)
        return text, "paddleocr"

    executable = locate_tesseract()
    if setting != "paddle" and executable:
        run = subprocess.run(
            [str(executable), str(normalized), "stdout", "-l", "eng", "--psm", "6"],
            check=True, capture_output=True, timeout=55, encoding="utf-8", errors="replace",
            env=tesseract_env(executable),
        )
        check_readable_ocr_text(run.stdout)
        return run.stdout, "tesseract"

    raise ValueError("Image OCR is unavailable. Install Tesseract or follow the PaddleOCR setup in README.md, or enter the fields manually.")


def render_page(pdf_page, output, scale):
    w, h = pdf_page.get_size()
    max_pixels = 30_000_000
    if w * h * scale * scale > max_pixels:
        scale = (max_pixels / max(w * h, 1)) ** 0.5 * 0.95
        if scale < 0.5:
            raise ValueError("The PDF page is too large. Scan it at a normal page size.")
    with closing(pdf_page.render(scale=scale)) as bitmap:
        bitmap.to_pil().save(output, format="PNG")


def extract(data, mime, setting="auto"):
    """Main extraction pipeline:
    - Images (JPG/PNG): EXIF normalize -> quality check -> configured image OCR -> parse_report.
    - PDFs (<= 5 pages): extract embedded digital text first; render any scanned/text-poor page
      to PNG and fall back to image OCR; also render page 1 as base64 preview_png for PHP review.
    """
    with TemporaryDirectory(prefix="clinic-ocr-") as directory:
        work = Path(directory)
        if mime != "application/pdf":
            source = work / ("scan" + MIME_EXTENSIONS[mime])
            source.write_bytes(data)
            text, engine = image_text(source, setting, work)
            return parse_report(text, engine)

        from pypdf import PdfReader
        from pypdf.errors import PdfReadError

        try:
            reader = PdfReader(BytesIO(data))
        except PdfReadError as error:
            raise RetakeScan("Try again. The PDF file could not be read. Re-export or scan the report and upload again.") from error

        if reader.is_encrypted:
            raise RetakeScan("Try again with an unlocked PDF.")
        page_count = len(reader.pages)
        if page_count < 1:
            raise RetakeScan("Try again. The PDF has no readable pages.")
        if page_count > MAX_PDF_PAGES:
            raise RetakeScan(
                f"Try again. This PDF has {page_count} pages, which exceeds the five-page OCR limit. Split the report into files of up to five pages and upload again."
            )

        text_parts = []
        engine = "pdf-text"
        image_pdf = None
        try:
            for index, page in enumerate(reader.pages):
                text = page.extract_text(extraction_mode="layout") or ""
                if len(text.strip()) >= 45:
                    text_parts.append(text)
                    continue
                if image_pdf is None:
                    import pypdfium2 as pdfium

                    image_pdf = pdfium.PdfDocument(data)
                with closing(image_pdf[index]) as pdf_page:
                    raster = work / f"page-{index + 1}.png"
                    render_page(pdf_page, raster, scale=2.5)
                scanned_text, engine = image_text(raster, setting, work)
                text_parts.append(scanned_text)
        finally:
            if image_pdf is not None:
                image_pdf.close()

        result = parse_report("\n".join(text_parts), engine)
        try:
            import pypdfium2 as pdfium

            preview = BytesIO()
            with closing(pdfium.PdfDocument(data)) as document, closing(document[0]) as page:
                render_page(page, preview, scale=1.65)
            if preview.tell() <= 2 * 1024 * 1024:
                result["preview_png"] = b64encode(preview.getvalue()).decode("ascii")
        except Exception as error:
            print(f"PDF preview unavailable ({type(error).__name__}).", flush=True)
        return result


class Handler(BaseHTTPRequestHandler):
    def write_json(self, status, payload):
        content = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(content)))
        self.end_headers()
        self.wfile.write(content)

    def do_GET(self):
        if self.path == "/health":
            active = (
                "paddleocr" if self.server.ocr_engine in ("auto", "paddle") and find_spec("paddleocr") and find_spec("paddle")
                else ("tesseract" if self.server.ocr_engine != "paddle" and locate_tesseract() else "pdf-only")
            )
            self.write_json(200, {"status": "ready", "image_engine": self.server.ocr_engine, "active_image_engine": active})
        else:
            self.write_json(404, {"message": "Use /health or POST a report to /extract."})

    def do_POST(self):
        if self.path != "/extract":
            self.write_json(404, {"message": "Use POST /extract."})
            return
        mime = self.headers.get_content_type()
        if mime not in MIME_EXTENSIONS:
            self.write_json(415, {"message": "Unsupported file type. Upload a PDF, JPG, or PNG."})
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            length = 0
        if not 0 < length <= MAX_BYTES:
            self.write_json(413, {"message": "This file exceeds the 8 MB upload limit."})
            return
        self.connection.settimeout(15)
        try:
            result = extract(self.rfile.read(length), mime, self.server.ocr_engine)
        except RetakeScan as error:
            self.write_json(422, {"message": str(error), "retry": True})
            return
        except ValueError as error:
            self.write_json(422, {"message": str(error)})
            return
        except ImportError:
            self.write_json(422, {"message": "Install the Python requirements from README.md, or enter the fields manually."})
            return
        except Exception as error:
            print(f"Extraction stopped ({type(error).__name__}).", flush=True)
            self.write_json(422, {"message": "The report could not be read. Try a clearer scan or enter the fields manually."})
            return
        self.write_json(200, result)


def run():
    cli = argparse.ArgumentParser(description="Local laboratory report extraction service")
    cli.add_argument("--port", type=int, default=8765)
    cli.add_argument("--engine", choices=("auto", "paddle", "tesseract"), default="auto")
    args = cli.parse_args()
    with ThreadingHTTPServer(("127.0.0.1", args.port), Handler) as server:
        server.ocr_engine = args.engine
        print(f"Clinic OCR ready at http://127.0.0.1:{args.port}", flush=True)
        server.serve_forever()


if __name__ == "__main__":
    run()
