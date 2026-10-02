<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Button, Description, Field, Heading, Select, Stack, Textarea } from '@statamic/cms/ui';
import { pixelSize, usd } from '../composables/format.js';

/**
 * Pin numbered notes to spots on an image, add a note for the whole image if
 * needed, and send it back to be changed. Positions are kept as a share of
 * the image's width and height, so they mean the same at any size.
 */
const props = defineProps({
    models: { type: Array, required: true },
    // The image being revised: { image, name, model, quality, aspectRatio }.
    source: { type: Object, default: null },
    // The model to fall back on when the one that made the image is gone.
    preferred: { type: String, default: null },
    // Most notes one revision can carry, as the server allows.
    max: { type: Number, default: 10 },
});

const emit = defineEmits(['confirm']);
const open = defineModel('open', { type: Boolean, default: false });

const notes = ref([]);
const general = ref('');
const model = ref(null);
const quality = ref(null);
const active = ref(null);
const noteFields = ref({});

let nextId = 1;

// Each time the editor opens on an image, start clean on the model and size
// that made it.
watch(
    () => [open.value, props.source],
    () => {
        if (!open.value || !props.source) {
            return;
        }

        notes.value = [];
        general.value = '';
        active.value = null;

        const ids = props.models.map((candidate) => candidate.id);
        model.value = [props.source.model, props.preferred].find((id) => ids.includes(id)) ?? ids[0] ?? null;
        quality.value = null;
        chooseQuality();
    },
);

const current = computed(() => props.models.find((candidate) => candidate.id === model.value) ?? null);
const modelOptions = computed(() => props.models.map((candidate) => ({ value: candidate.id, label: candidate.label })));

const qualityOptions = computed(() =>
    (current.value?.qualities ?? []).map((option) => ({
        value: option.value,
        label: [option.label, pixelSize(current.value.dimensions, props.source?.aspectRatio, option.value), usd(option.price)]
            .filter(Boolean)
            .join(' · '),
    })),
);

// The size it already is where the model offers it, otherwise 2K.
function chooseQuality() {
    const values = qualityOptions.value.map((option) => option.value);

    if (!values.includes(quality.value)) {
        quality.value = [props.source?.quality, '2K'].find((value) => values.includes(value)) ?? values[0] ?? null;
    }
}

watch(model, chooseQuality);

const price = computed(() => current.value?.qualities.find((option) => option.value === quality.value)?.price ?? null);

const written = computed(() => notes.value.filter((note) => note.text.trim()));
const ready = computed(() => model.value && quality.value && (written.value.length > 0 || general.value.trim() !== ''));

