<script setup>
import { computed, ref, watch } from 'vue';
import { Button, Description, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows } from '@statamic/cms/ui';
import { qualityLabel, shortDate, usd, usdTotal } from '../composables/format.js';
import { http } from '../composables/useHttp.js';

const props = defineProps({
    months: { type: Array, required: true },
    url: { type: String, required: true },
});

// The month whose individual images are listed, and those images.
const open = ref(null);
const entries = ref([]);
const loading = ref(false);

const thisMonth = computed(() => {
    const now = new Date();
    const key = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;

    return props.months.find((month) => month.month === key) ?? null;
});

const openLabel = computed(() => props.months.find((month) => month.month === open.value)?.label ?? '');

async function load(month) {
    loading.value = true;

    try {
        entries.value = (await http('GET', `${props.url}/${month}`)).entries;
    } catch (e) {
        Statamic.$toast.error(e.message);
    } finally {
        loading.value = false;
    }
}

function toggle(month) {
    if (open.value === month) {
        open.value = null;
        entries.value = [];

        return;
    }

    open.value = month;
    load(month);
}

// New images are generated while the list is open, so keep it current.
watch(
    () => props.months.find((month) => month.month === open.value)?.images,
    (images, before) => {
        if (open.value && images !== before) {
            load(open.value);
        }
    },
);

const breakdown = (month) =>
    month.models.map((model) => `${model.label} × ${model.images}`).join(', ');
</script>

<template>
    <div>
        <p class="dr-spend-headline">
            <template v-if="thisMonth">
                <strong>{{ usdTotal(thisMonth.total) }}</strong>
                {{ thisMonth.images === 1 ? __('this month, for 1 image') : __('this month, for :n images', { n: thisMonth.images }) }}
                <template v-if="thisMonth.altTexts">
                    {{ thisMonth.altTexts === 1 ? __('and 1 alt text') : __('and :n alt texts', { n: thisMonth.altTexts }) }}
                </template>
            </template>
            <template v-else>{{ __('Nothing generated this month.') }}</template>
        </p>

        <Table v-if="months.length" class="dr-spend-table">
            <TableColumns>
                <TableColumn>{{ __('Month') }}</TableColumn>
                <TableColumn>{{ __('Images') }}</TableColumn>
                <TableColumn>{{ __('Models') }}</TableColumn>
                <TableColumn class="dr-spend-number">{{ __('Estimated cost') }}</TableColumn>
                <TableColumn />
            </TableColumns>
            <TableRows>
                <TableRow v-for="month in months" :key="month.month">
                    <TableCell>{{ month.label }}</TableCell>
                    <TableCell class="dr-spend-figure">{{ month.images }}</TableCell>
                    <TableCell>{{ breakdown(month) }}</TableCell>
                    <TableCell class="dr-spend-number dr-spend-figure">
                        {{ usdTotal(month.total) }}<template v-if="month.unpriced">+</template>
                    </TableCell>
                    <TableCell class="dr-spend-action">
                        <Button size="sm" variant="ghost" @click="toggle(month.month)">
                            {{ open === month.month ? __('Hide images') : __('List images') }}
                        </Button>
                    </TableCell>
                </TableRow>
            </TableRows>
        </Table>

        <div v-if="open" class="dr-spend-items">
            <h3 class="dr-spend-subheading">{{ openLabel }}</h3>

            <Description v-if="loading && !entries.length">{{ __('Loading…') }}</Description>

            <Table v-else class="dr-spend-table">
                <TableColumns>
                    <TableColumn>{{ __('When') }}</TableColumn>
                    <TableColumn>{{ __('Model') }}</TableColumn>
                    <TableColumn>{{ __('Quality') }}</TableColumn>
                    <TableColumn>{{ __('Aspect ratio') }}</TableColumn>
                    <TableColumn>{{ __('Prompt') }}</TableColumn>
                    <TableColumn class="dr-spend-number">{{ __('Price') }}</TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="(entry, index) in entries" :key="`${entry.at}-${index}`">
                        <TableCell class="dr-spend-figure dr-spend-nowrap">{{ shortDate(entry.at, true) }}</TableCell>
                        <TableCell class="dr-spend-nowrap">{{ entry.model_label ?? entry.model }}</TableCell>
                        <TableCell>{{ qualityLabel(entry.quality) ?? '' }}</TableCell>
                        <TableCell>{{ entry.aspect_ratio === 'auto' ? __('Auto') : entry.aspect_ratio }}</TableCell>
                        <TableCell class="dr-spend-prompt" :title="entry.prompt">{{ entry.prompt }}</TableCell>
                        <TableCell class="dr-spend-number dr-spend-figure">{{ usd(entry.price) ?? '?' }}</TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </div>

        <Description class="dr-spend-note">
            {{ __('Estimates in US dollars, from Google’s list price when each image was generated. Every image Google returned is counted, including ones you discarded. Your Google invoice is the real figure.') }}
            <template v-if="months.some((month) => month.unpriced)">
                {{ __('A + means some images had no price in the config, so that total is short.') }}
            </template>
        </Description>
    </div>
</template>

<style scoped>
.dr-spend-headline {
    margin-bottom: 1rem;
    font-size: 1rem;
}

.dr-spend-headline strong {
    font-size: 1.5rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    margin-inline-end: 0.25rem;
}

.dr-spend-table {
    width: 100%;
}

.dr-spend-number {
    text-align: end;
}

.dr-spend-figure {
    font-variant-numeric: tabular-nums;
}

.dr-spend-nowrap {
    white-space: nowrap;
}

.dr-spend-action {
    text-align: end;
    white-space: nowrap;
}

.dr-spend-prompt {
    max-width: 28rem;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.dr-spend-items {
    margin-top: 1.5rem;
}

.dr-spend-subheading {
    margin-bottom: 0.5rem;
    font-weight: 600;
}

.dr-spend-note {
    margin-top: 1rem;
}
</style>
