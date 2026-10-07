<script setup>
import { ref } from 'vue';
import { Button, Field, Icon } from '@statamic/cms/ui';

/**
 * The reference images for the next generation: a numbered strip in the order
 * they are sent, with ways to add more. The numbers are what a prompt refers
 * to them by, so removing one renumbers the rest.
 */
const props = defineProps({
    // From useReferences: { key, name, status, reference }.
    list: { type: Array, required: true },
    max: { type: Number, required: true },
    // Whether there is a container to choose from.
    canChoose: { type: Boolean, default: true },
});

const emit = defineEmits(['add', 'choose', 'remove']);

const input = ref(null);

function picked(event) {
    emit('add', event.target.files);

    // So choosing the same file again still counts as a change.
    event.target.value = '';
}
</script>

<template>
    <Field
        :label="__('Reference images')"
        :instructions="__('Optional. Images for the model to work from. Say how to use them in your prompt, by number if there are several: “in the style of image 1, with the product from image 2”.')"
        instructions-below
    >
        <div class="dr-references">
            <ol v-if="list.length" class="dr-references-strip">
                <li v-for="(entry, index) in list" :key="entry.key" class="dr-reference" :title="entry.name">
                    <img v-if="entry.status === 'ready'" :src="entry.reference.url" :alt="__('Reference image :position: :name', { position: index + 1, name: entry.name })" />
                    <span v-else class="dr-reference-pending" role="status" :aria-label="__('Adding :name', { name: entry.name })">
                        <Icon name="loading" />
                    </span>
                    <span class="dr-reference-number" aria-hidden="true">{{ index + 1 }}</span>
                    <button
                        type="button"
                        class="dr-reference-remove"
                        :aria-label="__('Remove reference image :n', { n: index + 1 })"
                        @click="emit('remove', entry.key)"
                    >
                        <Icon name="x" />
                    </button>
                </li>
            </ol>

            <div class="dr-references-actions">
                <Button size="sm" icon="upload" :disabled="list.length >= max" @click="input.click()">{{ __('Upload images') }}</Button>
                <Button v-if="canChoose" size="sm" icon="assets" :disabled="list.length >= max" @click="emit('choose')">
                    {{ __('Choose from assets') }}
                </Button>
                <span v-if="list.length" class="dr-references-count">{{ __(':n of :max', { n: list.length, max }) }}</span>
                <input ref="input" type="file" accept="image/*" multiple hidden @change="picked" />
            </div>
        </div>
    </Field>
</template>

<style scoped>
.dr-references {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
}

.dr-references-strip {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.dr-reference {
    position: relative;
    width: 4.5rem;
    height: 4.5rem;
    border-radius: 0.5rem;
    overflow: hidden;
    background: color-mix(in oklab, currentColor 6%, transparent);
    border: 1px solid color-mix(in oklab, currentColor 12%, transparent);
}

.dr-reference img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.dr-reference-pending {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    opacity: 0.6;
}

.dr-reference-number {
    position: absolute;
    top: 0.25rem;
    left: 0.25rem;
    min-width: 1.25rem;
    height: 1.25rem;
    padding: 0 0.3rem;
    border-radius: 999px;
    background: rgb(0 0 0 / 0.7);
    color: #fff;
    font-size: 0.6875rem;
    font-weight: 600;
    line-height: 1.25rem;
    text-align: center;
    font-variant-numeric: tabular-nums;
}

.dr-reference-remove {
    position: absolute;
    top: 0.25rem;
    right: 0.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.25rem;
    height: 1.25rem;
    padding: 0.2rem;
    border: 0;
    border-radius: 999px;
    background: rgb(0 0 0 / 0.7);
    color: #fff;
    cursor: pointer;
}

.dr-reference-remove:focus-visible {
    outline: 2px solid #fff;
    outline-offset: 1px;
}

.dr-references-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

.dr-references-count {
    font-size: 0.75rem;
    opacity: 0.7;
    font-variant-numeric: tabular-nums;
}
</style>
