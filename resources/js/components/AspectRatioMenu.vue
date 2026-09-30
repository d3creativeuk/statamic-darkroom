<script setup>
import { computed } from 'vue';
import { Select } from '@statamic/cms/ui';
import { ratioBox } from '../composables/format.js';

const props = defineProps({
    modelValue: { type: String, default: null },
    ratios: { type: Array, required: true },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const options = computed(() =>
    props.ratios.map((ratio) => ({
        value: ratio,
        label: ratio === 'auto' ? __('Auto') : ratio,
    })),
);
</script>

<template>
    <Select
        :model-value="modelValue"
        :options="options"
        :disabled="disabled"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <template #option="option">
            <span class="dr-ratio">
                <span class="dr-ratio-frame">
                    <span
                        class="dr-ratio-shape"
                        :class="{ 'dr-ratio-shape--auto': option.value === 'auto' }"
                        :style="ratioBox(option.value)"
                    />
                </span>
                <span>{{ option.label }}</span>
            </span>
        </template>

        <template #selected-option="{ option }">
            <span v-if="option" class="dr-ratio">
                <span class="dr-ratio-frame">
                    <span
                        class="dr-ratio-shape"
                        :class="{ 'dr-ratio-shape--auto': option.value === 'auto' }"
                        :style="ratioBox(option.value)"
                    />
                </span>
                <span>{{ option.label }}</span>
            </span>
        </template>
    </Select>
</template>

<style scoped>
.dr-ratio {
    display: inline-flex;
    align-items: center;
    gap: 0.625rem;
}

/* A fixed square so every label lines up, whatever shape sits inside it. */
.dr-ratio-frame {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    flex: none;
}

.dr-ratio-shape {
    display: block;
    border: 1.5px solid currentColor;
    border-radius: 2px;
    opacity: 0.7;
}

.dr-ratio-shape--auto {
    border-style: dashed;
}
</style>
