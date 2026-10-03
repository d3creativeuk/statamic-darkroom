<script setup>
import { computed, ref, watch } from 'vue';
import { http } from '../composables/useHttp.js';
import { Button, ListingSearch, Select, Stack, ToggleGroup, ToggleItem } from '@statamic/cms/ui';

/**
 * Choose where images are saved, using Statamic's own asset browser: the same
 * search, folders, breadcrumbs and Create Folder as the Assets section. The
 * browser is core's globally registered <asset-browser>, and the header and
 * footer copy the markup of core's asset selector, so the whole drawer looks
 * and behaves as it does when picking an asset anywhere else.
 */
const props = defineProps({
    containers: { type: Array, required: true },
    // Where the browser opens: the last place something was saved.
    container: { type: String, default: null },
    folder: { type: String, default: '' },
    count: { type: Number, default: 1 },
    // Anything else saving these images does, said under where they go.
    note: { type: String, default: null },
    // Lists a container's folders; "__container__" is replaced by its handle.
    foldersUrl: { type: String, required: true },
});

const emit = defineEmits(['choose']);
const open = defineModel('open', { type: Boolean, default: false });

// Templates cannot see the Statamic global, so it is read here.
const perPage = Statamic.$config.get('paginationSize');

const handle = ref(null);
const path = ref('/');
const search = ref(null);
const browser = ref(null);

// Core's asset search only ever matches files, so a folder, and above all a
// new, empty one, can never be found by typing its name. Folder names are
// matched here instead and offered above the file results.
const folders = ref([]);

async function loadFolders() {
    if (!current.value) {
        return;
    }

    try {
        folders.value = (await http('GET', props.foldersUrl.replace('__container__', current.value.handle))).folders;
    } catch (e) {
        folders.value = [];
    }
}

// What has been typed into core's search box, read from the browser itself.
const query = computed(() => String(browser.value?.searchQuery ?? '').trim().toLowerCase());

const matches = computed(() =>
    query.value ? folders.value.filter((path) => path.toLowerCase().includes(query.value)).slice(0, 8) : [],
);

// A folder made in this drawer a moment ago has to be in the list too, so
// the list is read again as a new search starts.
watch(query, (now, before) => {
    if (now && !before) {
        loadFolders();
    }
});

function goTo(path) {
    // Core's search box holds its text inside the listing and only clears it
    // itself, which it does on Escape. Pressing that for the user ends the
    // search the supported way, box and results together.
    search.value?.$el
        ?.querySelector('input')
        ?.dispatchEvent(new KeyboardEvent('keyup', { key: 'Escape', bubbles: true }));

    browser.value.selectFolder(path);
}

// The browser keeps its own selection list. Nothing is selected here, but it
// still needs one to write to.
const selections = ref([]);

const current = computed(() => props.containers.find((container) => container.handle === handle.value) ?? props.containers[0] ?? null);

const containerOptions = computed(() => props.containers.map((container) => ({ value: container.handle, label: container.title })));

// Folder paths as the server wants them: no slashes at either end, and the
// top level as an empty string.
const folder = computed(() => String(path.value ?? '').replace(/^\/+|\/+$/g, ''));

const where = computed(() => [current.value?.title, ...folder.value.split('/').filter(Boolean)].join(' / '));

watch(open, (isOpen) => {
    if (isOpen) {
        handle.value = props.containers.some((container) => container.handle === props.container)
            ? props.container
            : props.containers[0]?.handle ?? null;
        path.value = props.folder ? props.folder : '/';

        loadFolders();
    }
});

function changeContainer(value) {
    handle.value = value;
    path.value = '/';

    loadFolders();
}

// The browser reports every move, including opening an asset to edit it
// ("folder/photo.jpg/edit"). Only folder moves count.
function navigated(value) {
    if (!String(value ?? '').endsWith('/edit')) {
        path.value = value || '/';
    }
}

// As core's selector does: ready to type as soon as the drawer has loaded.
function focusSearch() {
    search.value?.focus?.();
}

function choose() {
    emit('choose', { container: current.value.handle, folder: folder.value });
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
                    :selected-path="path"
                    :selected-assets="selections"
                    :allow-bulk-actions="false"
                    @initialized="focusSearch"
                    @path-changed="navigated"
                    @navigated="navigated"
                >
                    <template #header="{ canCreateFolders, startCreatingFolder, mode, modeChanged }">
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

                            <Button v-if="canCreateFolders" :text="__('Create Folder')" icon="folder-add" @click="startCreatingFolder" />

                            <ToggleGroup :model-value="mode" @update:model-value="modeChanged">
                                <ToggleItem icon="layout-grid" value="grid" />
                                <ToggleItem icon="layout-list" value="table" />
                            </ToggleGroup>
                        </div>

                        <div v-if="matches.length" class="flex flex-wrap items-center gap-2 mb-4">
                            <span class="dark:text-gray-200 text-sm text-gray-700">{{ __('Folders') }}</span>
                            <Button v-for="match in matches" :key="match" size="sm" icon="folder" @click="goTo(match)">
                                {{ match }}
                            </Button>
                        </div>
                    </template>
                </asset-browser>
            </div>

            <div class="flex items-center justify-between border-t bg-gray-100 dark:bg-gray-850 dark:border-gray-700 px-4 py-2 sm:p-4">
                <div class="dark:text-gray-200 text-sm text-gray-700">
                    <div v-text="count > 1 ? __('Save :n images to :where', { n: count, where }) : __('Save to :where', { where })" />
                    <div v-if="note" class="text-xs opacity-75" v-text="note" />
                </div>

                <div class="flex items-center space-x-3">
                    <Button variant="ghost" @click="open = false">{{ __('Cancel') }}</Button>
                    <Button variant="primary" @click="choose">{{ __('Save here') }}</Button>
                </div>
            </div>
        </div>
    </Stack>
</template>
