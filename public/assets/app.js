"use strict";

/**
 * Client-side behavior for DepEd Employee Health:
 * 1. Dynamic add/remove laboratory result rows on the clinic review form.
 * 2. Double-submit guards ([data-once-form]) that preserve clicked submitter values (e.g. approve=1).
 * 3. Camera selection, live preview, full-page capture, still-image preview + Retake, file validation
 *    (PDF/JPG/PNG, <= 8 MB), and "Reading report…" submission lock on the upload screen.
 * 4. Print trigger ([data-print]) on printable views.
 */

const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;
const ALLOWED_MIME = new Set(["application/pdf", "image/jpeg", "image/png"]);
const ALLOWED_EXT = /\.(?:pdf|jpe?g|png)$/i;

// --- 1. Editable laboratory test rows (review screen) ---
const rows = document.querySelector("[data-test-rows]");
const addResult = document.querySelector("[data-add-row]");
if (rows && addResult) {
    const rowTemplate = document.querySelector("#result-row-template");
    addResult.addEventListener("click", () => {
        if (rows.children.length < 40) {
            rows.append(rowTemplate.content.cloneNode(true));
            rows.lastElementChild.querySelector("input").focus();
        }
        addResult.disabled = rows.children.length >= 40;
    });
    rows.addEventListener("click", event => {
        if (!(event.target instanceof HTMLElement) || !event.target.matches("[data-remove-row]")) {
            return;
        }
        const resultRow = event.target.closest(".result-row");
        if (rows.children.length === 1) {
            resultRow.querySelectorAll("input").forEach(field => {
                field.value = "";
                field.classList.remove("needs-check");
            });
        } else {
            resultRow.remove();
        }
        addResult.disabled = false;
    });
}

document.querySelectorAll(".needs-check").forEach(input => {
    input.addEventListener("input", () => {
        if (input.value.trim() !== "") {
            input.classList.remove("needs-check");
        }
    });
});

// --- 2. Prevent duplicate submissions on manual entry, sample load, paper receipt, and review forms ---
// Note: we copy event.submitter (e.g. name="approve" value="1") into a hidden input and disable buttons
// on the next tick so browsers never omit the clicked button's name/value from the POST payload.
document.querySelectorAll("form[data-once-form]").forEach(form => {
    let locked = false;
    form.addEventListener("submit", event => {
        if (locked) {
            event.preventDefault();
            return;
        }
        locked = true;
        const submitter = event.submitter;
        if (submitter instanceof HTMLButtonElement && submitter.name) {
            const hidden = document.createElement("input");
            hidden.type = "hidden";
            hidden.name = submitter.name;
            hidden.value = submitter.value;
            form.append(hidden);
        }
        window.setTimeout(() => {
            form.querySelectorAll("button[type=submit]").forEach(btn => { btn.disabled = true; });
        }, 0);
    });
    window.addEventListener("pageshow", () => {
        locked = false;
        form.querySelectorAll("button[type=submit]").forEach(btn => { btn.disabled = false; });
    });
});

