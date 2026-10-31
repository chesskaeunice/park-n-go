'use strict';

(() => {
    const form = document.querySelector('[data-qr-upload-form]');
    const file = document.querySelector('[data-qr-upload-file]');
    const manual = document.querySelector('[data-qr-upload-manual]');
    const hidden = document.querySelector('[data-qr-upload-code]');
    const status = document.querySelector('[data-qr-upload-status]');
    if (!form || !file || !manual || !hidden || !status) return;

    const maxBytes = 5 * 1024 * 1024;
    const maxDimension = 4096;
    const setStatus = (message, error = false) => {
        status.textContent = message;
        status.classList.toggle('error-text', error);
    };

    file.addEventListener('change', () => {
        hidden.value = '';
        setStatus('The image is decoded locally and is not uploaded.');
        const imageFile = file.files?.[0];
        if (!imageFile) return;
        if (!['image/png', 'image/jpeg', 'image/webp'].includes(imageFile.type) || imageFile.size > maxBytes) {
            file.value = '';
            setStatus('Choose a PNG, JPG, or WebP image up to 5 MB.', true);
            return;
        }
        const reader = new FileReader();
        reader.onerror = () => setStatus('The QR image could not be read.', true);
        reader.onload = () => {
            const image = new Image();
            image.onerror = () => setStatus('The selected image is invalid or unreadable.', true);
            image.onload = () => {
                if (image.naturalWidth < 1 || image.naturalHeight < 1 || image.naturalWidth > maxDimension || image.naturalHeight > maxDimension) {
                    setStatus('The image dimensions must not exceed 4096 × 4096 pixels.', true);
                    return;
                }
                const scale = Math.min(1, 2048 / Math.max(image.naturalWidth, image.naturalHeight));
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.floor(image.naturalWidth * scale));
                canvas.height = Math.max(1, Math.floor(image.naturalHeight * scale));
                const context = canvas.getContext('2d', { willReadFrequently: true });
                if (!context || typeof window.jsQR !== 'function') {
                    setStatus('The local QR decoder is unavailable.', true);
                    return;
                }
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
                const decoded = window.jsQR(context.getImageData(0, 0, canvas.width, canvas.height).data, canvas.width, canvas.height, { inversionAttempts: 'attemptBoth' });
                if (!decoded || typeof decoded.data !== 'string' || decoded.data.trim() === '') {
                    setStatus('No readable QR ticket was found in this image.', true);
                    return;
                }
                hidden.value = decoded.data.trim();
                manual.value = '';
                setStatus('QR ticket decoded locally. Select Validate ticket to continue.');
            };
            image.src = String(reader.result);
        };
        reader.readAsDataURL(imageFile);
    });

    manual.addEventListener('input', () => {
        if (manual.value.trim() !== '') {
            hidden.value = '';
            setStatus('Manual code entered. Select Validate ticket to continue.');
        }
    });
    form.addEventListener('submit', event => {
        if (manual.value.trim() !== '') hidden.value = manual.value.trim();
        if (!hidden.value.trim()) {
            event.preventDefault();
            setStatus('Upload a QR image or enter a manual ticket code.', true);
        }
    });
})();
