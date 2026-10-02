<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button, ErrorMessage, Input, Select } from '@statamic/cms/ui';
import { fileSize, slugify } from '../composables/format.js';

const props = defineProps({
    item: { type: Object, required: true },
    batch: { type: Object, required: true },
    fileTypes: { type: Array, required: true },
    busy: { type: Boolean, default: false },
    // Whether any model can make this image larger than it is.
    canUpscale: { type: Boolean, default: false },
    // Whether a new image can be started now, so it can be revised.
    canRevise: { type: Boolean, default: false },
    writingAlt: { type: Boolean, default: false },
});

const emit = defineEmits(['save', 'retry', 'discard', 'upscale', 'revise', 'alt']);

const filename = defineModel('filename', { type: String });
const alt = defineModel('alt', { type: String });
const fileType = defineModel('fileType', { type: String });

const waiting = computed(() => ['pending', 'generating'].includes(props.item.status));

// The shape of the placeholder while the image is on its way, so the grid
// does not jump when it arrives.
const placeholderRatio = computed(() => {
    const [w, h] = String(props.batch.aspectRatio).split(':').map(Number);

    return w && h ? `${w} / ${h}` : '1 / 1';
});

const details = computed(() =>
    [
        props.item.width && props.item.height ? `${props.item.width} × ${props.item.height}` : null,
        fileSize(props.item.bytes),
    ]
        .filter(Boolean)
        .join(' · '),
);

// Seconds since the batch was created, for the waiting message.
const elapsed = ref(0);
let ticker = null;

function tick() {
    elapsed.value = Math.max(0, Math.round(Date.now() / 1000 - props.batch.createdAt));
}

onMounted(() => {
    tick();
    ticker = setInterval(tick, 1000);
});

onBeforeUnmount(() => clearInterval(ticker));
</script>

<template>
    <article class="dr-card" :class="`dr-card--${item.status}`">
        <div v-if="waiting" class="dr-card-placeholder" :style="{ aspectRatio: placeholderRatio }" role="status">
            <span class="dr-card-pulse" aria-hidden="true" />
            <span class="dr-card-waiting">
                {{ __('Generating…') }} {{ elapsed }}s
                <template v-if="item.attempts > 1">
                    <br />{{ __('Google was busy. Attempt :n.', { n: item.attempts }) }}
                </template>
            </span>
        </div>

        <div v-else-if="item.status === 'failed'" class="dr-card-placeholder dr-card-placeholder--failed" :style="{ aspectRatio: placeholderRatio }">
            <span class="dr-card-waiting">{{ item.error?.message ?? __('This image could not be generated.') }}</span>
        </div>

        <!-- A plain link, not an Inertia one: this is an image, not a page. -->
        <a v-else-if="item.urls.preview" :href="item.urls.preview" target="_blank" rel="noopener" class="dr-card-image">
            <img :src="item.urls.preview" :alt="alt || __('Generated image :n', { n: item.index })" loading="lazy" />
        </a>

        <div class="dr-card-body">
            <template v-if="item.status === 'complete' || item.status === 'saving'">
                <p class="dr-card-meta">{{ details }}</p>

                <div class="dr-card-name">
                    <!-- Made URL-safe as it is typed or pasted, the way it will be saved. -->
                    <Input
                        :model-value="filename"
                        size="sm"
                        :aria-label="__('Filename')"
                        :placeholder="__('Filename')"
                        :disabled="item.status === 'saving'"
                        maxlength="120"
                        @update:model-value="(value) => (filename = slugify(value))"
                        @focusout="filename = slugify(filename, { trim: true })"
                    />
                    <Select
                        v-model="fileType"
                        class="dr-card-type"
                        size="sm"
                        :options="fileTypes"
                        :aria-label="__('File type')"
                        :disabled="item.status === 'saving'"
                    />
                </div>

                <div class="dr-card-alt">
                    <Input
                        v-model="alt"
                        size="sm"
                        :aria-label="__('Alt text')"
                        :placeholder="__('Alt text')"
                        :disabled="item.status === 'saving' || writingAlt"
                        maxlength="500"
                    />
                    <Button
                        size="sm"
                        icon="ai-sparks"
                        :loading="writingAlt"
                        :disabled="item.status === 'saving' || busy"
                        @click="emit('alt')"
                    >
                        {{ alt ? __('Rewrite') : __('Write') }}
                    </Button>
                </div>

                <ErrorMessage v-if="item.saveError" :text="item.saveError" />

                <div class="dr-card-actions">
                    <Button
                        size="sm"
                        variant="primary"
                        :loading="item.status === 'saving'"
                        :disabled="busy"
                        @click="emit('save')"
                    >
                        {{ item.status === 'saving' ? __('Saving…') : __('Save to folder…') }}
                    </Button>
                    <Button
                        v-if="item.status === 'complete' && canUpscale"
                        size="sm"
                        :disabled="busy"
                        @click="emit('upscale')"
                    >
                        {{ __('Upscale') }}
                    </Button>
                    <Button
                        v-if="item.status === 'complete' && canRevise"
                        size="sm"
                        :disabled="busy"
                        @click="emit('revise')"
                    >
                        {{ __('Revise') }}
                    </Button>
                    <Button
                        v-if="item.status === 'complete'"
                        size="sm"
                        variant="ghost"
                        :disabled="busy"
                        @click="emit('discard')"
                    >
                        {{ __('Discard') }}
                    </Button>
                </div>
            </template>

            <!-- There is no "saved" state here. Once an image is saved it leaves
                 this grid and appears in History. -->
            <template v-else-if="item.status === 'failed'">
                <div class="dr-card-actions">
                    <Button v-if="item.error?.retryable" size="sm" icon="sync" :disabled="busy" @click="emit('retry')">
                        {{ __('Try again') }}
                    </Button>
                    <Button size="sm" variant="ghost" :disabled="busy" @click="emit('discard')">
                        {{ __('Dismiss') }}
                    </Button>
                </div>
            </template>
        </div>
    </article>