// --- 3. Lab upload & built-in / USB document camera workflow (intake screen) ---
const upload = document.querySelector("[data-upload-form]");
if (upload) {
    const panel = upload.querySelector("[data-camera-panel]");
    const list = upload.querySelector("[data-camera-list]");
    const view = upload.querySelector("[data-camera-view]");
    const status = upload.querySelector("[data-camera-message]");
    const open = upload.querySelector("[data-camera-open]");
    const uploadTrigger = upload.querySelector("[data-upload-trigger]");
    const captureBtn = upload.querySelector("[data-capture]");
    const closeBtn = upload.querySelector("[data-camera-close]");
    const previewBox = upload.querySelector("[data-capture-preview]");
    const previewImg = upload.querySelector("[data-capture-image]");
    const previewTitle = upload.querySelector("[data-capture-title]");
    const previewMeta = upload.querySelector("[data-capture-meta]");
    const retakeBtn = upload.querySelector("[data-retake]");
    const clearCaptureBtn = upload.querySelector("[data-clear-capture]");
    const fileSummary = upload.querySelector("[data-file-summary]");
    const submit = upload.querySelector("[data-submit-report]") || upload.querySelector("button[type=submit]");
    const file = upload.querySelector("input[type=file]");
    const defaultSubmitText = submit.textContent;

    let stream;
    let previewUrl = "";
    let lastDeviceId = "";
    let fromCamera = false;
    let submitting = false;

    function formatSize(bytes) {
        if (bytes >= 1024 * 1024) {
            return (bytes / (1024 * 1024)).toFixed(2) + " MB";
        }
        return Math.max(1, Math.round(bytes / 1024)) + " KB";
    }

    function showMessage(message, kind = "") {
        status.textContent = message;
        status.className = kind ? "notice " + kind : "notice";
        status.hidden = !message;
    }

    function revokePreview() {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = "";
        }
        if (previewImg) {
            previewImg.removeAttribute("src");
        }
        if (previewBox) {
            previewBox.hidden = true;
        }
    }

    function updateFileSummary(selected) {
        if (!fileSummary) {
            return;
        }
        if (!selected) {
            fileSummary.textContent = "";
            fileSummary.hidden = true;
            return;
        }
        fileSummary.textContent = "Selected: " + selected.name + " (" + formatSize(selected.size) + ")";
        fileSummary.hidden = false;
    }

    function stopCamera() {
        if (stream) {
            stream.getTracks().forEach(track => {
                track.onended = null;
                track.stop();
            });
            stream = undefined;
        }
        view.srcObject = null;
        if (captureBtn) {
            captureBtn.disabled = true;
        }
        panel.hidden = true;
    }

    async function refreshCameraList(activeDeviceId) {
        if (!navigator.mediaDevices?.enumerateDevices) {
            return;
        }
        const devices = (await navigator.mediaDevices.enumerateDevices()).filter(device => device.kind === "videoinput");
        list.replaceChildren(
            ...devices.map((device, index) => new Option(device.label || "Camera " + (index + 1), device.deviceId))
        );
        if (activeDeviceId && devices.some(device => device.deviceId === activeDeviceId)) {
            list.value = activeDeviceId;
        } else if (list.options[0]) {
            list.value = list.options[0].value;
        }
        if (list.value) {
            lastDeviceId = list.value;
        }
    }

    async function startCamera(deviceId) {
        if (submitting) {
            return;
        }
        if (!navigator.mediaDevices?.getUserMedia) {
            showMessage("Camera access requires http://localhost or HTTPS. Use Upload file instead.", "error");
            return;
        }
        open.disabled = true;
        stopCamera();
        revokePreview();
        try {
            const preferredId = deviceId || lastDeviceId;
            const constraints = preferredId
                ? { deviceId: { exact: preferredId }, width: { ideal: 1920 }, height: { ideal: 1080 } }
                : { facingMode: { ideal: "environment" }, width: { ideal: 1920 }, height: { ideal: 1080 } };
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: constraints, audio: false });
            } catch (firstError) {
                if (preferredId) {
                    stream = await navigator.mediaDevices.getUserMedia({
                        video: { width: { ideal: 1920 }, height: { ideal: 1080 } },
                        audio: false,
                    });
                } else {
                    throw firstError;
                }
            }
            const track = stream.getVideoTracks()[0];
            if (track) {
                track.onended = () => {
                    stopCamera();
                    showMessage("The camera disconnected. Reconnect it or choose Upload file.", "error");
                };
            }
            view.srcObject = stream;
            const activeDevice = track ? track.getSettings().deviceId : "";
            await refreshCameraList(activeDevice);
            panel.hidden = false;
            if (captureBtn) {
                captureBtn.disabled = !(view.videoWidth > 0);
            }
            showMessage("");
        } catch {
            stopCamera();
            showMessage("Camera access failed. Check browser camera permissions or use Upload file.", "error");
        } finally {
            open.disabled = false;
        }
    }

    function validateSelectedFile(selected) {
        if (!selected) {
            return "Choose a PDF, JPG, or PNG file, or capture a page with your camera.";
        }
        if (selected.size < 1) {
            return "Try again. The selected file is empty.";
        }
        if (selected.size > MAX_UPLOAD_BYTES) {
            return "This file exceeds the 8 MB upload limit (" + formatSize(selected.size) + "). Split the report or scan at a lower resolution and try again.";
        }
        const mimeOk = selected.type ? ALLOWED_MIME.has(selected.type) : false;
        const extOk = ALLOWED_EXT.test(selected.name || "");
        if (!mimeOk && !extOk) {
            return "Unsupported file type. Upload a PDF, JPG, or PNG laboratory report.";
        }
        return "";
    }

    function showImagePreview(selected, capturedFromCamera, dimensionsText = "") {
        revokePreview();
        if (!previewBox || !previewImg || !selected) {
            return;
        }
        const isImage = selected.type === "image/jpeg" || selected.type === "image/png" || /\.(?:jpe?g|png)$/i.test(selected.name);
        if (!isImage) {
            return;
        }
        previewUrl = URL.createObjectURL(selected);
        previewImg.src = previewUrl;
        if (previewTitle) {
            previewTitle.textContent = capturedFromCamera ? "Captured page preview" : "Selected image preview";
        }
        if (previewMeta) {
            const parts = [formatSize(selected.size)];
            if (dimensionsText) {
                parts.unshift(dimensionsText);
            }
            previewMeta.textContent = (capturedFromCamera
                ? "Check that all text, numbers, and units are sharp before submitting · "
                : selected.name + " · ") + parts.join(" · ");
        }
        if (retakeBtn) {
            retakeBtn.hidden = !capturedFromCamera;
        }
        previewBox.hidden = false;
    }

    view.addEventListener("loadedmetadata", () => {
        if (captureBtn && view.videoWidth > 0) {
            captureBtn.disabled = false;
        }
    });

    if (uploadTrigger) {
        uploadTrigger.addEventListener("click", () => {
            if (!submitting) {
                file.click();
            }
        });
    }

    file.addEventListener("change", () => {
        stopCamera();
        fromCamera = false;
        const selected = file.files?.[0];
        if (!selected) {
            revokePreview();
            updateFileSummary(null);
            return;
        }
        const err = validateSelectedFile(selected);
        if (err) {
            file.value = "";
            revokePreview();
            updateFileSummary(null);
            showMessage(err, "error");
            return;
        }
        updateFileSummary(selected);
        showImagePreview(selected, false);
        showMessage("");
    });

    open.addEventListener("click", () => startCamera(lastDeviceId));
    list.addEventListener("change", () => {
        lastDeviceId = list.value;
        startCamera(list.value);
    });
    if (navigator.mediaDevices?.addEventListener) {
        navigator.mediaDevices.addEventListener("devicechange", () => {
            if (stream) {
                refreshCameraList(lastDeviceId);
            }
        });
    }
    if (closeBtn) {
        closeBtn.addEventListener("click", () => {
            stopCamera();
            showMessage("");
        });
    }

    function canvasToJpegBlob(canvas, quality) {
        return new Promise(resolve => canvas.toBlob(resolve, "image/jpeg", quality));
    }

    if (captureBtn) {
        captureBtn.addEventListener("click", async () => {
            if (!view.videoWidth || !view.videoHeight) {
                showMessage("Wait for the live camera preview and try capturing again.", "warn");
                return;
            }
            captureBtn.disabled = true;
            const canvas = document.createElement("canvas");
            canvas.width = view.videoWidth;
            canvas.height = view.videoHeight;
            canvas.getContext("2d").drawImage(view, 0, 0);
            let blob = await canvasToJpegBlob(canvas, 0.93);
            if (blob && blob.size > MAX_UPLOAD_BYTES) {
                blob = await canvasToJpegBlob(canvas, 0.82);
            }
            if (!blob) {
                captureBtn.disabled = false;
                showMessage("Try again. The camera could not capture the page.", "error");
                return;
            }
            if (blob.size > MAX_UPLOAD_BYTES) {
                captureBtn.disabled = false;
                showMessage("The captured image exceeds the 8 MB upload limit. Lower the camera resolution or upload a compressed file.", "error");
                return;
            }
            const capturedFile = new File([blob], "camera-report.jpg", { type: "image/jpeg" });
            const transfer = new DataTransfer();
            transfer.items.add(capturedFile);
            file.files = transfer.files;
            fromCamera = true;
            stopCamera();
            updateFileSummary(capturedFile);
            showImagePreview(capturedFile, true, canvas.width + "×" + canvas.height + " px");
            showMessage("Page captured. Review the preview below, select Retake if blurry, or submit.");
            submit.focus();
        });
    }

    if (retakeBtn) {
        retakeBtn.addEventListener("click", () => {
            if (submitting) {
                return;
            }
            file.value = "";
            fromCamera = false;
            revokePreview();
            updateFileSummary(null);
            startCamera(lastDeviceId);
        });
    }

    if (clearCaptureBtn) {
        clearCaptureBtn.addEventListener("click", () => {
            if (submitting) {
                return;
            }
            file.value = "";
            fromCamera = false;
            revokePreview();
            updateFileSummary(null);
            showMessage("");
        });
    }

    upload.addEventListener("submit", event => {
        if (submitting) {
            event.preventDefault();
            return;
        }
        const selected = file.files?.[0];
        const err = validateSelectedFile(selected);
        if (err) {
            event.preventDefault();
            showMessage(err, "error");
            return;
        }
        submitting = true;
        stopCamera();
        submit.disabled = true;
        submit.textContent = "Reading report…";
        if (open) open.disabled = true;
        if (uploadTrigger) uploadTrigger.disabled = true;
        if (retakeBtn) retakeBtn.disabled = true;
        if (clearCaptureBtn) clearCaptureBtn.disabled = true;
        showMessage("Reading report… Please wait while the file is processed.");
    });

    window.addEventListener("pageshow", () => {
        submitting = false;
        submit.disabled = false;
        submit.textContent = defaultSubmitText;
        if (open) open.disabled = false;
        if (uploadTrigger) uploadTrigger.disabled = false;
        if (retakeBtn) retakeBtn.disabled = false;
        if (clearCaptureBtn) clearCaptureBtn.disabled = false;
    });

    // Always release hardware camera tracks and revoke blob preview URLs when leaving the page.
    window.addEventListener("pagehide", () => {
        stopCamera();
        revokePreview();
    });
    window.addEventListener("beforeunload", stopCamera);
}

const printButton = document.querySelector("[data-print]");
if (printButton) printButton.addEventListener("click", () => window.print());
