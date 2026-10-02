<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Button, Description, Field, Heading, Select, Stack, Textarea } from '@statamic/cms/ui';
import RevisionRound from './RevisionRound.vue';
import { pixelSize, usd } from '../composables/format.js';

/**
 * The Revise panel: the image being worked on, with notes pinned to it, beside
 * a feed of every round of revising it. The panel stays open between rounds.
 * Positions are kept as a share of the image's width and height, so they mean
 * the same at any size.
 */
const props = defineProps({
    models: { type: Array, required: true },
    // What the next round starts from: { key, name, image, model, quality, aspectRatio, target }.
    base: { type: Object, default: null },
    // The image the thread started from, when it is still around.
    origin: { type: Object, default: null },
    prompt: { type: String, default: null },
    rounds: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    // Something is being generated, here or elsewhere on the page.
    busy: { type: Boolean, default: false },
    // The model to fall back on when the one that made the image is gone.
    preferred: { type: String, default: null },
    // Most notes one round can carry, as the server allows.
    max: { type: Number, default: 10 },
});

const emit = defineEmits(['send', 'choose', 'save', 'discard', 'retry']);
const open = defineModel('open', { type: Boolean, default: false });

const notes = ref([]);
const general = ref('');
const model = ref(null);
const quality = ref(null);
const active = ref(null);
const noteFields = ref({});
const feed = ref(null);

let nextId = 1;

// A new image to work on starts a clean round, on the model and size that
// made it.
watch(
    () => [open.value, props.base?.key],
    () => {
        if (!open.value || !props.base) {
            return;
        }

        notes.value = [];
        general.value = '';
        active.value = null;

        const ids = props.models.map((candidate) => candidate.id);
        model.value = [props.base.model, props.preferred].find((id) => ids.includes(id)) ?? ids[0] ?? null;
        quality.value = null;
        chooseQuality();
    },
    { immediate: true },
);

// Keep the newest round in view as rounds arrive.
watch(
    () => props.rounds.length,
    async () => {
        await nextTick();
        feed.value?.scrollTo({ top: feed.value.scrollHeight, behavior: 'smooth' });
    },
);

const current = computed(() => props.models.find((candidate) => candidate.id === model.value) ?? null);
const modelOptions = computed(() => props.models.map((candidate) => ({ value: candidate.id, label: candidate.label })));

const qualityOptions = computed(() =>
    (current.value?.qualities ?? []).map((option) => ({
        value: option.value,
        label: [option.label, pixelSize(current.value.dimensions, props.base?.aspectRatio, option.value), usd(option.price)]
            .filter(Boolean)
            .join(' · '),
    })),
);

// The size it already is where the model offers it, otherwise 2K.
function chooseQuality() {
    const values = qualityOptions.value.map((option) => option.value);

    if (!values.includes(quality.value)) {
        quality.value = [props.base?.quality, '2K'].find((value) => values.includes(value)) ?? values[0] ?? null;
    }
}

watch(model, chooseQuality);

const priceOf = (modelId, size) => props.models.find((candidate) => candidate.id === modelId)?.qualities.find((option) => option.value === size)?.price ?? null;
const price = computed(() => priceOf(model.value, quality.value));

const written = computed(() => notes.value.filter((note) => note.text.trim()));
const ready = computed(() => props.base?.target && model.value && quality.value && (written.value.length > 0 || general.value.trim() !== ''));

const workingOn = computed(() => {
    if (props.base?.key === 'origin') {
        return __('Revising the original');
    }

    const round = props.rounds.find((candidate) => `round:${candidate.id}` === props.base?.key);

    return round ? __('Revising round :n', { n: round.number }) : props.base?.name;
});

async function pin(event) {
    if (notes.value.length >= props.max) {
        Statamic.$toast.info(__('A round can carry :n notes at most.', { n: props.max }));

        return;
    }

    const box = event.currentTarget.getBoundingClientRect();
    const round = (value) => Math.round(Math.min(1, Math.max(0, value)) * 10000) / 10000;
    const note = { id: nextId++, x: round((event.clientX - box.left) / box.width), y: round((event.clientY - box.top) / box.height), text: '' };

    notes.value.push(note);
    await focus(note);
}

async function focus(note) {
    active.value = note.id;

    await nextTick();
    noteFields.value[note.id]?.$el?.querySelector('textarea')?.focus();
}

function remove(note) {
    notes.value = notes.value.filter((candidate) => candidate.id !== note.id);
}

