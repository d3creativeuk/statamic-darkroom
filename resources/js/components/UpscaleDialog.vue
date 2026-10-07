<script setup>
import { computed, ref, watch } from 'vue';
import { Button, Description, Field, Modal, ModalClose, Select } from '@statamic/cms/ui';
import { pixelSize, qualityLabel, qualityRank, usd, withInputImages } from '../composables/format.js';

const props = defineProps({
    models: { type: Array, required: true },
    // The image being enlarged: its current quality and the model that made it.
    source: { type: Object, default: null },
    // Pro redraws most faithfully, so it is the one to suggest.
    preferred: { type: String, default: null },
});

const emit = defineEmits(['confirm']);
const open = defineModel('open', { type: Boolean, default: false });

const model = ref(null);
const quality = ref(null);

// Only sizes larger than the image already is. An image of unknown size can
// go to any of them.
const larger = (candidate) => {
    const from = qualityRank(props.source?.quality);

    return candidate.qualities.filter((option) => from === null || qualityRank(option.value) > from);
};

const eligible = computed(() => props.models.filter((candidate) => larger(candidate).length));
const current = computed(() => eligible.value.find((candidate) => candidate.id === model.value) ?? null);

const modelOptions = computed(() => eligible.value.map((candidate) => ({ value: candidate.id, label: candidate.label })));

const qualityOptions = computed(() =>
    (current.value ? larger(current.value) : []).map((option) => ({
        value: option.value,
        // The same size shown elsewhere in the page, then the price. The size
        // is left out for Auto, where the model takes its shape from the image.
        // The price includes the image being sent, which Google bills too.
        label: [option.label, pixelSize(current.value.dimensions, props.source?.aspectRatio, option.value), usd(withInputImages(current.value, option.price))]
            .filter(Boolean)
            .join(' · '),
    })),
);

const price = computed(() => withInputImages(current.value, current.value?.qualities.find((option) => option.value === quality.value)?.price ?? null));

function chooseQuality() {
    const values = qualityOptions.value.map((option) => option.value);

    if (!values.includes(quality.value)) {
        quality.value = values.includes('2K') ? '2K' : (values[0] ?? null);
    }
}

// Each time the dialog opens for an image, start from sensible choices.
watch(
    () => [open.value, props.source],
    () => {
        if (!open.value) {
            return;
        }

        const ids = eligible.value.map((candidate) => candidate.id);

        model.value =
            [props.preferred, props.source?.model].find((id) => ids.includes(id)) ?? ids[0] ?? null;
        quality.value = null;

        chooseQuality();
    },
);

watch(model, chooseQuality);

function confirm() {
    if (model.value && quality.value) {
        emit('confirm', { model: model.value, quality: quality.value });
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="__('Upscale image')">
        <Description>
            {{
                source?.quality
                    ? __('This image is :size. The model redraws it at the larger size you choose.', { size: qualityLabel(source.quality) })
                    : __('The model redraws this image at the larger size you choose.')
            }}
            {{ __('The composition stays the same, but fine detail such as small lettering can change. Nano Banana Pro is the most faithful.') }}
        </Description>

        <div class="dr-upscale-fields">
            <Field :label="__('Model')" class="dr-upscale-field">
                <Select v-model="model" :options="modelOptions" />
            </Field>

            <Field :label="__('Quality')" class="dr-upscale-field">
                <Select v-model="quality" :options="qualityOptions" />
            </Field>
        </div>

        <Description>
            {{ __('The result appears as a new image to preview and save. The original is left as it is.') }}
        </Description>

        <template #footer>
            <div class="dr-upscale-footer">
                <ModalClose>
                    <Button variant="ghost">{{ __('Cancel') }}</Button>
                </ModalClose>
                <Button variant="primary" :disabled="!model || !quality" @click="confirm">
                    {{ price === null ? __('Upscale') : __('Upscale for about :price', { price: usd(price) }) }}
                </Button>
            </div>
        </template>
    </Modal>
</template>

<style scoped>
.dr-upscale-fields {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin: 1rem 0;
}

.dr-upscale-field {
    flex: 1 1 12rem;
    min-width: 0;
}

.dr-upscale-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 0.75rem 0.5rem 0.25rem;
}
</style>
