<script setup>
import { computed, reactive, ref, watch } from 'vue';
import {
    Button,
    Card,
    Checkbox,
    ConfirmationModal,
    Description,
    Heading,
    Input,
    Panel,
    PanelHeader,
    Select,
    Textarea,
} from '@statamic/cms/ui';
import { http } from '../composables/useHttp.js';

const props = defineProps({
    instructions: { type: Array, required: true },
    modelValue: { type: String, default: null },
    url: { type: String, required: true },
    limit: { type: Number, default: 8000 },
    // True when the chosen model ignores the API's own system instruction
    // field, so the text is sent ahead of the prompt instead.
    prepended: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'update:instructions']);

const NONE = '__none';
const CREATE = '__create';

// What the editor is showing: nothing, a blank new instruction, or a saved one.
const editing = ref(props.modelValue ?? NONE);
const draft = reactive({ title: '', body: '', default: false });
const saving = ref(false);
const confirmingDelete = ref(false);

const saved = computed(() => props.instructions.find((instruction) => instruction.id === editing.value) ?? null);

const options = computed(() => [
    { value: NONE, label: __('None') },
    ...props.instructions.map((instruction) => ({
        value: instruction.id,
        label: instruction.default ? `${instruction.title} (${__('default')})` : instruction.title,
    })),
    { value: CREATE, label: __('+ Create new instruction') },
]);

const dirty = computed(() => {
    if (editing.value === NONE) {
        return false;
    }

    if (editing.value === CREATE) {
        return draft.body.trim() !== '';
    }

    return (
        !!saved.value &&
        (draft.title !== saved.value.title ||
            draft.body !== saved.value.body ||
            draft.default !== !!saved.value.default)
    );
});

function fill(instruction) {
    draft.title = instruction?.title ?? '';
    draft.body = instruction?.body ?? '';
    draft.default = !!instruction?.default;
}

function choose(value) {
    editing.value = value;

    fill(props.instructions.find((instruction) => instruction.id === value));

    // A new instruction is not sent with anything until it has been saved.
    emit('update:modelValue', value === NONE || value === CREATE ? null : value);
}

async function save() {
    if (!draft.body.trim()) {
        return;
    }

    saving.value = true;

    try {
        const payload = {
            // An untitled instruction takes its opening words as a title.
            title: draft.title.trim() || draft.body.trim().split(/\s+/).slice(0, 6).join(' ').slice(0, 120),
            body: draft.body,
            default: draft.default,
        };

        const response =
            editing.value === CREATE
                ? await http('POST', props.url, payload)
                : await http('PATCH', `${props.url}/${editing.value}`, payload);

        emit('update:instructions', response.instructions);

        editing.value = response.saved.id;
        fill(response.saved);

        emit('update:modelValue', response.saved.id);
    } finally {
        saving.value = false;
    }
}

async function remove() {
    const response = await http('DELETE', `${props.url}/${editing.value}`);

    emit('update:instructions', response.instructions);

    choose(NONE);
}

async function saveWithFeedback() {
    try {
        await save();

        Statamic.$toast.success(__('System instruction saved'));
    } catch (e) {
        Statamic.$toast.error(e.message);
    }
}

async function removeWithFeedback() {
    try {
        await remove();

        Statamic.$toast.success(__('System instruction deleted'));
    } catch (e) {
        Statamic.$toast.error(e.message);
    }
}

// A saved prompt can bring its own instruction with it.
watch(
    () => props.modelValue,
    (value) => {
        if (value !== null && value !== editing.value) {
            editing.value = value;
            fill(props.instructions.find((instruction) => instruction.id === value));
        }

        if (value === null && editing.value !== CREATE && editing.value !== NONE) {
            editing.value = NONE;
            fill(null);
        }
    },
);

fill(saved.value);

// Generating sends the saved text, so any unsaved edit is saved first.
// Otherwise what is on screen would not be what the model is given.
defineExpose({
    flush: async () => {
        if (dirty.value) {
            await save();
        }
    },
});
</script>

<template>
    <Panel>
        <PanelHeader>
            <Heading>{{ __('System instructions') }}</Heading>
        </PanelHeader>

        <Card>
            <Select :model-value="editing" :options="options" :disabled="disabled" @update:model-value="choose" />

            <Description v-if="editing === NONE" class="dr-instructions-hint">
                {{ __('Optional tone and style instructions, sent with every image you generate. Save the ones you reuse.') }}
            </Description>

            <template v-else>
                <div class="dr-instructions-title">
                    <Input v-model="draft.title" :placeholder="__('Title')" :disabled="disabled" maxlength="120" />
                    <Button
                        v-if="saved"
                        variant="ghost"
                        icon="trash"
                        :aria-label="__('Delete system instruction')"
                        :disabled="disabled"
                        @click="confirmingDelete = true"
                    />
                </div>

                <Textarea
                    v-model="draft.body"
                    class="dr-instructions-body"
                    :rows="10"
                    :disabled="disabled"
                    :maxlength="limit"
                    :placeholder="__('Optional tone and style instructions for the model')"
                />

                <div class="dr-instructions-footer">
                    <Checkbox v-model="draft.default" :label="__('Use by default')" :disabled="disabled" />
                    <Button
                        size="sm"
                        :variant="dirty ? 'primary' : 'default'"
                        :disabled="disabled || !dirty"
                        :loading="saving"
                        @click="saveWithFeedback"
                    >
                        {{ editing === CREATE ? __('Save instruction') : __('Save changes') }}
                    </Button>
                </div>

                <Description v-if="prepended" class="dr-instructions-hint">
                    {{ __('This model ignores system instructions, so yours is placed at the start of the prompt instead.') }}
                </Description>
            </template>
        </Card>

        <ConfirmationModal
            v-model:open="confirmingDelete"
            :title="__('Delete system instruction')"
            :body-text="__('Delete “:title”? Saved prompts that use it will fall back to no instruction.', { title: saved?.title ?? '' })"
            :button-text="__('Delete')"
            danger
            @confirm="removeWithFeedback"
        />
    </Panel>
</template>

<style scoped>
.dr-instructions-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.75rem;
}

.dr-instructions-title > :first-child {
    flex: 1 1 auto;
    min-width: 0;
}

.dr-instructions-body {
    margin-top: 0.75rem;
}

.dr-instructions-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-top: 0.75rem;
}

.dr-instructions-hint {
    margin-top: 0.75rem;
}
</style>
