"use strict";

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
            resultRow.querySelectorAll("input").forEach(field => { field.value = ""; });
        } else {
            resultRow.remove();
        }
        addResult.disabled = false;
    });
}

const upload = document.querySelector("[data-upload-form]");
if (upload) {
    const panel = upload.querySelector("[data-camera-panel]");
    const list = upload.querySelector("[data-camera-list]");
    const view = upload.querySelector("[data-camera-view]");
    const status = upload.querySelector("[data-camera-message]");
    const open = upload.querySelector("[data-camera-open]");
    const submit = upload.querySelector("button[type=submit]");
    const file = upload.querySelector("input[type=file]");
    let stream;

    function showMessage(message, kind = "") {
        status.textContent = message;
        status.className = "notice " + kind;
        status.hidden = !message;
    }

    function stopCamera() {
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = undefined;
        }
        view.srcObject = null;
        panel.hidden = true;
    }

    async function startCamera(deviceId) {
        if (!navigator.mediaDevices?.getUserMedia) {
            showMessage("Camera access requires localhost or HTTPS. You can also upload a picture.", "error");
            return;
        }
        open.disabled = true;
        stopCamera();
        try {
            const video = deviceId
                ? { deviceId: { exact: deviceId }, width: { ideal: 1920 }, height: { ideal: 1080 } }
                : { facingMode: { ideal: "environment" }, width: { ideal: 1920 }, height: { ideal: 1080 } };
            stream = await navigator.mediaDevices.getUserMedia({ video, audio: false });
            view.srcObject = stream;
            const activeDevice = stream.getVideoTracks()[0].getSettings().deviceId;
            const devices = (await navigator.mediaDevices.enumerateDevices()).filter(device => device.kind === "videoinput");
            list.replaceChildren(...devices.map((device, index) => new Option(device.label || "Camera " + (index + 1), device.deviceId)));
            list.value = activeDevice || list.options[0]?.value;
            panel.hidden = false;
            showMessage("");
        } catch {
            stopCamera();
            showMessage("Camera access failed. Check browser permissions or upload a picture.", "error");
        } finally {
            open.disabled = false;
        }
    }

    open.addEventListener("click", () => startCamera());
    list.addEventListener("change", () => startCamera(list.value));
    upload.querySelector("[data-camera-close]").addEventListener("click", stopCamera);
    upload.querySelector("[data-capture]").addEventListener("click", () => {
        if (!view.videoWidth) {
            showMessage("Wait for the camera preview and try capturing again.");
            return;
        }
        const canvas = document.createElement("canvas");
        canvas.width = view.videoWidth;
        canvas.height = view.videoHeight;
        canvas.getContext("2d").drawImage(view, 0, 0);
        canvas.toBlob(blob => {
            if (!blob) {
                showMessage("The camera could not capture the page.", "error");
                return;
            }
            const transfer = new DataTransfer();
            transfer.items.add(new File([blob], "camera-report.jpg", { type: "image/jpeg" }));
            file.files = transfer.files;
            stopCamera();
            showMessage("Page captured. You can submit it now.");
            submit.focus();
        }, "image/jpeg", .93);
    });
    upload.addEventListener("submit", () => {
        stopCamera();
        submit.disabled = true;
        submit.textContent = "Reading report…";
    });
    addEventListener("pagehide", stopCamera);
}

const printButton = document.querySelector("[data-print]");
if (printButton) printButton.addEventListener("click", () => window.print());
