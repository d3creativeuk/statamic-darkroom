import { computed, ref } from 'vue';
import { escapeHtml } from './format.js';
import { http } from './useHttp.js';

/**
 * The reference images for the next generation, in the order they are sent,
 * which is the order a prompt refers to them by ("image 1", "image 2").
 *
 * Uploads are reduced to a JPEG in the browser before they are sent, so a
 * phone photo is a few hundred kilobytes rather than many megabytes, well
 * inside what a typical server accepts. The server reduces them again either
 * way. Choosing from the library sends the asset's id instead.
 *
 * Each entry is { key, name, status: 'pending' | 'ready', reference }.
 */
export function useReferences({ url, limits }) {
    const list = ref([]);
    let nextKey = 1;
    // Moved on by clear(), so a reply for a list that has since been
    // emptied is dropped rather than added back.
    let round = 0;

    const max = computed(() => limits.references ?? 14);
    const room = computed(() => Math.max(0, max.value - list.value.length));
    const pending = computed(() => list.value.some((entry) => entry.status === 'pending'));
    const ids = computed(() => list.value.filter((entry) => entry.status === 'ready').map((entry) => entry.reference.id));

    function start(name) {
        list.value.push({ key: nextKey++, name, status: 'pending', reference: null });

        // The reactive copy, so later changes show.
        return list.value[list.value.length - 1];
    }

    function drop(entry) {
        list.value = list.value.filter((candidate) => candidate.key !== entry.key);
    }

    // As many as fit. The rest are left out, with a note saying so.
    function fitting(items) {
        const taking = items.slice(0, room.value);

        if (items.length > taking.length) {
            Statamic.$toast.info(
                room.value === 0
                    ? __('A generation can use :n reference images at most.', { n: max.value })
                    : __('Only :n more reference images fit, so the rest were left out.', { n: taking.length }),
            );
        }

        return taking;
    }

    // A JPEG no larger than the server keeps, drawn on white so transparency
    // comes out the same as the server would make it.
    async function shrink(file) {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const edge = limits.referenceEdge ?? 1536;
        const scale = Math.min(1, edge / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');

        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));

        const context = canvas.getContext('2d');
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close?.();

        return new Promise((resolve, reject) => canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error())), 'image/jpeg', 0.85));
    }

    // Resolves to null when it was added, or to what went wrong.
    async function send(entry, body, mine, quiet = false) {
        try {
            const reference = await http('POST', url, body);

            if (mine === round) {
                // The name as the server kept it, which is the one History will show.
                Object.assign(entry, { status: 'ready', reference, name: reference.name });
            }

            return null;
        } catch (e) {
            if (mine === round) {
                drop(entry);

                if (!quiet) {
                    // Toasts are HTML, and a filename is whatever its owner typed.
                    Statamic.$toast.error(`${escapeHtml(entry.name)}: ${escapeHtml(e.message)}`);
                }
            }

            return { name: entry.name, message: e.message };
        }
    }

    async function upload(file) {
        const mine = round;
        const entry = start(file.name);
        let blob;

        try {
            blob = await shrink(file);
        } catch (e) {
            drop(entry);
            Statamic.$toast.error(`${escapeHtml(file.name)}: ${__('Darkroom cannot read that image. Use a JPEG, PNG or WebP.')}`);

            return;
        }

        if (limits.referenceBytes && blob.size > limits.referenceBytes) {
            drop(entry);
            Statamic.$toast.error(`${escapeHtml(file.name)}: ${__('That image is too large for this server to accept.')}`);

            return;
        }

        const body = new FormData();
        body.append('image', blob, file.name);

        await send(entry, body, mine);
    }

    /**
     * Upload files from a file input or a drop, as many as fit.
     */
    async function add(files) {
        const images = [...files].filter((file) => file.type === '' || file.type.startsWith('image/'));

        if (images.length < files.length) {
            Statamic.$toast.error(__('Only images can be used as references.'));
        }

        await Promise.all(fitting(images).map(upload));
    }

    /**
     * Use images from the asset library, as many as fit. Resolves to the
     * ones that could not be used, as { name, message }. Quiet leaves saying
     * so to the caller, for bringing back the references of a saved prompt.
     * Any that did not fit have already been mentioned.
     *
     * @param {Array<{id: string, name: string}>} assets
     */
    async function attach(assets, { quiet = false } = {}) {
        const mine = round;
        const results = await Promise.all(fitting(assets).map(({ id, name }) => send(start(name), { asset: id }, mine, quiet)));

        // Cleared while these were on their way, because something else was
        // loaded: they no longer describe what is on screen.
        return mine === round ? results.filter(Boolean) : [];
    }

    function remove(key) {
        list.value = list.value.filter((entry) => entry.key !== key);
    }

    function clear() {
        round++;
        list.value = [];
    }

    /**
     * Take out any that have expired on the server (it keeps them for a day),
     * after generating was refused because of one. Resolves to how many went.
     */
    async function dropExpired() {
        const ready = list.value.filter((entry) => entry.status === 'ready');
        const checks = await Promise.all(
            ready.map(async (entry) => {
                try {
                    return (await fetch(entry.reference.url, { method: 'GET', credentials: 'same-origin' })).ok;
                } catch (e) {
                    return true;
                }
            }),
        );
        const gone = ready.filter((entry, index) => !checks[index]).map((entry) => entry.key);

        list.value = list.value.filter((entry) => !gone.includes(entry.key));

        return gone.length;
    }

    return { list, max, room, pending, ids, add, attach, remove, clear, dropExpired };
}
