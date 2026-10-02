<script setup>
import { computed } from 'vue';
import { Button, Description, Modal, ModalClose } from '@statamic/cms/ui';

const props = defineProps({
    // "trash" when moving out of History, "destroy" when deleting for good.
    mode: { type: String, default: 'trash' },
    // How many images were chosen in all.
    count: { type: Number, default: 0 },
    // The chosen images that are used on the site: { id, name, places: [{ type, title, url }] }.
    used: { type: Array, default: () => [] },
    days: { type: Number, default: 30 },
});

const emit = defineEmits(['choose']);

const open = defineModel('open', { type: Boolean, default: false });

const unused = computed(() => props.count - props.used.length);

// One image is "it", several are "them", in every sentence below.
const intro = computed(() => {
    if (props.mode === 'destroy') {
        return props.count === 1
            ? __('This deletes it from the asset library. It cannot be undone.')
            : __('This deletes them from the asset library. It cannot be undone.');
    }

    return props.used.length === 1
        ? __('Moving it to Trash deletes it in :days days, and the pages using it lose the image. Or keep it in the asset library, where it stays in use, and Darkroom just stops listing it.', { days: props.days })
        : __('Moving them to Trash deletes them in :days days, and the pages using them lose the images. Or keep them in the asset library, where they stay in use, and Darkroom just stops listing them.', { days: props.days });
});

// Statamic clears an asset from the fields its blueprints know about, but
// Darkroom also finds it elsewhere, so this only promises what is certain.
const warning = computed(() => {
    const used = props.used.length;

    const lead =
        used === props.count
            ? props.count === 1 ? __('It is still used on the site.') : __('They are all still used on the site.')
            : used === 1 ? __('One of them is still used on the site.') : __(':n of them are still used on the site.', { n: used });

    return `${lead} ${__('The places listed below will be missing the image.')}`;
});

const title = computed(() => {
    if (props.mode === 'destroy') {
        return props.count === 1 ? __('Delete this image forever?') : __('Delete :n images forever?', { n: props.count });
    }

    return props.used.length === 1 ? __('This image is used on the site') : __(':n of these images are used on the site', { n: props.used.length });
});
</script>

<template>
    <Modal v-model:open="open" :title="title">
        <template v-if="mode === 'destroy'">
            <Description>{{ intro }}</Description>
            <Description v-if="used.length" class="dr-removal-warning">{{ warning }}</Description>
        </template>

        <template v-else>
            <Description>{{ intro }}</Description>
            <Description v-if="unused > 0">
                {{ unused === 1 ? __('The other image you chose goes to Trash either way.') : __('The other :n images you chose go to Trash either way.', { n: unused }) }}
            </Description>
        </template>

        <ul v-if="used.length" class="dr-removal-list">
            <li v-for="image in used" :key="image.id">
                <strong>{{ image.name }}</strong>
                <span class="dr-removal-places">
                    {{ __('Used in') }}
                    <template v-for="(place, index) in image.places" :key="index">
                        <template v-if="index">, </template>
                        <a v-if="place.url" :href="place.url" target="_blank" rel="noopener">{{ place.title }}</a>
                        <template v-else>{{ place.title }}</template>
                        ({{ place.type }})
                    </template>
                </span>
            </li>
        </ul>

        <Description class="dr-removal-note">
            {{ __('Images written into templates or linked from other sites cannot be detected.') }}
        </Description>

        <template #footer>
            <div class="dr-removal-footer">
                <ModalClose>
                    <Button variant="ghost">{{ __('Cancel') }}</Button>
                </ModalClose>

                <template v-if="mode === 'destroy'">
                    <Button variant="danger" @click="emit('choose', 'destroy')">{{ __('Delete forever') }}</Button>
                </template>

                <template v-else>
                    <Button @click="emit('choose', 'trash')">{{ __('Move to Trash anyway') }}</Button>
                    <Button variant="primary" @click="emit('choose', 'keep')">{{ __('Keep in asset library') }}</Button>
                </template>
            </div>
        </template>
    </Modal>
</template>

<style scoped>
.dr-removal-warning {
    margin-top: 0.75rem;
}

.dr-removal-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin: 0.75rem 0;
    padding: 0;
    list-style: none;
}

.dr-removal-list li {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
}

.dr-removal-places {
    font-size: 0.875rem;
}

.dr-removal-places a {
    text-decoration: underline;
}

.dr-removal-note {
    font-size: 0.75rem;
    opacity: 0.75;
}

.dr-removal-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 0.75rem 0.5rem 0.25rem;
}
</style>