function send() {
    if (!ready.value || props.busy) {
        return;
    }

    emit('send', {
        model: model.value,
        quality: quality.value,
        notes: written.value.map(({ x, y, text }) => ({ x, y, text: text.trim() })),
        general: general.value.trim() || null,
    });

    notes.value = [];
    general.value = '';
}
</script>

<template>
    <Stack v-model:open="open" inset :show-close-button="false">
        <div v-if="open && base" class="dr-thread">
            <div class="dr-thread-header">
                <div class="dr-thread-title">
                    <Heading :text="__('Revise')" />
                    <Description v-if="prompt" class="dr-thread-prompt" :title="prompt">{{ prompt }}</Description>
                </div>
                <Button variant="ghost" icon="x" :aria-label="__('Close')" @click="open = false" />
            </div>

            <div class="dr-thread-body">
                <!-- The image the next round starts from, with its pins. -->
                <div class="dr-thread-canvas">
                    <p class="dr-thread-working">{{ workingOn }}</p>

                    <div v-if="!base.image" class="dr-thread-waiting" role="status">
                        {{ __('This round is still being generated. It appears here when it is ready.') }}
                    </div>

                    <div v-else class="dr-thread-frame" @click="pin">
                        <img :src="base.image" :alt="__('The image to revise')" draggable="false" />

                        <button
                            v-for="(note, index) in notes"
                            :key="note.id"
                            type="button"
                            class="dr-thread-pin"
                            :class="{ 'is-active': active === note.id }"
                            :style="{ left: `${note.x * 100}%`, top: `${note.y * 100}%` }"
                            :aria-label="__('Note :n', { n: index + 1 })"
                            @click.stop="focus(note)"
                        >
                            {{ index + 1 }}
                        </button>
                    </div>

                    <Description>{{ __('Click the image to pin a numbered note to that spot, then say what to change there.') }}</Description>
                </div>

                <div class="dr-thread-side">
                    <ol ref="feed" class="dr-thread-feed" :aria-busy="loading">
                        <li v-if="origin" class="dr-thread-origin" :class="{ 'is-current': base.key === 'origin' }">
                            <button
                                v-if="origin.image"
                                type="button"
                                class="dr-thread-origin-image"
                                :disabled="!origin.target || busy || base.key === 'origin'"
                                :aria-label="__('Revise the original')"
                                @click="emit('choose', 'origin')"
                            >
                                <img :src="origin.image" alt="" />
                            </button>
                            <span>{{ __('Original') }}</span>
                        </li>

                        <RevisionRound
                            v-for="round in rounds"
                            :key="round.id"
                            :round="round"
                            :current="base.key === `round:${round.id}`"
                            :price="priceOf(round.model, round.quality)"
                            :busy="busy"
                            @choose="emit('choose', round)"
                            @save="emit('save', round)"
                            @discard="emit('discard', round)"
                            @retry="emit('retry', round)"
                        />

                        <li v-if="!rounds.length && !loading" class="dr-thread-empty">
                            <Description>{{ __('Each round you send appears here, with the image that came back. Revise from any of them.') }}</Description>
                        </li>
                    </ol>

                    <div class="dr-thread-composer">
                        <div
                            v-for="(note, index) in notes"
                            :key="note.id"
                            class="dr-thread-note"
                            :class="{ 'is-active': active === note.id }"
                            @focusin="active = note.id"
                        >
                            <span class="dr-thread-number" aria-hidden="true">{{ index + 1 }}</span>
                            <Textarea
                                :ref="(el) => (noteFields[note.id] = el)"
                                v-model="note.text"
                                :rows="1"
                                elastic
                                :aria-label="__('What to change at note :n', { n: index + 1 })"
                                :placeholder="__('What to change here, e.g. remove this door')"
                                maxlength="500"
                            />
                            <Button size="sm" variant="ghost" icon="x" :aria-label="__('Remove note :n', { n: index + 1 })" @click="remove(note)" />
                        </div>

                        <Field :label="__('Message')" :instructions="__('Optional. Changes for the whole image, or about earlier rounds, such as undo that.')">
                            <Textarea v-model="general" :rows="2" elastic maxlength="2000" />
                        </Field>

                        <div class="dr-thread-settings">
                            <Field :label="__('Model')" class="dr-thread-setting">
                                <Select v-model="model" :options="modelOptions" />
                            </Field>
                            <Field :label="__('Quality')" class="dr-thread-setting">
                                <Select v-model="quality" :options="qualityOptions" />
                            </Field>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dr-thread-footer flex items-center justify-between gap-3 border-t bg-gray-100 dark:bg-gray-850 dark:border-gray-700 px-4 py-2 sm:p-4">
                <span class="dr-thread-hint">{{ __('Each round comes back as a new image. Nothing you have saved is changed.') }}</span>
                <div class="flex items-center gap-3">
                    <Button variant="ghost" @click="open = false">{{ __('Close') }}</Button>
                    <Button variant="primary" :disabled="!ready || busy" @click="send">
                        {{ price === null ? __('Send') : __('Send for about :price', { price: usd(price) }) }}
                    </Button>
                </div>
            </div>
        </div>
    </Stack>