async function pin(event) {
    if (notes.value.length >= props.max) {
        Statamic.$toast.info(__('A revision can carry :n notes at most.', { n: props.max }));

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

function confirm() {
    if (!ready.value) {
        return;
    }

    emit('confirm', {
        model: model.value,
        quality: quality.value,
        notes: written.value.map(({ x, y, text }) => ({ x, y, text: text.trim() })),
        general: general.value.trim() || null,
    });
}
</script>

<template>
    <Stack v-model:open="open" inset :show-close-button="false">
        <div v-if="open && source" class="dr-revise">
            <div class="dr-revise-header">
                <Heading :text="__('Revise :name', { name: source.name })" />
                <Description>{{ __('Click the image to pin a numbered note to that spot, then say what to change there.') }}</Description>
            </div>

            <div class="dr-revise-body">
                <!-- Pins sit on the image itself, placed by share of its width and height. -->
                <div class="dr-revise-canvas">
                    <div class="dr-revise-frame" @click="pin">
                        <img :src="source.image" :alt="__('The image to revise')" draggable="false" />

                        <button
                            v-for="(note, index) in notes"
                            :key="note.id"
                            type="button"
                            class="dr-revise-pin"
                            :class="{ 'is-active': active === note.id }"
                            :style="{ left: `${note.x * 100}%`, top: `${note.y * 100}%` }"
                            :aria-label="__('Note :n', { n: index + 1 })"
                            @click.stop="focus(note)"
                        >
                            {{ index + 1 }}
                        </button>
                    </div>
                </div>

                <div class="dr-revise-notes">
                    <Description v-if="!notes.length">
                        {{ __('No notes pinned yet.') }}
                    </Description>

                    <div
                        v-for="(note, index) in notes"
                        :key="note.id"
                        class="dr-revise-note"
                        :class="{ 'is-active': active === note.id }"
                        @focusin="active = note.id"
                    >
                        <span class="dr-revise-number" aria-hidden="true">{{ index + 1 }}</span>
                        <Textarea
                            :ref="(el) => (noteFields[note.id] = el)"
                            v-model="note.text"
                            :rows="2"
                            elastic
                            :aria-label="__('What to change at note :n', { n: index + 1 })"
                            :placeholder="__('What to change here, e.g. remove this door')"
                            maxlength="500"
                        />
                        <Button size="sm" variant="ghost" icon="x" :aria-label="__('Remove note :n', { n: index + 1 })" @click="remove(note)" />
                    </div>

                    <Field :label="__('For the whole image')" :instructions="__('Optional. Changes that apply everywhere, such as warmer light.')" class="dr-revise-general">
                        <Textarea v-model="general" :rows="2" elastic maxlength="2000" />
                    </Field>

                    <div class="dr-revise-settings">
                        <Field :label="__('Model')" class="dr-revise-setting">
                            <Select v-model="model" :options="modelOptions" />
                        </Field>
                        <Field :label="__('Quality')" class="dr-revise-setting">
                            <Select v-model="quality" :options="qualityOptions" />
                        </Field>
                    </div>

                    <Description>
                        {{ __('The result appears as a new image to preview and save. This one is left as it is. Nano Banana Pro is best at leaving everything else unchanged.') }}
                    </Description>
                </div>
            </div>

            <div class="dr-revise-footer flex items-center justify-end gap-3 border-t bg-gray-100 dark:bg-gray-850 dark:border-gray-700 px-4 py-2 sm:p-4">
                <Button variant="ghost" @click="open = false">{{ __('Cancel') }}</Button>
                <Button variant="primary" :disabled="!ready" @click="confirm">
                    {{ price === null ? __('Revise') : __('Revise for about :price', { price: usd(price) }) }}
                </Button>
            </div>
        </div>
    </Stack>
</template>

<style scoped>
.dr-revise {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
}

.dr-revise-header {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 1rem 1rem 0;
}

.dr-revise-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 1.25rem;
    padding: 1rem;
}

.dr-revise-canvas {
    flex: 3 1 28rem;
    min-width: 0;
}

/* Sized by the image, so a pin's percentage lands on the picture, not on
   empty space beside it. */
.dr-revise-frame {
    position: relative;
    display: inline-block;
    max-width: 100%;
    cursor: crosshair;
    line-height: 0;
    user-select: none;
}

.dr-revise-frame img {
    display: block;
    max-width: 100%;
    max-height: 70vh;
    border-radius: 0.5rem;
}

.dr-revise-pin {
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

.dr-revise-pin.is-active,
.dr-revise-pin:focus-visible {
    outline: 3px solid color-mix(in oklab, #ff2d55 45%, transparent);
    outline-offset: 2px;
}

.dr-revise-notes {
    flex: 2 1 18rem;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.dr-revise-note {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 0.375rem;
    border-radius: 0.5rem;
}

.dr-revise-note.is-active {
    background: color-mix(in oklab, #ff2d55 8%, transparent);
}

.dr-revise-note > :nth-child(2) {
    flex: 1 1 auto;
    min-width: 0;
}

.dr-revise-number {
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

.dr-revise-general {
    margin-top: 0.25rem;
}

.dr-revise-settings {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.dr-revise-setting {
    flex: 1 1 10rem;
    min-width: 0;
}
</style>
