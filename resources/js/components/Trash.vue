<script setup>
import { computed, ref, watch } from 'vue';
import { Button, Checkbox, Description, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows } from '@statamic/cms/ui';
import { shortDate } from '../composables/format.js';

const props = defineProps({
    items: { type: Array, required: true },
    // How long an image waits here before it is deleted.
    days: { type: Number, default: 30 },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['restore', 'destroy']);

const selected = ref([]);

// Anything restored, deleted or emptied by the clean-up leaves the selection.
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

// Whole days left, counted up so an image due later today still says "today".
function daysLeft(item) {
    return Math.max(0, Math.ceil((item.deletesAt * 1000 - Date.now()) / 86400000));
}

function deletes(item) {
    const days = daysLeft(item);

    if (days === 0) {
        return __('Today');
    }

    return days === 1 ? __('In 1 day') : __('In :n days', { n: days });
}

const where = (item) => [item.containerTitle, item.folder].filter(Boolean).join(' / ');
</script>

<template>
    <div>
        <Description class="dr-trash-intro">
            {{ __('Images here stay in the asset library, and keep working wherever they are used, until they are deleted :days days after being moved here. An image still used on the site is never deleted automatically: Darkroom just stops listing it.', { days }) }}
        </Description>

        <div class="dr-trash-toolbar">
            <Checkbox
                solo
                :label="__('Select all')"
                :model-value="selected.length > 0"
                :indeterminate="someSelected"
                :disabled="disabled"
                @update:model-value="toggleAll"
            />

            <span class="dr-trash-count">
                {{
                    selected.length
                        ? selected.length === 1 ? __('1 selected') : __(':n selected', { n: selected.length })
                        : items.length === 1 ? __('1 image') : __(':n images', { n: items.length })
                }}
            </span>

            <template v-if="selected.length">
                <Button size="sm" :disabled="disabled" @click="emit('restore', [...selected])">{{ __('Restore') }}</Button>
                <Button size="sm" variant="danger" :disabled="disabled" @click="emit('destroy', [...selected])">
                    {{ __('Delete forever') }}
                </Button>
            </template>
        </div>

        <!-- Narrow, the date it was moved gives way to when it goes; anything
             still too wide scrolls rather than being cut off. -->
        <div class="dr-trash-scroll">
            <Table class="dr-trash-table">
                <TableColumns>
                    <TableColumn class="dr-trash-check-column"><span class="sr-only">{{ __('Select') }}</span></TableColumn>
                    <TableColumn>{{ __('Image') }}</TableColumn>
                    <TableColumn class="dr-trash-moved">{{ __('Moved to Trash') }}</TableColumn>
                    <TableColumn>{{ __('Deleted') }}</TableColumn>
                </TableColumns>
            <TableRows>
                    <TableRow v-for="item in items" :key="item.id" :class="{ 'is-selected': isSelected(item) }">
                        <TableCell class="dr-trash-check-column">
                            <Checkbox
                                solo
                                :label="__('Select :path', { path: item.path })"
                                :model-value="isSelected(item)"
                                :disabled="disabled"
                                @update:model-value="(on) => toggle(item, on)"
                            />
                        </TableCell>
                        <TableCell>
                            <div class="dr-trash-file">
                                <img class="dr-trash-thumb" :src="item.thumbnail" :alt="item.alt || ''" loading="lazy" />
                                <div class="dr-trash-name">
                                    <span class="dr-trash-basename" :title="item.path">{{ item.basename }}</span>
                                    <span class="dr-trash-where">{{ where(item) }}</span>
                                </div>
                            </div>
                        </TableCell>
                        <TableCell class="dr-trash-nowrap dr-trash-moved">{{ shortDate(item.trashedAt) }}</TableCell>
                        <TableCell class="dr-trash-nowrap">{{ deletes(item) }}</TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </div>
    </div>
</template>

<style scoped>
.dr-trash-intro {
    margin-bottom: 1rem;
}

.dr-trash-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.75rem;
    min-height: 2rem;
    margin-bottom: 0.75rem;
}

.dr-trash-count {
    font-size: 0.875rem;
    font-variant-numeric: tabular-nums;
}

.dr-trash-scroll {
    overflow-x: auto;
    container-type: inline-size;
}

.dr-trash-table {
    width: 100%;
}

@container (max-width: 34rem) {
    .dr-trash-moved {
        display: none;
    }
}

.dr-trash-check-column {
    width: 2.5rem;
}

.dr-trash-file {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-width: 0;
}

/* The whole image, never cropped, in one size of box. */
.dr-trash-thumb {
    flex: none;
    width: 3rem;
    height: 3rem;
    object-fit: contain;
    border-radius: 0.375rem;
    background: color-mix(in oklab, currentColor 6%, transparent);
}

.dr-trash-name {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.dr-trash-basename {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.dr-trash-where {
    font-size: 0.75rem;
    opacity: 0.65;
}

.dr-trash-nowrap {
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
</style>
