import argparse
from base64 import b64encode
import json
import re
import shutil
import subprocess
from contextlib import closing
from datetime import datetime
from functools import lru_cache
from http.server import BaseHTTPRequestHandler, HTTPServer
from importlib.util import find_spec
from io import BytesIO
from pathlib import Path
from tempfile import TemporaryDirectory


MAX_BYTES = 8 * 1024 * 1024
MIME_EXTENSIONS = {"application/pdf": ".pdf", "image/jpeg": ".jpg", "image/png": ".png"}
TESTS = (
    "fasting blood glucose", "fasting blood sugar", "fasting glucose",
    "total cholesterol", "HDL cholesterol", "LDL cholesterol", "triglycerides",
    "white blood cell count", "red blood cell count", "platelet count",
    "hemoglobin", "hematocrit", "creatinine", "uric acid", "glucose", "WBC", "RBC", "FBS", "FBG",
    "HGB", "HCT", "MCV", "MCH", "MCHC", "PLT", "TC", "TG", "HDL", "LDL", "VLDL", "SGPT", "ALT",
    "CREA", "BUA", "HbA1c", "PSA", "urine - WBC", "urine - RBC", "urine - protein", "urine - glucose",
    "urine - nitrite", "urine - blood", "chest x-ray", "chest xray",
)
LABELS = "|".join(re.escape(test) for test in sorted(TESTS, key=len, reverse=True))
RESULT = re.compile(
    rf"^({LABELS})(?![A-Za-z])(?:\s*[:|]\s*|\s+)([<>≤≥]?[+-]?\d+(?:[.,]\d+)?)"
    r"(?:\s*([A-Za-zµμ%/0-9^×]+(?:\s*/\s*[A-Za-zµμ]+)?))?",
    re.IGNORECASE,
)
QUALITATIVE = re.compile(
    rf"^({LABELS})(?![A-Za-z])(?:\s*[:|]\s*|\s+)"
    r"(not detected|none seen|abnormal|normal|negative|positive|trace|rare)(?:\s*\|\s*.*)?$",
    re.IGNORECASE,
)
UNIT = re.compile(r"^(?:%|(?:mg|mmol|g|ng|[µμu]g|[µμu]mol|u|iu)\s*/\s*(?:dl|ml|l)|"
                  r"(?:[x×]?10\^[0-9]+)?\s*/\s*(?:[µμu]l|l)|fl|pg)$", re.IGNORECASE)
FIELDS = {
    "name": r"(?:patient|employee name|patient name)",
    "employee_no": r"(?:employee id|employee no\.?|employee ref\.?)",
    "report_no": r"(?:report number|report no\.?|report id)",
    "collected_on": r"(?:collection date|date collected|test date|specimen date)",
    "report_date": r"(?:report date|date reported)",
    "laboratory": r"(?:laboratory|lab name)",
}


def normalized_date(value):
    for fmt in ("%Y-%m-%d", "%d %b %Y", "%d %B %Y", "%b %d, %Y"):
        try:
            return datetime.strptime(value, fmt).date().isoformat()
        except ValueError:
            continue
    return ""


def parse_report(text, engine):
    fields = {}
    results = []
    warnings = []
    for raw_line in text.splitlines():
        line = " ".join(raw_line.strip().split())
        if not line:
            continue
        for key, label in FIELDS.items():
            match = re.match(rf"^{label}\s*[:|]\s*(.+)$", line, re.IGNORECASE)
            if match and key not in fields:
                candidate = match[1].strip()
                if key in ("report_date", "collected_on"):
                    candidate = normalized_date(candidate)
                if candidate:
                    fields[key] = candidate[:120]
                break
        match = RESULT.match(line)
        if match and len(results) < 30:
            unit = match[3] or ""
            if not UNIT.fullmatch(unit):
                unit = ""
            results.append({"test_name": match[1], "result_value": match[2], "unit": unit})
        elif len(results) < 30 and (qualitative := QUALITATIVE.match(line)):
            results.append({"test_name": qualitative[1], "result_value": qualitative[2], "unit": ""})
    if "collected_on" in fields:
        fields["report_date"] = fields.pop("collected_on")
    if not results:
        warnings.append("No supported result rows were found. Add the results from the original report.")
    elif any(not result["unit"] and re.search(r"\d", result["result_value"]) for result in results):
        warnings.append("Some units could not be read. Check each unit against the original.")
    if not all(key in fields for key in ("laboratory", "report_no", "report_date")):
        warnings.append("Some report details need manual entry.")
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
        if lines and abs(lines[-1][0][1] - token[1]) <= max(5, token[2] * .55):
            lines[-1].append(token)
        else:
            lines.append([token])
    return "\n".join(" ".join(token[3] for token in sorted(line)) for line in lines)


