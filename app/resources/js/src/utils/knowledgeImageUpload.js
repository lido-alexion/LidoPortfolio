import api from '../api';
import { appUrl } from '../appBase';

export const KNOWLEDGE_IMAGE_MAX_DISPLAY_EDGE = 1200;
export const KNOWLEDGE_IMAGE_MAX_FULL_EDGE = 4000;
export const KNOWLEDGE_IMAGE_ACCEPT = 'image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif';

/**
 * Load a File/Blob into an HTMLImageElement.
 * @param {Blob} blob
 * @returns {Promise<HTMLImageElement>}
 */
function loadImageElement(blob) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(blob);
        const img = new Image();
        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve(img);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Could not read image.'));
        };
        img.src = url;
    });
}

/**
 * @param {HTMLImageElement} img
 * @param {number} maxEdge
 * @param {string} mime
 * @param {number} quality
 * @returns {Promise<{ blob: Blob, width: number, height: number }>}
 */
async function canvasEncode(img, maxEdge, mime, quality) {
    const srcW = img.naturalWidth || img.width;
    const srcH = img.naturalHeight || img.height;
    const scale = Math.min(1, maxEdge / Math.max(srcW, srcH, 1));
    const width = Math.max(1, Math.round(srcW * scale));
    const height = Math.max(1, Math.round(srcH * scale));

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        throw new Error('Canvas not available.');
    }
    ctx.drawImage(img, 0, 0, width, height);

    const blob = await new Promise((resolve, reject) => {
        canvas.toBlob(
            (result) => {
                if (!result) {
                    reject(new Error('Failed to encode image.'));
                    return;
                }
                resolve(result);
            },
            mime,
            quality,
        );
    });

    return { blob, width, height };
}

/**
 * Prepare display (resized) + full blobs for upload.
 * GIFs are stored as-is for both variants so animation is preserved.
 *
 * @param {File} file
 * @returns {Promise<{
 *   displayBlob: Blob,
 *   fullBlob: Blob,
 *   displayWidth: number,
 *   displayHeight: number,
 *   fullWidth: number,
 *   fullHeight: number,
 *   originalName: string,
 *   displayMime: string,
 *   fullMime: string
 * }>}
 */
export async function prepareKnowledgeImage(file) {
    if (!file || !file.type?.startsWith('image/')) {
        throw new Error('Please choose an image file (JPEG, PNG, WebP, or GIF).');
    }

    const originalName = file.name || 'image';
    const isGif = file.type === 'image/gif';

    if (isGif) {
        const img = await loadImageElement(file);
        const width = img.naturalWidth || img.width;
        const height = img.naturalHeight || img.height;
        return {
            displayBlob: file,
            fullBlob: file,
            displayWidth: width,
            displayHeight: height,
            fullWidth: width,
            fullHeight: height,
            originalName,
            displayMime: 'image/gif',
            fullMime: 'image/gif',
        };
    }

    const img = await loadImageElement(file);
    const fullWidth = img.naturalWidth || img.width;
    const fullHeight = img.naturalHeight || img.height;
    const encodeMime = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
    const quality = encodeMime === 'image/jpeg' ? 0.88 : 0.92;

    const full = await canvasEncode(img, KNOWLEDGE_IMAGE_MAX_FULL_EDGE, encodeMime, quality);
    const display = await canvasEncode(img, KNOWLEDGE_IMAGE_MAX_DISPLAY_EDGE, encodeMime, 0.82);

    return {
        displayBlob: display.blob,
        fullBlob: full.blob,
        displayWidth: display.width,
        displayHeight: display.height,
        fullWidth: full.width,
        fullHeight: full.height,
        originalName,
        displayMime: encodeMime,
        fullMime: encodeMime,
    };
}

/**
 * Upload prepared image blobs to the knowledge-board API.
 *
 * @param {File} file
 * @returns {Promise<{ display_url: string, full_url: string, uuid: string, original_name?: string }>}
 */
export async function uploadKnowledgeImage(file) {
    const prepared = await prepareKnowledgeImage(file);
    const form = new FormData();
    const displayName = prepared.originalName.replace(/\.[^.]+$/, '') || 'image';
    const displayExt = prepared.displayMime === 'image/png' ? 'png'
        : prepared.displayMime === 'image/gif' ? 'gif'
            : prepared.displayMime === 'image/webp' ? 'webp'
                : 'jpg';
    const fullExt = prepared.fullMime === 'image/png' ? 'png'
        : prepared.fullMime === 'image/gif' ? 'gif'
            : prepared.fullMime === 'image/webp' ? 'webp'
                : 'jpg';

    form.append('display', prepared.displayBlob, `${displayName}_display.${displayExt}`);
    form.append('full', prepared.fullBlob, `${displayName}_full.${fullExt}`);
    form.append('original_name', prepared.originalName);
    form.append('display_width', String(prepared.displayWidth));
    form.append('display_height', String(prepared.displayHeight));
    form.append('full_width', String(prepared.fullWidth));
    form.append('full_height', String(prepared.fullHeight));

    const res = await api.post('/knowledge-board/images', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });

    const data = res.data?.data;
    if (!data?.display_url || !data?.full_url) {
        throw new Error('Image upload returned an unexpected response.');
    }

    return {
        ...data,
        display_url: appUrl(data.display_url),
        full_url: appUrl(data.full_url),
    };
}

/**
 * Ensure img tags carry data-full-src (from title="…" when coming from Markdown).
 * @param {string} [html]
 */
export function enhanceImageHtml(html) {
    if (!html || !/<img\b/i.test(html)) {
        return html || '';
    }
    const doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll('img').forEach((img) => {
        if (!img.getAttribute('data-full-src')) {
            const title = (img.getAttribute('title') || '').trim();
            if (title.startsWith('full:')) {
                img.setAttribute('data-full-src', title.slice(5));
                img.removeAttribute('title');
            } else if (title) {
                img.setAttribute('data-full-src', title);
                img.removeAttribute('title');
            } else {
                img.setAttribute('data-full-src', img.getAttribute('src') || '');
            }
        }
        img.classList.add('lido-knowledge-image');
        if (!img.getAttribute('alt')) {
            img.setAttribute('alt', 'Embedded image');
        }
    });
    return doc.body.innerHTML;
}
