<script setup>
import { computed, ref, watch } from 'vue';
import { Badge, Button, Description, Heading, Modal, ModalClose, Stack } from '@statamic/cms/ui';
import { shortDate } from '../composables/format.js';

/**
 * How a saved revised image was made, read-only: the prompt, the original,
 * then for each round the notes, pinned on the image they were written on,
 * and the image that came back. The last round's image is the saved one.
 */
const props = defineProps({
    // The History card it was opened from.
    item: { type: Object, default: null },
    // The story as the server tells it, or null while it loads.
    story: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['edit', 'revise', 'forget']);
const open = defineModel('open', { type: Boolean, default: false });

const confirming = ref(false);

watch(open, (now) => {
    if (!now) {
        confirming.value = false;
    }
});

const name = computed(() => props.item?.path.split('/').pop() ?? '');

// The copies kept in a revisions folder: what deleting the history removes,
// unless something else still needs them.
const kept = computed(() => {
    if (!props.story) {
        return [];
    }

    const images = [props.story.original, ...props.story.steps.map((step) => step.result)];

    return [...new Set(images.filter((image) => image?.kind === 'kept').map((image) => image.path))];
});

const memory = (step) =>
    ({
        continued: __('Remembered earlier rounds'),
        lost: __('Started fresh: the earlier conversation had expired'),
    })[step.memory] ?? null;

const meta = (step) => [step.modelLabel, step.qualityLabel, memory(step)].filter(Boolean).join(' · ');

const isLast = (step) => step.number === props.story?.steps.length;

function forget() {
    confirming.value = false;
    emit('forget');
}
</script>

<template>
    <Stack v-model:open="open" size="full" inset :show-close-button="false">
        <div v-if="open && item" class="dr-story">
            <div class="dr-story-header">
                <Heading :text="__('How :name was made', { name })" />
                <Button variant="ghost" icon="x" :aria-label="__('Close')" @click="open = false" />
            </div>

            <div class="dr-story-body" :aria-busy="loading">
                <p v-if="loading && !story" class="dr-story-loading" role="status">{{ __('Loading…') }}</p>

                <ol v-else-if="story" class="dr-story-feed">
                    <li class="dr-story-entry">
                        <div class="dr-story-bubble dr-story-bubble--ask">
                            <p class="dr-story-label">{{ __('Prompt') }}</p>
                            <p class="dr-story-text">{{ story.prompt }}</p>
                            <Badge v-if="story.instructionTitle" class="dr-story-badge" size="sm" icon="ai-sparks">{{ story.instructionTitle }}</Badge>
                        </div>

                        <div class="dr-story-answer">
                            <p class="dr-story-label">{{ __('Original') }}</p>
                            <img v-if="story.original && !story.original.hidden" class="dr-story-image" :src="story.original.preview" :alt="__('The original image')" loading="lazy" />
                            <div v-else class="dr-story-missing">
                                {{ story.original?.hidden ? __('You do not have permission to see this image.') : __('This image was not kept.') }}
                            </div>
                        </div>
                    </li>

                    <li v-if="story.earlier" class="dr-story-entry">
                        <Description>{{ __('Earlier rounds are not shown. A saved image keeps its most recent rounds.') }}</Description>
                    </li>

                    <li v-for="step in story.steps" :key="step.round" class="dr-story-entry">
                        <div class="dr-story-bubble dr-story-bubble--ask">
                            <p class="dr-story-heading">
                                <strong>{{ __('Round :n', { n: step.number }) }}</strong>
                                <span v-if="step.at" class="dr-story-time">{{ shortDate(step.at, true) }}</span>
                            </p>

                            <div class="dr-story-request">
                                <!-- The notes where they were pinned, on the image they were written on. -->
                                <div v-if="step.notes.length && step.from && !step.from.hidden" class="dr-story-pinned">
                                    <img :src="step.from.preview" alt="" loading="lazy" />
                                    <span
                                        v-for="(note, index) in step.notes"
                                        :key="index"
                                        class="dr-story-pin"
                                        :style="{ left: `${note.x * 100}%`, top: `${note.y * 100}%` }"
                                        aria-hidden="true"
                                    >
                                        {{ index + 1 }}
                                    </span>
                                </div>

                                <div class="dr-story-words">
                                    <ol v-if="step.notes.length" class="dr-story-notes">
                                        <li v-for="(note, index) in step.notes" :key="index">
                                            <span class="dr-story-number" aria-hidden="true">{{ index + 1 }}</span>
                                            <span>{{ note.text }}</span>
                                        </li>
                                    </ol>
                                    <p v-if="step.general" class="dr-story-text">{{ step.general }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="dr-story-answer">
                            <img
                                v-if="step.result && !step.result.hidden"
                                class="dr-story-image"
                                :src="step.result.preview"
                                :alt="__('Round :n', { n: step.number })"
                                loading="lazy"
                            />
                            <div v-else class="dr-story-missing">
                                {{ step.result?.hidden ? __('You do not have permission to see this image.') : __('This image was not kept.') }}
                            </div>

                            <p v-if="meta(step)" class="dr-story-meta">{{ meta(step) }}</p>

                            <p v-if="isLast(step)" class="dr-story-saved">
                                <Badge size="sm" color="green">{{ __('Saved') }}</Badge>
                                <span>{{ story.saved.path }}</span>
                            </p>
                            <p v-else-if="step.result?.kind === 'saved'" class="dr-story-meta">
                                {{ __('Also saved as :path', { path: step.result.path }) }}
                            </p>
                            <p v-if="step.result?.trashed" class="dr-story-meta">{{ __('In Trash') }}</p>
                        </div>
                    </li>
                </ol>
            </div>

            <div class="dr-story-footer flex flex-wrap items-center justify-between gap-3 border-t bg-gray-100 dark:bg-gray-850 dark:border-gray-700 px-4 py-2 sm:p-4">
                <div>
                    <Button v-if="story?.canDeleteHistory" variant="danger" :disabled="busy" @click="confirming = true">
                        {{ __('Delete revision history') }}
                    </Button>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3">
                    <span v-if="story && !item.alt" class="dr-story-hint">{{ __('No alt text yet') }}</span>
                    <Button v-if="story?.canRevise" :disabled="busy" @click="emit('revise')">{{ __('Continue revising') }}</Button>
                    <Button variant="primary" @click="emit('edit')">{{ __('Open in asset editor') }}</Button>
                </div>
            </div>
        </div>
    </Stack>

    <Modal v-model:open="confirming" :title="__('Delete the revision history of :name?', { name })">
        <Description>
            {{ __('The notes from each round go, and so do the earlier images kept for it in a revisions folder. The image itself stays where it is, and in History.') }}
        </Description>

        <ul v-if="kept.length" class="dr-story-files">
            <li v-for="path in kept" :key="path">{{ path }}</li>
        </ul>

        <Description class="dr-story-small">
            {{ __('An earlier image that another image’s story or a page still uses is kept, and listed once this is done.') }}
        </Description>

        <template #footer>
            <div class="dr-story-confirm">
                <ModalClose>
                    <Button variant="ghost">{{ __('Cancel') }}</Button>
                </ModalClose>
                <Button variant="danger" @click="forget">{{ __('Delete revision history') }}</Button>
            </div>
        </template>
    </Modal>
</template>

<style scoped>
.dr-story {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
}

.dr-story-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1rem 0;
}

.dr-story-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
    padding: 1rem;
}

