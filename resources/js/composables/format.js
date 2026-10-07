/**
 * A price in US dollars, which is what Google bills in. Small amounts keep a
 * third decimal so $0.034 and $0.067 do not both round to a few cents.
 */
export function usd(amount) {
    if (amount === null || amount === undefined) {
        return null;
    }

    return '$' + amount.toFixed(amount < 0.1 ? 3 : 2);
}

/**
 * Text made safe to put in a toast. Statamic shows toast messages as HTML, so
 * anything someone else could have typed, such as a filename, is escaped.
 */
export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
}

/**
 * An asset's filename from its id: "assets::photos/cat.jpg" -> "cat.jpg".
 */
export function assetName(id) {
    const path = String(id ?? '');
    const at = path.indexOf('::');

    return (at === -1 ? path : path.slice(at + 2)).split('/').pop();
}

/**
 * The price of one image plus the images sent with its prompt, which Google
 * bills as well: reference images, or the picture being upscaled or revised.
 * An unpriced size stays unpriced rather than looking free.
 */
export function withInputImages(model, price, count = 1) {
    return price === null || price === undefined ? null : price + count * (model?.inputImagePrice ?? 0);
}

// Sizes from smallest to largest, as the API names them, with how each
// relates to 1K. The API calls the smallest "512"; it is shown as 0.5K.
export const SIZES = { 512: 0.5, '1K': 1, '2K': 2, '4K': 4 };

const ORDER = ['512', '1K', '2K', '4K'];

export function qualityLabel(quality) {
    return String(quality) === '512' ? '0.5K' : quality;
}

/**
 * Where a quality sits in the order, or null when it is not one we know.
 */
export function qualityRank(quality) {
    const rank = ORDER.indexOf(String(quality));

    return rank === -1 ? null : rank;
}

/**
 * A total, always to the cent.
 */
export function usdTotal(amount) {
    return '$' + Number(amount ?? 0).toFixed(2);
}

export function shortDate(timestamp, withTime = false) {
    if (!timestamp) {
        return '';
    }

    const options = withTime
        ? { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }
        : { day: 'numeric', month: 'short', year: 'numeric' };

    return new Date(timestamp * 1000).toLocaleString(undefined, options);
}

/**
 * Lowercase, accents dropped, and every run of anything other than a letter
 * or digit turned into a single hyphen. While someone is still typing, a
 * trailing hyphen is kept so the next word can follow it; pass trim to tidy
 * the ends once they have finished.
 */
export function slugify(value, { trim = false } = {}) {
    let slug = String(value ?? '')
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/['’]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+/, '');

    if (trim) {
        slug = slug.replace(/-+$/, '');
    }

    return slug.slice(0, 120);
}

/**
 * "2752 × 1536" for a quality at an aspect ratio, or null when unknown. A size
 * measured for that quality wins; otherwise it is scaled from 1K, which is
 * exact for 2K and 4K.
 */
export function pixelSize(dimensions, aspectRatio, quality) {
    const exact = dimensions?.[quality]?.[aspectRatio];

    if (exact) {
        return `${exact[0]} × ${exact[1]}`;
    }

    const base = dimensions?.['1K']?.[aspectRatio];
    const scale = SIZES[quality];

    return base && scale ? `${Math.floor(base[0] * scale)} × ${Math.floor(base[1] * scale)}` : null;
}

export function fileSize(bytes) {
    if (!bytes) {
        return null;
    }

    return bytes >= 1024 * 1024 ? (bytes / 1024 / 1024).toFixed(1) + ' MB' : Math.round(bytes / 1024) + ' KB';
}

/**
 * Width and height in CSS pixels for a small outline of an aspect ratio,
 * fitted inside a square of the given size.
 */
export function ratioBox(ratio, size = 16) {
    const [w, h] = String(ratio).split(':').map(Number);

    if (!w || !h) {
        return { width: size + 'px', height: size + 'px' };
    }

    const scale = size / Math.max(w, h);

    return {
        width: Math.max(5, Math.round(w * scale)) + 'px',
        height: Math.max(5, Math.round(h * scale)) + 'px',
    };
}

