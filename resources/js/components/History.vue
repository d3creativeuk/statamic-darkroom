<script setup>
import { computed, ref, watch } from 'vue';
import { Badge, Button, ButtonGroup, Checkbox, Description, Input, Pagination } from '@statamic/cms/ui';
import { shortDate } from '../composables/format.js';

const props = defineProps({
    // Given a quality, says whether any model can go larger.
    canUpscale: { type: Function, default: () => false },
    items: { type: Array, required: true },
    // Every saved image, search or not.
    all: { type: Number, default: 0 },
    // Page, page size and totals, shaped as core's Pagination expects.
    meta: { type: Object, required: true },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['reuse', 'upscale', 'open', 'page', 'per-page', 'trash']);

// Searches prompts, filenames, alt text and instruction titles. The page
// does the fetching.
const search = defineModel('search', { type: String, default: '' });

// Images ticked for moving to the trash, by asset id. Only what is on the
// page can be ticked, so a new page, a search or a refresh drops anything
// no longer shown.
const selected = ref([]);

watch(
    () => props.items,
    (items) => {
        const shown = items.map((item) => item.id);

        selected.value = selected.value.filter((id) => shown.includes(id));
    },
);

const isSelected = (item) => selected.value.includes(item.id);

function toggle(item, on) {
    selected.value = on ? [...new Set([...selected.value, item.id])] : selected.value.filter((id) => id !== item.id);
}

const allSelected = computed(() => props.items.length > 0 && selected.value.length === props.items.length);
// As core's listings do it: ticked when anything is, with a dash instead of
// a tick while only some are. Clicking it then clears the selection.
const someSelected = computed(() => selected.value.length > 0 && !allSelected.value);

function toggleAll(on) {
    selected.value = on ? props.items.map((item) => item.id) : [];
}

function trashSelected() {
    emit('trash', [...selected.value]);
}
</script>

<template>
    <div>
        <Description v-if="!all">
            {{ __('Images you save to assets are listed here, along with the prompt and settings that made them.') }}
        </Description>

        <template v-else>
            <div class="dr-history-toolbar">
                <Checkbox
                    v-if="items.length"
                    solo
                    :label="__('Select all on this page')"
                    :model-value="selected.length > 0"
                    :indeterminate="someSelected"
                    :disabled="disabled"
                    @update:model-value="toggleAll"
                />

                <div class="dr-history-search">
                    <Input
                        v-model="search"
                        icon-prepend="magnifying-glass"
                        clearable
                        :placeholder="__('Search by prompt, filename, alt text or instruction')"
                    />
                </div>

                <div v-if="selected.length" class="dr-history-selection">
                    <span class="dr-history-count">
                        {{ selected.length === 1 ? __('1 selected') : __(':n selected', { n: selected.length }) }}
                    </span>
                    <Button size="sm" icon="trash" :disabled="disabled" @click="trashSelected">
                        {{ __('Move to Trash') }}
                    </Button>
                    <Button size="sm" variant="ghost" @click="toggleAll(false)">{{ __('Clear') }}</Button>
                </div>
            </div>

            <Description v-if="!items.length && !loading">
                {{ __('No saved images match that search.') }}
            </Description>
        </template>

        <div v-if="items.length" class="dr-history-grid">
            <article v-for="item in items" :key="item.id" class="dr-history-card" :class="{ 'is-selected': isSelected(item) }">
                <!-- Over the thumbnail, as in core's asset grid. -->
                <div class="dr-history-check">
                    <Checkbox
                        solo
                        :label="__('Select :path', { path: item.path })"
                        :model-value="isSelected(item)"
                        :disabled="disabled"
                        @update:model-value="(on) => toggle(item, on)"
                    />
                </div>

                <!-- Opens core's asset editor over this page rather than leaving it. -->
                <button type="button" class="dr-history-thumb" :aria-label="__('Open :path', { path: item.path })" @click="emit('open', item)">
                    <img :src="item.thumbnail" :alt="item.alt || item.prompt" loading="lazy" />
                </button>

                <div class="dr-history-body">
                    <!-- The prompt is a click away under "Reuse prompt", so the
                         card keeps to when it was made and how. -->
                    <p class="dr-history-meta">
                        <template v-if="item.generatedAt">{{ shortDate(item.generatedAt) }}</template>
                        <template v-if="item.generatedAt && item.width && item.height"> · </template>
                        <template v-if="item.width && item.height">{{ item.width }} × {{ item.height }}</template>
                    </p>

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

                    <!-- Opening the asset is the thumbnail's job. -->
                    <ButtonGroup class="dr-history-actions">
                        <Button size="xs" :disabled="disabled" @click="emit('reuse', item)">
                            {{ __('Reuse prompt') }}
                        </Button>
                        <Button v-if="canUpscale(item.quality)" size="xs" :disabled="disabled" @click="emit('upscale', item)">
                            {{ __('Upscale') }}
                        </Button>
                    </ButtonGroup>
                </div>
            </article>
        </div>

        <!-- Core's own pagination, as under the Assets listing: the range,
             page buttons and the Per Page menu. -->
        <Pagination
            v-if="items.length"
            class="dr-history-pagination"
            :resource-meta="meta"
            :per-page="meta.per_page"
            :scroll-to-top="false"
            @page-selected="(page) => emit('page', page)"
            @per-page-changed="(size) => emit('per-page', size)"
        />
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
    position: relative;
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

.dr-history-card.is-selected {
    outline: 2px solid var(--theme-color-primary, currentColor);
    outline-offset: -1px;
}

.dr-history-check {
    position: absolute;
    top: 0.5rem;
    inset-inline-start: 0.5rem;
    z-index: 1;
    padding: 0.25rem;
    border-radius: 0.375rem;
    background: color-mix(in oklab, Canvas 85%, transparent);
}

.dr-history-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.dr-history-search {
    flex: 1 1 12rem;
    min-width: 0;
    max-width: 28rem;
}

.dr-history-selection {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.dr-history-count {
    font-size: 0.875rem;
    font-variant-numeric: tabular-nums;
}

.dr-history-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
}

.dr-history-meta {
    font-size: 0.75rem;
    opacity: 0.65;
    font-variant-numeric: tabular-nums;
}

/* Core's ButtonGroup lays out and joins the buttons; this only spaces it. */
.dr-history-actions {
    margin-top: 0.25rem;
}

.dr-history-pagination {
    margin-top: 1.25rem;
}
</style>
