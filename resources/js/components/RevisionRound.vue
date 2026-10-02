<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@statamic/cms/ui';
import { shortDate, usd } from '../composables/format.js';

/**
 * One round in the Revise panel's feed: what was asked, then what came back.
 */
const props = defineProps({
    round: { type: Object, required: true },
    // Whether this round's image is the one being revised next.
    current: { type: Boolean, default: false },
    // Price of one image at this round's model and size, when the feed did not say.
    price: { type: Number, default: null },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['choose', 'save', 'discard', 'retry']);

const waiting = computed(() => ['pending', 'generating'].includes(props.round.status));

const meta = computed(() =>
    [props.round.modelLabel, props.round.qualityLabel, usd(props.round.price ?? props.price)].filter(Boolean).join(' · '),
);

const memory = computed(
    () =>
        ({
            continued: __('Remembered earlier rounds'),
            lost: __('Started fresh: the earlier conversation had expired'),
        })[props.round.memory] ?? null,
);

const gone = computed(
    () =>
        ({
            discarded: __('Discarded'),
            saving: __('Saving…'),
        })[props.round.status] ?? __('Image no longer kept'),
);

// Seconds since the round was sent, while it is on its way.
const elapsed = ref(0);
let ticker = null;

onMounted(() => {
    const tick = () => (elapsed.value = Math.max(0, Math.round(Date.now() / 1000 - props.round.at)));

    tick();
    ticker = setInterval(tick, 1000);
});

onBeforeUnmount(() => clearInterval(ticker));
</script>

<template>
    <li class="dr-round" :class="{ 'is-current': current }">
        <div class="dr-round-ask">
            <p class="dr-round-heading">
                <strong>{{ __('Round :n', { n: round.number }) }}</strong>
                <span v-if="round.branched">
                    {{ round.parentNumber ? __('from round :n', { n: round.parentNumber }) : __('from the original') }}
                </span>
                <span class="dr-round-time">{{ shortDate(round.at, true) }}</span>
            </p>

            <ol v-if="round.notes.length" class="dr-round-notes">
                <li v-for="(note, index) in round.notes" :key="index">
                    <span class="dr-round-pin" aria-hidden="true">{{ index + 1 }}</span>
                    <span>{{ note.text }}</span>
                </li>
            </ol>
            <p v-if="round.general" class="dr-round-general">{{ round.general }}</p>
        </div>

        <div class="dr-round-answer">
            <button
                v-if="round.image"
                type="button"
                class="dr-round-image"
                :disabled="!round.target || busy || current"
                :aria-label="__('Revise from round :n', { n: round.number })"
                @click="emit('choose')"
            >
                <img :src="round.image" alt="" />
            </button>
            <div v-else-if="waiting" class="dr-round-placeholder" role="status">{{ __('Generating…') }} {{ elapsed }}s</div>
            <div v-else-if="round.status === 'failed'" class="dr-round-placeholder dr-round-placeholder--failed">
                {{ round.item?.error?.message ?? __('This image could not be generated.') }}
            </div>
            <div v-else class="dr-round-placeholder">{{ gone }}</div>

            <p v-if="meta || memory" class="dr-round-meta">
                {{ meta }}<template v-if="meta && memory"> · </template>{{ memory }}
            </p>

            <div class="dr-round-actions">
                <span v-if="current" class="dr-round-current">{{ __('Revising this') }}</span>
                <Button v-else-if="round.target" size="xs" :disabled="busy" @click="emit('choose')">{{ __('Revise from this') }}</Button>
                <Button v-if="round.status === 'complete'" size="xs" :disabled="busy" @click="emit('save')">{{ __('Save…') }}</Button>
                <span v-if="round.status === 'saved'" class="dr-round-saved">{{ __('Saved') }}</span>
                <Button v-if="round.status === 'failed' && round.item?.error?.retryable" size="xs" icon="sync" :disabled="busy" @click="emit('retry')">
                    {{ __('Try again') }}
                </Button>
                <Button
                    v-if="round.status === 'complete' || round.status === 'failed'"
                    size="xs"
                    variant="ghost"
                    :disabled="busy"
                    @click="emit('discard')"
                >
                    {{ round.status === 'failed' ? __('Dismiss') : __('Discard') }}
                </Button>
            </div>
        </div>
    </li>
</template>

<style scoped>
.dr-round {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding: 0.75rem;
    border-radius: 0.75rem;
    border: 1px solid color-mix(in oklab, currentColor 12%, transparent);
}

.dr-round.is-current {
    border-color: color-mix(in oklab, #ff2d55 55%, transparent);
    box-shadow: 0 0 0 1px color-mix(in oklab, #ff2d55 35%, transparent);
}

.dr-round-heading {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.5rem;
    font-size: 0.8125rem;
}

.dr-round-time,
.dr-round-meta {
    font-size: 0.75rem;
    opacity: 0.65;
    font-variant-numeric: tabular-nums;
}

.dr-round-time {
    margin-inline-start: auto;
}

.dr-round-ask {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    padding: 0.5rem 0.625rem;
    border-radius: 0.5rem;
    background: color-mix(in oklab, currentColor 5%, transparent);
}

.dr-round-notes {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 0.875rem;
}

.dr-round-notes li {
    display: flex;
    align-items: flex-start;
    gap: 0.375rem;
}

.dr-round-pin {
    flex: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.125rem;
    height: 1.125rem;
    margin-top: 0.125rem;
    border-radius: 999px;
    background: #ff2d55;
    color: #fff;
    font-size: 0.6875rem;
    font-weight: 600;
}

.dr-round-general {
    font-size: 0.875rem;
}

.dr-round-answer {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.dr-round-image {
    display: block;
    width: 100%;
    padding: 0;
    border: 0;
    border-radius: 0.5rem;
    overflow: hidden;
    cursor: pointer;
    line-height: 0;
    background: color-mix(in oklab, currentColor 6%, transparent);
}

.dr-round-image:disabled {
    cursor: default;
}

.dr-round-image img {
    width: 100%;
    height: auto;
}

.dr-round-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 5rem;
    padding: 0.75rem;
    border-radius: 0.5rem;
    text-align: center;
    font-size: 0.8125rem;
    font-variant-numeric: tabular-nums;
    background: color-mix(in oklab, currentColor 6%, transparent);
}

.dr-round-placeholder--failed {
    background: color-mix(in oklab, #dc2626 10%, transparent);
}

.dr-round-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.375rem;
}

.dr-round-current,
.dr-round-saved {
    font-size: 0.75rem;
    font-weight: 600;
}

.dr-round-current {
    color: #ff2d55;
}
</style>
