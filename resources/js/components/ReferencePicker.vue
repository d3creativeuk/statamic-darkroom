<script setup>
import { computed, ref, watch } from 'vue';
import { Button, ListingSearch, Select, Stack, ToggleGroup, ToggleItem } from '@statamic/cms/ui';
import { assetName, escapeHtml } from '../composables/format.js';

/**
 * Choose reference images from the asset library, in Statamic's own asset
 * browser, the way the folder picker chooses a folder. Any container the user
 * can look in is offered; nothing can be uploaded or created from here.
 *
 * Selection is kept here rather than by the browser's max-files, so the
 * number left and the file types can be enforced with a reason given.
 */
const props = defineProps({
    containers: { type: Array, required: true },
    // How many more reference images fit.
    room: { type: Number, required: true },
});

const emit = defineEmits(['choose']);
const open = defineModel('open', { type: Boolean, default: false });

// What Google and the server's image library can both read.
const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

// Templates cannot see the Statamic global, so it is read here.
const perPage = Statamic.$config.get('paginationSize');

const handle = ref(null);
const search = ref(null);
const browser = ref(null);

// The ids ticked in the browser. Shared with the browser's listing, which
// changes it in place for clicks but replaces it after a Delete, Rename or
// Move from a row's menu, so updated() takes whatever it is handed, as
// core's own asset selector does.
const selections = ref([]);
// The ticked images that may be used, with their names, in the order they
// were ticked. Kept here because moving to another folder replaces the rows
// the browser holds.
const names = new Map();

const current = computed(() => props.containers.find((container) => container.handle === handle.value) ?? props.containers[0] ?? null);
const containerOptions = computed(() => props.containers.map((container) => ({ value: container.handle, label: container.title })));

watch(open, (isOpen) => {
    if (isOpen) {
        handle.value = current.value?.handle ?? null;
        selections.value = [];
        names.clear();
    }
});

function changeContainer(value) {
    handle.value = value;
}

function untick(id) {
    const at = selections.value.indexOf(id);

    if (at !== -1) {
        selections.value.splice(at, 1);
    }

    names.delete(id);
}

const extension = (row) => String(row?.extension ?? row?.basename?.split('.').pop() ?? '').toLowerCase();

const nameFromId = assetName;

// A double-click on a tile arrives as two clicks and a double-click, each
// of which would say the same thing, so a message already showing is not
// shown again.
let lastToast = { text: null, at: 0 };

function toast(kind, text) {
    if (text === lastToast.text && Date.now() - lastToast.at < 2500) {
        return;
    }

    lastToast = { text, at: Date.now() };
    Statamic.$toast[kind](text);
}

// One toast per reason, however many rows a select-all or a shift-click
// range brought in. Toasts are HTML, so a name is escaped.
function refuse(wrongType, full) {
    if (wrongType.length) {
        toast(
            'error',
            wrongType.length === 1
                ? __(':name is not a JPEG, PNG or WebP image.', { name: escapeHtml(wrongType[0]) })
                : __(':n of those files are not JPEG, PNG or WebP images.', { n: wrongType.length }),
        );
    }

    if (full) {
        toast('info', __('Only :n more reference images fit.', { n: props.room }));
    }
}

// Decide on newly ticked ids in the order they were ticked, counting only
// the images already accepted, so the first ones that fit are the ones kept.
function accept(ids) {
    const rows = browser.value?.assets ?? [];
    const wrongType = [];
    let full = false;

    ids.filter((id) => !names.has(id)).forEach((id) => {
        // Not on this page: renamed or moved a moment ago, before the listing
        // reloaded. It was checked under its old id, and kept its extension.
        const row = rows.find((candidate) => candidate.id === id) ?? { id, basename: nameFromId(id) };

        if (!EXTENSIONS.includes(extension(row))) {
            wrongType.push(row.basename);
            untick(id);
        } else if (names.size >= props.room) {
            full = true;
            untick(id);
        } else {
            names.set(id, row.basename);
        }
    });

    refuse(wrongType, full);
}

// The browser has ticked or unticked something, possibly a whole page or a
// range at once.
function updated(ids) {
    if (ids !== selections.value) {
        selections.value = ids;
    }

    // Unticked ones first, so they no longer take a place.
    [...names.keys()].filter((id) => !ids.includes(id)).forEach((id) => names.delete(id));

    accept([...ids]);
}

// Clicking an image opens the editor elsewhere. Here it ticks it, as core's
// own asset selector does.
function toggle(row) {
    if (selections.value.includes(row.id)) {
        untick(row.id);

        return;
    }

    selections.value.push(row.id);
    accept([row.id]);
}

// As core's selector does: ready to type as soon as the drawer has loaded.
function focusSearch() {
    search.value?.focus?.();
}

function choose() {
    emit(
        'choose',
        selections.value.map((id) => ({ id, name: names.get(id) ?? nameFromId(id) })),
    );

    open.value = false;
}
</script>

<template>
    <Stack v-model:open="open" inset :show-close-button="false">
        <div v-if="open && current" class="flex h-full min-h-0 flex-col">
            <div class="flex flex-1 flex-col gap-4 overflow-auto p-4">
                <asset-browser
                    ref="browser"
                    :key="current.handle"
                    :container="current.browser"
                    :initial-per-page="perPage"
                    :initial-columns="current.columns"
                    selected-path="/"
                    :selected-assets="selections"
                    :allow-bulk-actions="false"
                    @initialized="focusSearch"
                    @selections-updated="updated"
                    @edit-asset="toggle"
                >
                    <template #header="{ mode, modeChanged }">
                        <div class="flex items-center gap-2 sm:gap-3 mb-4">
                            <div class="flex flex-1 items-center gap-2 sm:gap-3">
                                <ListingSearch ref="search" />
                                <Select
                                    v-if="containers.length > 1"
                                    class="w-56"
                                    :model-value="current.handle"
                                    :options="containerOptions"
                                    @update:model-value="changeContainer"
                                />
                            </div>

                            <ToggleGroup :model-value="mode" @update:model-value="modeChanged">
                                <ToggleItem icon="layout-grid" value="grid" />
                                <ToggleItem icon="layout-list" value="table" />
                            </ToggleGroup>
                        </div>
                    </template>
                </asset-browser>
            </div>

            <div class="flex items-center justify-between border-t bg-gray-100 dark:bg-gray-850 dark:border-gray-700 px-4 py-2 sm:p-4">
                <div class="dark:text-gray-200 text-sm text-gray-700">
                    <div v-text="__(':n selected', { n: selections.length })" />
                    <div class="text-xs opacity-75" v-text="__('Up to :n more reference images fit.', { n: room })" />
                </div>

                <div class="flex items-center space-x-3">
                    <Button variant="ghost" @click="open = false">{{ __('Cancel') }}</Button>
                    <Button variant="primary" :disabled="!selections.length" @click="choose">{{ __('Add') }}</Button>
                </div>
            </div>
        </div>
    </Stack>
</template>
