<script setup>
import { computed } from 'vue';
import { Field, Select } from '@statamic/cms/ui';
import AspectRatioMenu from './AspectRatioMenu.vue';
import BatchStepper from './BatchStepper.vue';
import { pixelSize } from '../composables/format.js';

const props = defineProps({
    models: { type: Array, required: true },
    fileTypes: { type: Array, required: true },
    batchMax: { type: Number, default: 4 },
    disabled: { type: Boolean, default: false },
});

const model = defineModel('model', { type: String });
const aspectRatio = defineModel('aspectRatio', { type: String });
const quality = defineModel('quality', { type: String });
const batchSize = defineModel('batchSize', { type: Number });
const fileType = defineModel('fileType', { type: String });

const current = computed(() => props.models.find((candidate) => candidate.id === model.value) ?? props.models[0]);

const modelOptions = computed(() => props.models.map((candidate) => ({ value: candidate.id, label: candidate.label })));

// Each quality shows the pixel size it will produce at the chosen aspect
// ratio, where the model's sizes are known.
const qualityOptions = computed(() =>
    (current.value?.qualities ?? []).map(({ value, label }) => {
        const size = pixelSize(current.value.dimensions, aspectRatio.value, value);

        return { value, label: size ? `${label} · ${size}` : label };
    }),
);
</script>

<template>
    <div class="dr-controls">
        <!-- Model gets a row of its own with the batch stepper, so its description has room to read. -->
        <div class="dr-controls__row">
            <Field :label="__('Model')" :instructions="current?.description ?? ''" instructions-below class="dr-control dr-control--model">
                <Select v-model="model" :options="modelOptions" :disabled="disabled" />
            </Field>

            <Field :label="__('Batch size')" class="dr-control dr-control--fit">
                <BatchStepper v-model="batchSize" :max="batchMax" :disabled="disabled" />
            </Field>
        </div>

        <div class="dr-controls__row">
            <Field :label="__('Aspect ratio')" class="dr-control">
                <AspectRatioMenu v-model="aspectRatio" :ratios="current?.aspectRatios ?? []" :disabled="disabled" />
            </Field>

            <Field :label="__('Quality')" class="dr-control dr-control--wide">
                <Select v-model="quality" :options="qualityOptions" :disabled="disabled" />
            </Field>

            <Field :label="__('File type')" class="dr-control">
                <Select v-model="fileType" :options="fileTypes" :disabled="disabled" />
            </Field>
        </div>
    </div>
</template>

<style scoped>
.dr-controls {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    margin-top: 1.25rem;
}

.dr-controls__row {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 1rem;
}

.dr-control {
    flex: 1 1 9rem;
    min-width: 0;
}

.dr-control--model {
    flex-basis: 16rem;
}

.dr-control--wide {
    flex-basis: 13rem;
}

.dr-control--fit {
    flex: 0 0 auto;
}
</style>
