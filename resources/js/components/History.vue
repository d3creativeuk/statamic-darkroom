<script setup>
import { Badge, Button, Description } from '@statamic/cms/ui';
import { shortDate } from '../composables/format.js';

defineProps({
    // Given a quality, says whether any model can go larger.
    canUpscale: { type: Function, default: () => false },
    items: { type: Array, required: true },
    total: { type: Number, default: 0 },
    hasMore: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['reuse', 'more', 'upscale', 'open']);
</script>

<template>
    <div>
        <Description v-if="!items.length">
            {{ __('Images you save to assets are listed here, along with the prompt and settings that made them.') }}
        </Description>

        <div v-else class="dr-history-grid">
            <article v-for="item in items" :key="item.id" class="dr-history-card">
                <!-- Opens core's asset editor over this page rather than leaving it. -->
                <button type="button" class="dr-history-thumb" :aria-label="__('Open :path', { path: item.path })" @click="emit('open', item)">
                    <img :src="item.thumbnail" :alt="item.alt || item.prompt" loading="lazy" />
                </button>

                <div class="dr-history-body">
                    <p class="dr-history-prompt" :title="item.prompt">{{ item.prompt }}</p>

                    <div class="dr-history-tags">
                        <Badge v-if="item.modelLabel" size="sm">{{ item.modelLabel }}</Badge>
                        <Badge v-if="item.aspectRatio" size="sm">
                            {{ item.aspectRatio === 'auto' ? __('Auto') : item.aspectRatio }}
                        </Badge>
                        <Badge v-if="item.qualityLabel" size="sm">{{ item.qualityLabel }}</Badge>
                        <Badge v-if="item.upscaledFrom" size="sm" color="blue">
                            {{ __('Upscaled from :size', { size: item.upscaledFrom }) }}
                        </Badge>
                        <Badge v-if="item.instructionTitle" size="sm" icon="ai-sparks">{{ item.instructionTitle }}</Badge>
                    </div>

                    <p class="dr-history-meta">
                        {{ item.path }}
                        <template v-if="item.width && item.height"> · {{ item.width }} × {{ item.height }}</template>
                        <template v-if="item.generatedAt"> · {{ shortDate(item.generatedAt) }}</template>
                    </p>

                    <div class="dr-history-actions">
                        <Button size="sm" :disabled="disabled" @click="emit('reuse', item)">
                            {{ __('Reuse prompt') }}
                        </Button>
                        <Button v-if="canUpscale(item.quality)" size="sm" :disabled="disabled" @click="emit('upscale', item)">
                            {{ __('Upscale') }}
                        </Button>
                        <Button size="sm" variant="ghost" @click="emit('open', item)">
                            {{ __('Open asset') }}
                        </Button>
                    </div>
                </div>
            </article>
        </div>

        <div v-if="hasMore" class="dr-history-more">
            <Button :loading="loading" @click="emit('more')">
                {{ __('Show more (:shown of :total)', { shown: items.length, total }) }}
            </Button>
        </div>
    </div>
</template>

<style scoped>
.dr-history-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 16rem), 1fr));
    gap: 1rem;
    align-items: start;
}

.dr-history-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    border: 1px solid color-mix(in oklab, currentColor 14%, transparent);
    border-radius: 0.75rem;
    overflow: hidden;
}

/* One shape for every thumbnail keeps the grid even, whatever the image's
   own aspect ratio. The whole image is shown inside it, never cropped. */
.dr-history-thumb {
    width: 100%;
    border: 0;
    padding: 0;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    aspect-ratio: 4 / 3;
    background: color-mix(in oklab, currentColor 6%, transparent);
}

.dr-history-thumb img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.dr-history-body {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding: 0.75rem;
}

.dr-history-prompt {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    overflow: hidden;
    font-size: 0.875rem;
    line-height: 1.4;
}

.dr-history-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
}

.dr-history-meta {
    font-size: 0.75rem;
    opacity: 0.65;
    overflow-wrap: anywhere;
    font-variant-numeric: tabular-nums;
}

.dr-history-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.25rem;
}

.dr-history-more {
    display: flex;
    justify-content: center;
    margin-top: 1.25rem;
}
</style>
