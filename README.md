# DepEd Employee Health

A PHP and XAMPP baseline for DepEd Regional Office I. The Clinic, Personnel, and ICT workspaces use one employee roster. This package uses fictional practice data.

## Start in XAMPP

Use XAMPP with PHP 8.1 or newer. Enable PDO MySQL and Fileinfo. Enable cURL for Python extraction.

1. Extract the ZIP. Move the **deped-medical-v2** folder to **C:\xampp\htdocs\deped-medical-v2**.
2. Start **Apache** and **MySQL** in XAMPP Control Panel.
3. Open [phpMyAdmin](http://localhost/phpmyadmin/). Use **Import** to run **database/install.sql**. Import **database/demo.sql** next. This creates the **deped_medical_v2** database and a small fictional roster. You can rerun the installer and the demo import after setup.
4. Open [Employee Health](http://localhost/deped-medical-v2/public/). Choose **Nurse**, **Doctor**, **Personnel**, or **ICT staff**, then enter the local practice code shown on the next page.

If you use a custom MariaDB password or port, copy **app/config.local.example.php** to **app/config.local.php**. Set the needed values and keep the file private. For port 8080 on Apache, add **:8080** to the localhost URLs. Report originals default to **C:\xampp\deped-medical-v2-data**, outside **htdocs**.

To allow uploads up to 8 MB, check **upload_max_filesize** and **post_max_size** in XAMPP's PHP settings. Use **8M** and **12M** respectively if your current limits are lower. For image OCR, use a PHP **max_execution_time** of at least **60** seconds. Restart Apache after editing **php.ini**.

## Practice the clinic workflow

1. Sign in as **Personnel**. Open **Upload lab report → Load sample report** to submit Mara Santos's fictional lab file. It shows **Pending**.
2. Sign out and select **Nurse**. Open the sample from the review queue. Compare the original to the editable fields. Make a correction if needed. Confirm that you checked the employee and values, then select **Approve report**.
3. Open **Details** to mark the paper original received. You can record receipt before or after digital approval. Sign in as Personnel to see **Submitted**, the reviewed readings, and **Print / Save PDF**.
4. Open **Annual physical exams** in the clinic workspace. Use year **2026** for the provided examples. The profile, lab submission, and clinician assessment remain separate.
5. Choose **Record visit** to see the vital signs, clinical notes, disposition, and guidance fields. Use the saved visit's print views for the F010-style register and F023-style examination record. Signatures have fields for clinic completion. Use **Reports & exports** for APE totals by office and monthly services and referrals.

Personnel accounts are restricted to their linked record. The ICT workspace is a read-only account directory. Only the guidance entered for the employee and the follow-up date are displayed from a clinic visit. The doctor workspace can record prescribed medicines in the visit.

## Run Python from VS Code

The demo opens without Python. A regular upload can still be reviewed manually whenever the extraction service is offline. Load the supplied sample in a fresh database if you want to test its extraction and save it as a new report.

1. In VS Code, open **C:\xampp\htdocs\deped-medical-v2** using **File → Open Folder**. Install the recommended Microsoft **Python** extension when prompted.
2. Open **Anaconda Prompt** and run these commands. An existing compatible Conda environment also works.

~~~bat
cd /d C:\xampp\htdocs\deped-medical-v2
conda create -n deped-ocr python=3.12
conda activate deped-ocr
python -m pip install -r ocr\requirements.txt
python ocr\server.py
~~~

3. Keep that terminal open. [OCR health check](http://127.0.0.1:8765/health) should return **ready**. To run it inside VS Code, choose **Python: Select Interpreter** from the Command Palette with **Ctrl + Shift + P**, choose **deped-ocr**, then use **Run and Debug → Run local clinic OCR**.

Python and PHP run as separate local programs. PHP sends the file to **POST /extract** on port **8765**. Python returns the report fields, result rows, and warnings as JSON. The clinic verifies them before approval.

### Photos, USB document cameras, and scanned PDFs

The base Python setup extracts text from PDFs. For photos and scanned PDFs, install an image OCR engine. With an existing Tesseract installation on PATH, start **python ocr\server.py --engine tesseract**. For PaddleOCR, use the same Conda environment.

~~~bat
python -m pip install paddlepaddle==3.2.0 -i https://www.paddlepaddle.org.cn/packages/stable/cpu/
python -m pip install -r ocr\requirements-paddle.txt
python ocr\server.py --engine paddle
~~~

Stop an earlier Python server with **Ctrl + C** before switching. PaddleOCR can download its pretrained models on the first image pass. Upload **samples/northstar-report.png** to try image extraction. If the same PDF sample is already in your records, use the PNG to try another file.

Connect the camera and choose **Use camera** on the upload screen. Allow the browser to use it. Select the USB camera in the camera list, then use **Capture page**. A flatbed scanner can save a PDF or image for upload. Image OCR checks for obviously blurry, blank, or low-resolution pages. Use the manual-entry form for a readable report whose layout the parser does not recognize. Larger reports can be split into up to five pages per upload for extraction.

## Enable DepEd email OTP with your ICT team

Practice accounts use **example.test**. For an approved local pilot, start with **database/install.sql** on a clean database. Set **demo_mode** to **false** in **app/config.local.php**, confirm the allowed **email_domains**, and set **mail_from** to the authorized sender. Configure the machine's PHP mail transport to use the approved relay. The code expires after five minutes and allows five verification attempts. Test delivery to a pilot mailbox with ICT before use.

Provision accounts from the project folder. First create a nurse or doctor account, sign in, and use the clinic's employee directory to add any personnel records. Link each personnel account to the correct employee ID.

~~~bat
C:\xampp\php\php.exe tools\provision-account.php nurse@deped.gov.ph nurse "Clinic nurse"
C:\xampp\php\php.exe tools\provision-account.php employee@deped.gov.ph personnel "Employee Name" EMP-0147
~~~

The examples use sample addresses. This starter runs on localhost. Review server configuration, backups, approved email delivery, upload storage, and role assignments before using employee records. Trial OCR against de-identified reports from the actual labs, checking the test name, value, unit, and date before measuring against the 97% target.

## Moving records from the first ZIP

The v2 folder and database use different names so you can keep the first ZIP and its records. If you saved reports in the first version, back up its database and originals. Run **database/import-v1.sql** on a fresh v2 database after **install.sql**, before adding demo data. Copy the old original reports and their previews from **C:\xampp\deped-medical-data** to the configured v2 storage folder. The import preserves records and previous review labels. Provision the new role-based accounts afterward.

## Main files

| File | Purpose |
|---|---|
| public/index.php | Session and role checks, then route loading |
| app/actions.php | Form handlers for approval, roster, profiles, and visits |
| app/clinical.php | APE and consultation record logic |
| app/records.php | Lab report drafts, review, search, and comparisons |
| app/screens and public/assets | Workspace screens, forms, and print styles |
| app/scans.php and ocr/server.py | Local scan intake and extraction |
| database/install.sql | MySQL or MariaDB schema |
| reference/UPSTREAM.md | Pinned MIT PHP repository, source, and license |

- [Apache Friends XAMPP Windows FAQ](https://www.apachefriends.org/faq_windows.html)
- [VS Code Python environments](https://code.visualstudio.com/docs/python/environments)
- [PHP mail configuration](https://www.php.net/manual/en/mail.configuration.php)
- [PaddleOCR installation and quick start](https://www.paddleocr.ai/v3.3.0/en/quick_start.html)