</template>

<style scoped>
.dr-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    border: 1px solid color-mix(in oklab, currentColor 14%, transparent);
    border-radius: 0.75rem;
    overflow: hidden;
}

.dr-card-image {
    display: block;
    background: color-mix(in oklab, currentColor 6%, transparent);
}

.dr-card-image img {
    display: block;
    width: 100%;
    height: auto;
}

.dr-card-placeholder {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    text-align: center;
    background: color-mix(in oklab, currentColor 6%, transparent);
    overflow: hidden;
}

.dr-card-placeholder--failed {
    background: color-mix(in oklab, #dc2626 10%, transparent);
}

.dr-card-waiting {
    position: relative;
    font-size: 0.8125rem;
    line-height: 1.4;
    font-variant-numeric: tabular-nums;
}

.dr-card-pulse {
    position: absolute;
    inset: 0;
    background: linear-gradient(100deg, transparent 30%, color-mix(in oklab, currentColor 8%, transparent) 50%, transparent 70%);
    background-size: 200% 100%;
    animation: dr-sweep 1.6s linear infinite;
}

@keyframes dr-sweep {
    from {
        background-position: 200% 0;
    }

    to {
        background-position: -200% 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .dr-card-pulse {
        animation: none;
    }
}

.dr-card-body {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding: 0.75rem;
}

.dr-card-body:empty {
    display: none;
}

.dr-card-meta {
    font-size: 0.75rem;
    opacity: 0.65;
    font-variant-numeric: tabular-nums;
}

.dr-card-name {
    display: flex;
    gap: 0.5rem;
}

.dr-card-name > :first-child {
    flex: 1 1 auto;
    min-width: 0;
}

.dr-card-alt {
    display: flex;
    gap: 0.5rem;
}

.dr-card-alt > :first-child {
    flex: 1 1 auto;
    min-width: 0;
}

.dr-card-type {
    flex: 0 0 6.5rem;
}

.dr-card-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.25rem;
}

</style>
