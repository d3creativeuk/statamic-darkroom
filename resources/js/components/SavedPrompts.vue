<script setup>
import { computed, ref } from 'vue';
import { Button, Combobox, ConfirmationModal, Field, Input, Modal, ModalClose } from '@statamic/cms/ui';

const props = defineProps({
    prompts: { type: Array, required: true },
    loaded: { type: String, default: null },
    suggestedName: { type: String, default: '' },
    canSave: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['load', 'create', 'update', 'remove']);

const naming = ref(false);
const name = ref('');
const confirmingDelete = ref(false);

const options = computed(() => props.prompts.map((prompt) => ({ value: prompt.id, label: prompt.name })));
const current = computed(() => props.prompts.find((prompt) => prompt.id === props.loaded) ?? null);

function pick(id) {
    emit('load', props.prompts.find((prompt) => prompt.id === id) ?? null);
}

function askForName() {
    name.value = props.suggestedName;
    naming.value = true;
}

function create() {
    if (!name.value.trim()) {
        return;
    }

    emit('create', name.value.trim());
    naming.value = false;
}
</script>

<template>
    <div class="dr-prompts">
        <Combobox
            class="dr-prompts-picker"
            :model-value="loaded"
            :options="options"
            :placeholder="prompts.length ? __('Load a saved prompt') : __('No saved prompts yet')"
            :disabled="disabled || !prompts.length"
            icon="bookmark"
            size="sm"
            clearable
            @update:model-value="pick"
        />

        <!-- With a saved prompt loaded, saving updates it in place. Saving a
             copy under a new name is one click further away. -->
        <template v-if="current">
            <Button size="sm" :disabled="disabled || !canSave" :loading="busy" @click="emit('update')">
                {{ __('Update') }}
            </Button>
            <Button size="sm" variant="ghost" :disabled="disabled || !canSave" @click="askForName">
                {{ __('Save as new') }}
            </Button>
            <Button
                size="sm"
                variant="ghost"
                icon="trash"
                :aria-label="__('Delete saved prompt')"
                :disabled="disabled"
                @click="confirmingDelete = true"
            />
        </template>

        <Button v-else size="sm" icon="add-bookmark" :disabled="disabled || !canSave" @click="askForName">
            {{ __('Save prompt') }}
        </Button>

        <Modal v-model:open="naming" :title="__('Save prompt')">
            <form @submit.prevent="create">
                <Field
                    :label="__('Name')"
                    :instructions="__('Saved with the model, aspect ratio, quality, file type, folder and system instruction you have chosen, and any reference images from the asset library. Uploaded reference images are not kept.')"
                    instructions-below
                >
                    <Input v-model="name" :focus="true" maxlength="120" />
                </Field>
            </form>

            <template #footer>
                <div class="dr-modal-footer">
                    <ModalClose>
                        <Button variant="ghost">{{ __('Cancel') }}</Button>
                    </ModalClose>
                    <Button variant="primary" :disabled="!name.trim()" @click="create">{{ __('Save') }}</Button>
                </div>
            </template>
        </Modal>

        <ConfirmationModal
            v-model:open="confirmingDelete"
            :title="__('Delete saved prompt')"
            :body-text="__('Delete “:name”? Images already generated from it are not affected.', { name: current?.name ?? '' })"
            :button-text="__('Delete')"
            danger
            @confirm="emit('remove')"
        />
    </div>
</template>

<style scoped>
.dr-prompts {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 0.5rem;
    min-width: 0;
}

.dr-prompts-picker {
    width: 16rem;
    max-width: 100%;
}

.dr-modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 0.75rem 0.5rem 0.25rem;
}
</style>
