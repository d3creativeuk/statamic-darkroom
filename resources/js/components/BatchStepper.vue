<script setup>
import { Button } from '@statamic/cms/ui';

const props = defineProps({
    modelValue: { type: Number, default: 1 },
    max: { type: Number, default: 4 },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

function step(by) {
    emit('update:modelValue', Math.min(props.max, Math.max(1, props.modelValue + by)));
}
</script>

<template>
    <div class="dr-stepper" role="group" :aria-label="__('Batch size')">
        <!-- Statamic's icon set has a plus but no minus, so both are plain
             characters to keep the pair matching. -->
        <Button
            size="sm"
            variant="ghost"
            :aria-label="__('Fewer images')"
            :disabled="disabled || modelValue <= 1"
            @click="step(-1)"
        >
            <span class="dr-stepper-sign" aria-hidden="true">&minus;</span>
        </Button>
        <span class="dr-stepper-value" aria-live="polite">
            <strong>{{ modelValue }}</strong><span class="dr-stepper-max">/{{ max }}</span>
        </span>
        <Button
            size="sm"
            variant="ghost"
            :aria-label="__('More images')"
            :disabled="disabled || modelValue >= max"
            @click="step(1)"
        >
            <span class="dr-stepper-sign" aria-hidden="true">+</span>
        </Button>
    </div>
</template>

<style scoped>
.dr-stepper {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    min-height: 2.5rem;
}

.dr-stepper-value {
    min-width: 2.5rem;
    text-align: center;
    font-variant-numeric: tabular-nums;
}

.dr-stepper-max {
    opacity: 0.5;
}

.dr-stepper-sign {
    display: inline-block;
    width: 1ch;
    font-size: 1.125rem;
    line-height: 1;
    text-align: center;
}
</style>
