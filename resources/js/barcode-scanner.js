/**
 * Barcode kamera (plan §20 "Kamera", FR-03).
 *
 * Decode berjalan penuh di sisi klien — tanpa internet, tanpa unggah gambar
 * (offline-first untuk build native). @zxing/library di-import dinamis agar
 * chunk berat hanya dimuat saat tombol scan pertama kali ditekan.
 */

let reader = null;
let videoEl = null;
let finished = false;

/**
 * Buka kamera dan mulai decode barcode.
 *
 * @param {object} options
 * @param {HTMLVideoElement} options.video  Elemen <video> untuk preview.
 * @param {(code: string) => void} [options.onResult]  Dipanggil sekali saat barcode berhasil dibaca.
 * @param {(error: string) => void} [options.onError]  Dipanggil saat kamera tidak tersedia/ditolak.
 */
export async function openBarcodeScanner({ video, onResult, onError }) {
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        onError?.('camera-unavailable');
        return;
    }

    const { BrowserMultiFormatReader } = await import('@zxing/library');

    videoEl = video;
    reader = new BrowserMultiFormatReader();
    finished = false;

    try {
        // deviceId null → ZXing memakai facingMode: 'environment' (fallback aman
        // di desktop/kamera depan saja). Callback terpanggil tiap frame; kita
        // hanya memproses hasil pertama yang valid.
        await reader.decodeFromVideoDevice(null, video, (result) => {
            if (finished || !result?.getText?.()) {
                return;
            }

            const text = result.getText().trim();
            if (!text) {
                return;
            }

            finished = true;
            closeBarcodeScanner();
            onResult?.(text);
        });
    } catch (error) {
        finished = true;
        closeBarcodeScanner();
        onError?.(error?.name ?? 'camera-unavailable');
    }
}

/**
 * Hentikan kamera dan bersihkan resources. Aman dipanggil berulang.
 */
export function closeBarcodeScanner() {
    finished = true;
    reader?.reset?.();
    reader = null;
    if (videoEl) {
        videoEl.srcObject = null;
        videoEl = null;
    }
}
