<script setup>
import { computed, ref } from 'vue';

/**
 * Opens an asset in Statamic's own asset editor without leaving the page.
 *
 * Core does not expose its editor to addons, but its asset browser opens the
 * editor for any asset it is given on load. So the browser is mounted out of
 * sight with that asset, the editor appears in its usual full-size panel, and
 * the browser is removed again when the editor closes.
 */
const props = defineProps({
    containers: { type: Array, required: true },
    // The asset to show: { id, container, folder }, or null when closed.
    asset: { type: Object, default: null },
});

const emit = defineEmits(['closed']);

// Templates cannot see the Statamic global, so it is read here.
const perPage = Statamic.$config.get('paginationSize');
const selections = ref([]);

const container = computed(() => props.containers.find((candidate) => candidate.handle === props.asset?.container) ?? null);

// The browser reports the editor opening as a path ending in "/edit", and
// closing as the same path without it.
function navigated(path) {
    if (!String(path ?? '').endsWith('/edit')) {
        emit('closed');
    }
}
</script>

<template>
    <div v-if="asset && container" class="hidden">
        <asset-browser
            :key="asset.id"
            :container="container.browser"
            :initial-per-page="perPage"
            :initial-columns="container.columns"
            :selected-path="asset.folder || '/'"
            :selected-assets="selections"
            :allow-bulk-actions="false"
            :initial-editing-asset-id="asset.id"
            @navigated="navigated"
        />
    </div>
</template>