</template>

<style scoped>
.dr-thread {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
}

.dr-thread-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1rem 0;
}

.dr-thread-title {
    min-width: 0;
}

.dr-thread-prompt {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.dr-thread-body {
    flex: 1 1 auto;
    min-height: 0;
    display: flex;
    gap: 1.25rem;
    padding: 1rem;
}

.dr-thread-canvas {
    flex: 3 1 0;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    overflow: auto;
}

.dr-thread-working {
    font-size: 0.8125rem;
    font-weight: 600;
    color: #ff2d55;
}

/* Sized by the image, so a pin's percentage lands on the picture. */
.dr-thread-frame {
    position: relative;
    align-self: flex-start;
    max-width: 100%;
    cursor: crosshair;
    line-height: 0;
    user-select: none;
}

.dr-thread-frame img {
    display: block;
    max-width: 100%;
    max-height: 65vh;
    border-radius: 0.5rem;
}

.dr-thread-waiting {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 12rem;
    padding: 1rem;
    border-radius: 0.5rem;
    text-align: center;
    font-size: 0.875rem;
    background: color-mix(in oklab, currentColor 6%, transparent);
}

.dr-thread-pin {
    position: absolute;
    width: 1.75rem;
    height: 1.75rem;
    margin: -0.875rem 0 0 -0.875rem;
    border: 2px solid #fff;
    border-radius: 999px;
    background: #ff2d55;
    color: #fff;
    font-size: 0.8125rem;
    font-weight: 600;
    line-height: 1;
    cursor: pointer;
    box-shadow: 0 1px 4px rgb(0 0 0 / 0.35);
}

.dr-thread-pin.is-active,
.dr-thread-pin:focus-visible {
    outline: 3px solid color-mix(in oklab, #ff2d55 45%, transparent);
    outline-offset: 2px;
}

.dr-thread-side {
    flex: 2 1 0;
    min-width: 18rem;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.dr-thread-feed {
    flex: 1 1 auto;
    min-height: 8rem;
    overflow: auto;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    margin: 0;
    padding: 0 0.25rem 0 0;
    list-style: none;
}

.dr-thread-origin {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    font-size: 0.8125rem;
    font-weight: 600;
}

.dr-thread-origin-image {
    width: 4rem;
    padding: 0;
    border: 0;
    border-radius: 0.375rem;
    overflow: hidden;
    line-height: 0;
    cursor: pointer;
}

.dr-thread-origin-image:disabled {
    cursor: default;
}

.dr-thread-origin.is-current .dr-thread-origin-image {
    outline: 2px solid #ff2d55;
    outline-offset: 1px;
}

.dr-thread-origin-image img {
    width: 100%;
    height: auto;
}

.dr-thread-composer {
    flex: none;
    max-height: 50%;
    overflow: auto;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding-top: 0.75rem;
    border-top: 1px solid color-mix(in oklab, currentColor 12%, transparent);
}

.dr-thread-note {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 0.25rem;
    border-radius: 0.5rem;
}

.dr-thread-note.is-active {
    background: color-mix(in oklab, #ff2d55 8%, transparent);
}

.dr-thread-note > :nth-child(2) {
    flex: 1 1 auto;
    min-width: 0;
}

.dr-thread-number {
    flex: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    margin-top: 0.375rem;
    border-radius: 999px;
    background: #ff2d55;
    color: #fff;
    font-size: 0.75rem;
    font-weight: 600;
}

.dr-thread-settings {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.dr-thread-setting {
    flex: 1 1 9rem;
    min-width: 0;
}

.dr-thread-hint {
    font-size: 0.8125rem;
    opacity: 0.75;
}

/* Narrow, the image and the feed stack and the whole body scrolls. */
@media (max-width: 52rem) {
    .dr-thread-body {
        flex-direction: column;
        overflow: auto;
    }

    .dr-thread-canvas,
    .dr-thread-side {
        flex: none;
        min-width: 0;
        overflow: visible;
    }

    .dr-thread-feed {
        overflow: visible;
    }

    .dr-thread-composer {
        max-height: none;
    }

    .dr-thread-hint {
        display: none;
    }
}
</style>