class RetakeScan(ValueError):
    """A file that should be replaced before review."""


def check_image_quality(image):
    from PIL import ImageFilter, ImageOps, ImageStat

    if min(image.size) < 350:
        raise RetakeScan("Try again. Capture the full page at a higher resolution.")
    gray = ImageOps.grayscale(image)
    gray.thumbnail((1100, 1100))
    w, h = gray.size
    gray = gray.crop((w // 20, h // 20, w - w // 20, h - h // 20))
    laplacian = ImageFilter.Kernel((3, 3), [0, 1, 0, 1, -4, 1, 0, 1, 0], scale=1, offset=128)
    edges = gray.filter(laplacian).crop((2, 2, gray.width - 2, gray.height - 2))
    if ImageStat.Stat(gray).stddev[0] < 4 or ImageStat.Stat(edges).stddev[0] < 2:
        raise RetakeScan("Try again. The page looks blurry or blank. Hold the camera steady and use even light.")


def image_text(path, setting):
    from PIL import Image

    with Image.open(path) as image:
        if image.width * image.height > 30_000_000:
            raise ValueError("Choose a scan under 30 megapixels.")
        check_image_quality(image)
    if setting in ("auto", "paddle") and find_spec("paddleocr") and find_spec("paddle"):
        return paddle_text(path), "paddleocr"
    executable = shutil.which("tesseract")
    if setting != "paddle" and executable:
        run = subprocess.run(
            [executable, str(path), "stdout", "--psm", "6"],
            check=True, capture_output=True, timeout=55, encoding="utf-8",
        )
        return run.stdout, "tesseract"
    raise ValueError("Image OCR is unavailable. Follow the PaddleOCR setup in README.md, or enter the fields manually.")


def render_page(pdf_page, output, scale):
    w, h = pdf_page.get_size()
    if w * h * scale * scale > 30_000_000:
        raise ValueError("The PDF page is too large. Scan it at a normal page size.")
    with closing(pdf_page.render(scale=scale)) as bitmap:
        bitmap.to_pil().save(output, format="PNG")


def extract(data, mime, setting="auto"):
    with TemporaryDirectory(prefix="clinic-ocr-") as directory:
        work = Path(directory)
        if mime != "application/pdf":
            source = work / ("scan" + MIME_EXTENSIONS[mime])
            source.write_bytes(data)
            text, engine = image_text(source, setting)
            return parse_report(text, engine)

        from pypdf import PdfReader

        reader = PdfReader(BytesIO(data))
        if reader.is_encrypted:
            raise RetakeScan("Try again with an unlocked PDF.")
        if len(reader.pages) > 5:
            raise RetakeScan("Try again with a report of up to five pages.")
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
                    render_page(pdf_page, raster, scale=2)
                scanned_text, engine = image_text(raster, setting)
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
            self.write_json(200, {"status": "ready", "image_engine": self.server.ocr_engine})
        else:
            self.write_json(404, {"message": "Use /health or POST a report to /extract."})

    def do_POST(self):
        if self.path != "/extract":
            self.write_json(404, {"message": "Use POST /extract."})
            return
        mime = self.headers.get_content_type()
        if mime not in MIME_EXTENSIONS:
            self.write_json(415, {"message": "Upload a PDF, JPG, or PNG."})
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            length = 0
        if not 0 < length <= MAX_BYTES:
            self.write_json(413, {"message": "Upload a file up to 8 MB."})
            return
        self.connection.settimeout(10)
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
    with HTTPServer(("127.0.0.1", args.port), Handler) as server:
        server.ocr_engine = args.engine
        print(f"Clinic OCR ready at http://127.0.0.1:{args.port}", flush=True)
        server.serve_forever()


if __name__ == "__main__":
    run()