.dr-story-loading {
    font-size: 0.875rem;
    opacity: 0.75;
}

/* A conversation: what was asked on the right, what came back on the left. */
.dr-story-feed {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    max-width: 52rem;
    margin: 0 auto;
    padding: 0;
    list-style: none;
}

.dr-story-entry {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.dr-story-bubble {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    max-width: min(36rem, 100%);
    padding: 0.625rem 0.75rem;
    border-radius: 0.75rem;
}

.dr-story-bubble--ask {
    align-self: flex-end;
    background: color-mix(in oklab, #ff2d55 8%, transparent);
}

.dr-story-label,
.dr-story-heading {
    font-size: 0.8125rem;
    font-weight: 600;
}

.dr-story-heading {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.5rem;
}

.dr-story-time,
.dr-story-meta {
    font-size: 0.75rem;
    font-weight: 400;
    opacity: 0.65;
    font-variant-numeric: tabular-nums;
}

.dr-story-text {
    font-size: 0.875rem;
    white-space: pre-line;
}

.dr-story-request {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}

.dr-story-badge {
    align-self: flex-start;
}

/* Sized by the image, so a pin's percentage lands on the picture. */
.dr-story-pinned {
    position: relative;
    flex: none;
    width: 10rem;
    line-height: 0;
}

.dr-story-pinned img {
    width: 100%;
    height: auto;
    border-radius: 0.375rem;
}

.dr-story-pin,
.dr-story-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.125rem;
    height: 1.125rem;
    border-radius: 999px;
    background: #ff2d55;
    color: #fff;
    font-size: 0.6875rem;
    font-weight: 600;
    line-height: 1;
}

.dr-story-pin {
    position: absolute;
    margin: -0.5625rem 0 0 -0.5625rem;
    border: 1px solid #fff;
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.35);
}

.dr-story-number {
    flex: none;
    margin-top: 0.125rem;
}

.dr-story-words {
    flex: 1 1 12rem;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.dr-story-notes {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 0.875rem;
}

.dr-story-notes li {
    display: flex;
    align-items: flex-start;
    gap: 0.375rem;
}

.dr-story-answer {
    align-self: flex-start;
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    max-width: min(40rem, 100%);
}

.dr-story-image {
    display: block;
    max-width: 100%;
    max-height: 60vh;
    width: auto;
    height: auto;
    border-radius: 0.5rem;
}

/* On a phone the pinned image takes the bubble's width, notes below it. */
@media (max-width: 40rem) {
    .dr-story-request {
        flex-direction: column;
    }

    .dr-story-pinned {
        width: 100%;
    }

    .dr-story-words {
        flex: none;
    }
}

.dr-story-missing {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 6rem;
    min-width: min(16rem, 100%);
    padding: 0.75rem;
    border-radius: 0.5rem;
    text-align: center;
    font-size: 0.8125rem;
    background: color-mix(in oklab, currentColor 6%, transparent);
}

.dr-story-saved {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8125rem;
    word-break: break-all;
}

.dr-story-hint {
    font-size: 0.8125rem;
    opacity: 0.75;
}

.dr-story-files {
    margin: 0.75rem 0;
    padding: 0;
    list-style: none;
    font-size: 0.875rem;
    word-break: break-all;
}

.dr-story-small {
    font-size: 0.75rem;
    opacity: 0.75;
}

.dr-story-confirm {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 0.75rem 0.5rem 0.25rem;
}
</style>
