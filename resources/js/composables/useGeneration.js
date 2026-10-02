import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { http } from './useHttp.js';

const WAITING_ON_GOOGLE = ['pending', 'generating'];
const IN_PROGRESS = [...WAITING_ON_GOOGLE, 'saving'];

/**
 * The batches on the page and everything that can be done to them.
 *
 * Generating and saving both finish on the server after the request has
 * already been answered, so the page finds out by asking again. Polling runs
 * only while at least one image is in progress.
 */
export function useGeneration({ initial, url, upscaleUrl, reviseUrl, interval, onChange }) {
    const batches = ref(initial ?? []);

    let timer = null;
    let polling = false;

    const inProgress = (batch) => batch.items.some((item) => IN_PROGRESS.includes(item.status));

    // The server allows one batch at a time per person. Mirroring that here
    // lets the button say so before a request is refused.
    const generating = computed(() =>
        batches.value.some((batch) => batch.items.some((item) => WAITING_ON_GOOGLE.includes(item.status))),
    );

    function put(batch) {
        const index = batches.value.findIndex((existing) => existing.id === batch.id);
        const previous = index === -1 ? null : batches.value[index];

        if (index === -1) {
            batches.value.unshift(batch);
        } else {
            batches.value.splice(index, 1, batch);
        }

        // Tell the page when an image moves on, so it can react to one being
        // generated (money was spent) or saved (it is in the library now).
        if (previous && onChange) {
            batch.items.forEach((item) => {
                const before = previous.items.find((candidate) => candidate.index === item.index);

                if (before && before.status !== item.status) {
                    onChange(item, before.status, batch);
                }
            });
        }

        schedule();
    }

    function drop(id) {
        batches.value = batches.value.filter((batch) => batch.id !== id);

        schedule();
    }

    function schedule() {
        const needed = batches.value.some(inProgress);

        if (needed && !timer) {
            timer = setInterval(poll, interval);
        }

        if (!needed && timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    async function poll() {
        // A slow answer must not stack up behind the next tick.
        if (polling) {
            return;
        }

        polling = true;

        try {
            await Promise.all(
                batches.value.filter(inProgress).map(async (batch) => {
                    try {
                        put(await http('GET', batch.urls.show));
                    } catch (e) {
                        // Pruned or discarded from another tab.
                        if (e.status === 404) {
                            drop(batch.id);
                        }
                    }
                }),
            );
        } finally {
            polling = false;
        }
    }

    async function generate(payload) {
        put(await http('POST', url, payload));
    }

    async function upscale(payload) {
        put(await http('POST', upscaleUrl, payload));
    }

    async function revise(payload) {
        const batch = await http('POST', reviseUrl, payload);

        put(batch);

        return batch;
    }

    async function save(item, draft) {
        put(await http('POST', item.urls.save, draft));
    }

    async function retry(item) {
        put(await http('POST', item.urls.retry));
    }

    async function discard(item) {
        put(await http('DELETE', item.urls.destroy));
    }

    async function discardBatch(batch) {
        await http('DELETE', batch.urls.destroy);

        drop(batch.id);
    }

    onMounted(schedule);
    onBeforeUnmount(() => clearInterval(timer));

    return { batches, generating, put, generate, upscale, revise, save, retry, discard, discardBatch };
}
