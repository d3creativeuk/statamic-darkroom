import { computed, reactive, watch } from 'vue';
import { http } from './useHttp.js';

/**
 * The Revise panel: the image being worked on, the thread of rounds it
 * belongs to, and sending the next round.
 *
 * Rounds still in temporary storage are batches in useGeneration's list like
 * any other, so their status and actions stay live. The server's feed adds
 * what it remembers beyond that: rounds copied into later ones, and the trail
 * stamped on a saved image.
 */
export function useRevisions({ batches, put, revise, urls }) {
    const state = reactive({
        open: false,
        threadId: null,
        prompt: null,
        origin: null,
        // Rounds from the feed, as the server last described them.
        known: [],
        // What the next round starts from: { key, name, image, model, quality, aspectRatio, target }.
        base: null,
        // A round just sent, so the panel can move on to it when it is done.
        waitingFor: null,
        loading: false,
    });

    const assetPreview = (id) => `${urls.assetPreview}?asset=${encodeURIComponent(id)}`;

    const live = computed(() => (state.threadId ? batches.value.filter((batch) => batch.thread?.id === state.threadId) : []));

    function fromBatch(batch) {
        return {
            id: batch.id,
            parent: batch.thread?.parent ?? null,
            at: batch.createdAt,
            notes: batch.revision?.notes ?? [],
            general: batch.revision?.general ?? null,
            model: batch.model,
            modelLabel: batch.modelLabel,
            quality: batch.quality,
            qualityLabel: batch.qualityLabel,
            price: null,
            memory: batch.items[0]?.memory ?? null,
            asset: null,
        };
    }

    // A round's image: its preview while unsaved, the asset once saved.
    function describe(round) {
        const item = round.batch?.items[0] ?? null;
        const assetId = item?.asset?.id ?? round.asset?.id ?? null;
        const status = item?.status ?? (assetId ? 'saved' : 'gone');

        let image = null;
        let target = null;

        if (item?.urls?.preview) {
            image = item.urls.preview;
            target = status === 'complete' ? { batch: round.id, index: 1 } : null;
        } else if (assetId && (round.asset?.preview || item?.asset)) {
            image = round.asset?.preview ?? assetPreview(assetId);
            target = { asset: assetId };
        }

        return { item, status, image, target, memory: item?.memory ?? round.memory ?? null };
    }

    const rounds = computed(() => {
        const byId = new Map(state.known.map((round) => [round.id, { ...round }]));

        live.value.forEach((batch) => byId.set(batch.id, { ...(byId.get(batch.id) ?? fromBatch(batch)), batch }));

        const list = [...byId.values()].sort((a, b) => a.at - b.at || a.id.localeCompare(b.id));
        const numbers = new Map(list.map((round, index) => [round.id, index + 1]));

        return list.map((round, index) => ({
            ...round,
            ...describe(round),
            number: index + 1,
            parentNumber: round.parent ? (numbers.get(round.parent) ?? null) : 0,
            // Not simply the next step from the round above it.
            branched: index > 0 && round.parent !== list[index - 1].id,
        }));
    });

    // Anything in this thread still on its way.
    const busy = computed(() => rounds.value.some((round) => ['pending', 'generating', 'saving'].includes(round.status)));

    function threadUrl(threadId, asset) {
        const url = urls.threads.replace('__thread__', threadId);

        return asset ? `${url}?asset=${encodeURIComponent(asset)}` : url;
    }

    /**
     * Open the panel on an image. A round or a saved round brings its thread
     * with it; anything else starts a new one when the first round is sent.
     */
    async function open({ base, threadId = null, asset = null, prompt = null }) {
        Object.assign(state, {
            open: true,
            threadId,
            prompt,
            origin: threadId ? null : { ...base, key: 'origin' },
            known: [],
            base: threadId ? base : { ...base, key: 'origin' },
            waitingFor: null,
        });

        if (!threadId) {
            return;
        }

        state.loading = true;

        try {
            const feed = await http('GET', threadUrl(threadId, asset));

            state.known = feed.rounds;
            state.prompt = feed.prompt ?? prompt;
            state.origin = feed.origin?.image || feed.origin?.asset
                ? {
                      key: 'origin',
                      name: __('Original'),
                      image: feed.origin.image,
                      target: feed.origin.asset ? { asset: feed.origin.asset.id } : feed.origin.batch && feed.origin.image ? { batch: feed.origin.batch, index: feed.origin.index } : null,
                      model: feed.origin.model ?? base.model,
                      quality: feed.origin.quality ?? base.quality,
                      aspectRatio: feed.origin.aspectRatio ?? base.aspectRatio,
                  }
                : null;

            // Rounds still in temporary storage join the page's list, so they
            // are polled and can be saved or discarded like any other.
            feed.rounds.filter((round) => round.batch).forEach((round) => put(round.batch));

            // Opened on a round still on its way: work on it once it arrives.
            if (!state.base.target && state.base.key?.startsWith('round:')) {
                state.waitingFor = { round: state.base.key.slice('round:'.length), from: state.base.key };
            }

            // Opened from a saved image: that image is the last round of its line.
            if (asset && !state.base.key?.startsWith('round:')) {
                const own = feed.rounds.findLast((round) => round.asset?.id === asset);

                if (own) {
                    state.base = { ...state.base, key: `round:${own.id}` };
                }
            }
        } catch (e) {
            Statamic.$toast.error(e.message);
        } finally {
            state.loading = false;
        }
    }

    function choose(round) {
        if (round === 'origin') {
            if (state.origin?.target) {
                state.base = { ...state.origin };
            }

            return;
        }

        if (!round.target) {
            return;
        }

        state.base = {
            key: `round:${round.id}`,
            name: __('Round :n', { n: round.number }),
            image: round.image,
            model: round.model,
            quality: round.quality,
            aspectRatio: round.batch?.aspectRatio ?? state.base?.aspectRatio,
            target: round.target,
        };
    }

    async function send(changes) {
        const batch = await revise({ ...state.base.target, ...changes });

        state.threadId ??= batch.thread?.id ?? null;
        state.waitingFor = { round: batch.id, from: state.base.key };

        return batch;
    }

    // The round being worked on can change under the panel: saving it turns
    // its temporary image into an asset. Follow it, so the next round starts
    // from what is really there.
    watch(rounds, (list) => {
        const current = list.find((round) => `round:${round.id}` === state.base?.key);

        if (current?.target && JSON.stringify(current.target) !== JSON.stringify(state.base.target)) {
            choose(current);
        }
    });

    // When the round just sent finishes, carry on from it, unless the user
    // has since chosen something else to work on.
    watch(rounds, (list) => {
        const waiting = state.waitingFor;
        const round = waiting && list.find((candidate) => candidate.id === waiting.round);

        if (!round) {
            return;
        }

        if (round.status === 'complete' && round.target) {
            if (state.base?.key === waiting.from) {
                choose(round);
            }

            state.waitingFor = null;
        } else if (['failed', 'discarded'].includes(round.status)) {
            state.waitingFor = null;
        }
    });

    return { state, rounds, busy, open, choose, send };
}
