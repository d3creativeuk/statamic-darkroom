<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Badge, Button, Card, ConfirmationModal, Heading, Panel, PanelHeader } from '@statamic/cms/ui';
import ResultCard from './ResultCard.vue';
import { http } from '../composables/useHttp.js';

const props = defineProps({
    batch: { type: Object, required: true },
    fileTypes: { type: Array, required: true },
    busy: { type: Boolean, default: false },
    canUpscale: { type: Boolean, default: false },
});

const emit = defineEmits(['save', 'save-all', 'retry', 'discard', 'discard-batch', 'upscale', 'spent']);

// What has been typed into each card. Kept here rather than in the card so it
// survives the batch being replaced by a fresh copy on every status check.
const drafts = reactive({});

watch(
    () => props.batch.items.map((item) => item.index),
    () => {
        props.batch.items.forEach((item) => {
            drafts[item.index] ??= { filename: item.filename, alt: '', file_type: props.batch.fileType };
        });
    },
    { immediate: true },
);

function draft(item) {
    return drafts[item.index];
}

// Which images are waiting on alt text, so each card can show its own spinner.
const writing = reactive({});

async function writeAlt(item) {
    writing[item.index] = true;

    try {
        draft(item).alt = (await http('POST', item.urls.alt)).alt;

        // A few hundredths of a cent, but spend all the same.
        emit('spent');
    } catch (e) {
        Statamic.$toast.error(e.message);
    } finally {
        writing[item.index] = false;
    }
}

// A saved image has moved on to History, and a discarded one is gone. What is
// left here is work still to be dealt with.
const visible = computed(() => props.batch.items.filter((item) => !['discarded', 'saved'].includes(item.status)));
const unsaved = computed(() => props.batch.items.filter((item) => item.status === 'complete'));
const active = computed(() => props.batch.items.some((item) => ['pending', 'generating', 'saving'].includes(item.status)));

const confirmingDiscard = ref(false);

// One folder choice for every unsaved image in the batch.
function saveAll() {
    emit('save-all', unsaved.value.map((item) => [item, draft(item)]));
}
</script>

<template>
    <Panel v-if="visible.length" class="dr-batch">
        <PanelHeader class="dr-batch-header">
            <div class="dr-batch-title">
                <Heading class="dr-batch-prompt" :title="batch.prompt">{{ batch.prompt }}</Heading>
                <div class="dr-batch-tags">
                    <Badge size="sm">{{ batch.modelLabel }}</Badge>
                    <Badge size="sm">{{ batch.aspectRatio === 'auto' ? __('Auto') : batch.aspectRatio }}</Badge>
                    <Badge size="sm">{{ batch.qualityLabel }}</Badge>
                    <Badge v-if="batch.upscaledFrom" size="sm" color="blue">
                        {{ batch.upscaledFrom === true ? __('Upscaled') : __('Upscaled from :size', { size: batch.upscaledFrom }) }}
                    </Badge>
                    <Badge v-if="batch.instructionTitle" size="sm" icon="ai-sparks">{{ batch.instructionTitle }}</Badge>
                </div>
            </div>

            <div class="dr-batch-actions">
                <Button v-if="unsaved.length > 1" size="sm" :disabled="busy" @click="saveAll">
                    {{ __('Save all :n', { n: unsaved.length }) }}
                </Button>
                <Button size="sm" variant="ghost" :disabled="busy || active" @click="confirmingDiscard = true">
                    {{ __('Clear') }}
                </Button>
            </div>
        </PanelHeader>

        <Card>
            <div class="dr-batch-grid" :class="{ 'dr-batch-grid--single': visible.length === 1 }">
                <ResultCard
                    v-for="item in visible"
                    :key="item.index"
                    v-model:filename="draft(item).filename"
                    v-model:alt="draft(item).alt"
                    v-model:file-type="draft(item).file_type"
                    :item="item"
                    :batch="batch"
                    :file-types="fileTypes"
                    :busy="busy"
                    :can-upscale="canUpscale"
                    :writing-alt="!!writing[item.index]"
                    @alt="writeAlt(item)"
                    @upscale="emit('upscale', item)"
                    @save="emit('save', item, draft(item))"
                    @retry="emit('retry', item)"
                    @discard="emit('discard', item)"
                />
            </div>
        </Card>

        <ConfirmationModal
            v-model:open="confirmingDiscard"
            :title="__('Clear these images')"
            :body-text="
                unsaved.length
                    ? unsaved.length === 1
                        ? __('This discards 1 unsaved image. Images already saved to assets are kept.')
                        : __('This discards :n unsaved images. Images already saved to assets are kept.', { n: unsaved.length })
                    : __('Remove these from the page?')
            "
            :button-text="__('Clear')"
            :danger="unsaved.length > 0"
            @confirm="emit('discard-batch', batch)"
        />
    </Panel>
</template>

<style scoped>
.dr-batch-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
}

.dr-batch-title {
    flex: 1 1 20rem;
    min-width: 0;
}

.dr-batch-prompt {
    display: block;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.dr-batch-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
    margin-top: 0.5rem;
}

.dr-batch-actions {
    display: flex;
    gap: 0.5rem;
    flex: none;
}

.dr-batch-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 17rem), 1fr));
    gap: 1rem;
    align-items: start;
}

/* One image on its own is shown larger, but not stretched across the page. */
.dr-batch-grid--single {
    grid-template-columns: minmax(0, 34rem);
}
</style>
