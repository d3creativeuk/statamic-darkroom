<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head } from '@statamic/cms/inertia';
import {
    Alert,
    Button,
    Card,
    Description,
    Header,
    Heading,
    Panel,
    PanelHeader,
    TabContent,
    TabList,
    Tabs,
    TabTrigger,
    Textarea,
} from '@statamic/cms/ui';
import ControlsBar from '../../components/ControlsBar.vue';
import FolderPicker from '../../components/FolderPicker.vue';
import History from '../../components/History.vue';
import ResultsGrid from '../../components/ResultsGrid.vue';
import SavedPrompts from '../../components/SavedPrompts.vue';
import Spend from '../../components/Spend.vue';
import SystemInstructions from '../../components/SystemInstructions.vue';
import UpscaleDialog from '../../components/UpscaleDialog.vue';
import { qualityRank, usd } from '../../composables/format.js';
import { useGeneration } from '../../composables/useGeneration.js';
import { http } from '../../composables/useHttp.js';

const props = defineProps({
    configured: { type: Boolean, required: true },
    models: { type: Array, required: true },
    fileTypes: { type: Array, required: true },
    containers: { type: Array, required: true },
    defaults: { type: Object, required: true },
    limits: { type: Object, required: true },
    prompts: { type: Array, required: true },
    instructions: { type: Array, required: true },
    batches: { type: Array, required: true },
    history: { type: Object, required: true },
    usage: { type: Array, required: true },
    urls: { type: Object, required: true },
});

// The settings someone last used are worth keeping between visits. The prompt
// and the batch size are not: batch size always starts at 1, so a reload can
// never quietly multiply what the next click costs.
const REMEMBERED = ['model', 'aspectRatio', 'quality', 'fileType', 'container', 'folder'];
const STORAGE_KEY = 'darkroom.settings';

function remembered() {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY)) ?? {};
    } catch (e) {
        return {};
    }
}

const form = reactive({
    prompt: '',
    batchSize: 1,
    instruction: props.defaults.instruction,
    ...Object.fromEntries(REMEMBERED.map((key) => [key, props.defaults[key]])),
    ...Object.fromEntries(Object.entries(remembered()).filter(([key]) => REMEMBERED.includes(key))),
});

const promptList = ref(props.prompts);
const instructionList = ref(props.instructions);
const loadedPrompt = ref(null);
const submitting = ref(false);
const savingPrompt = ref(false);
const acting = ref(false);
const instructionsPanel = ref(null);
const composer = ref(null);

const tab = ref('history');
const saved = reactive({ ...props.history, loading: false });
const months = ref(props.usage);

async function refreshHistory() {
    try {
        Object.assign(saved, await http('GET', props.urls.history));
    } catch (e) {
        // The list on screen is only stale, not wrong. The next save or a
        // reload will bring it up to date.
    }
}

async function moreHistory() {
    saved.loading = true;

    try {
        const next = await http('GET', `${props.urls.history}?page=${saved.nextPage}`);

        saved.items = [...saved.items, ...next.items];
        saved.total = next.total;
        saved.nextPage = next.nextPage;
    } catch (e) {
        Statamic.$toast.error(e.message);
    } finally {
        saved.loading = false;
    }
}

async function refreshUsage() {
    try {
        months.value = (await http('GET', props.urls.usage)).months;
    } catch (e) {
        // As above: stale until the next refresh.
    }
}

const { batches, generating, generate, upscale, save, retry, discard, discardBatch } = useGeneration({
    initial: props.batches,
    url: props.urls.batches,
    upscaleUrl: props.urls.upscales,
    interval: props.limits.pollInterval,
    onChange(item, before) {
        // Google charges when the image comes back, saved or not.
        if (item.status === 'complete' && ['pending', 'generating'].includes(before)) {
            refreshUsage();
        }

        if (item.status === 'saved') {
            Statamic.$toast.success(__('Saved to :path', { path: item.asset?.path ?? __('assets') }));

            refreshHistory();
        }
    },
});

const model = computed(() => props.models.find((candidate) => candidate.id === form.model) ?? props.models[0]);

/**
 * Pull every setting back to something the chosen model and this site can
 * actually do. Runs on load, when the model changes and when a saved prompt
 * is loaded, since any of those can leave a value that no longer applies.
 */
function reconcile() {
    if (!props.models.some((candidate) => candidate.id === form.model)) {
        form.model = props.defaults.model;
    }

    const qualities = model.value.qualities.map((quality) => quality.value);

    if (!qualities.includes(form.quality)) {
        form.quality = qualities.includes(props.defaults.quality) ? props.defaults.quality : qualities[0];
    }

    if (!model.value.aspectRatios.includes(form.aspectRatio)) {
        form.aspectRatio = model.value.aspectRatios.includes(props.defaults.aspectRatio)
            ? props.defaults.aspectRatio
            : model.value.aspectRatios[0];
    }

    if (!props.fileTypes.some((type) => type.value === form.fileType)) {
        form.fileType = props.defaults.fileType;
    }

    if (!props.containers.some((container) => container.handle === form.container)) {
        form.container = props.defaults.container;
        form.folder = props.defaults.folder;
    }

    if (form.instruction && !instructionList.value.some((instruction) => instruction.id === form.instruction)) {
        form.instruction = null;
    }
}

reconcile();

watch(() => form.model, reconcile);

watch(
    () => REMEMBERED.map((key) => form[key]),
    () => {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(Object.fromEntries(REMEMBERED.map((key) => [key, form[key]]))));
        } catch (e) {
            // Private browsing, or storage is full. Nothing depends on it.
        }
    },
);

const price = computed(() => model.value.qualities.find((quality) => quality.value === form.quality)?.price ?? null);

const costNote = computed(() => {
    if (price.value === null) {
        return null;
    }

    const total = usd(price.value * form.batchSize);

    return form.batchSize > 1
        ? __('About :total USD for :n images at Google’s list price.', { total, n: form.batchSize })
        : __('About :total USD at Google’s list price.', { total });
});

const ready = computed(() => props.configured && props.containers.length > 0);
const canGenerate = computed(() => ready.value && form.prompt.trim() !== '' && !generating.value && !submitting.value);

const generateLabel = computed(() => {
    if (generating.value) {
        return __('Generating…');
    }

    return form.batchSize > 1 ? __('Generate :n images', { n: form.batchSize }) : __('Generate');
});

async function submit() {
    if (!canGenerate.value) {
        return;
    }

    submitting.value = true;

    try {
        // An instruction edited but not saved would otherwise be sent as its
        // old text, so it is saved first.
        await instructionsPanel.value?.flush();

        await generate({
            prompt: form.prompt,
            model: form.model,
            quality: form.quality,
            aspect_ratio: form.aspectRatio,
            batch_size: form.batchSize,
            file_type: form.fileType,
            container: form.container,
            folder: form.folder,
            instruction: form.instruction,
        });
    } catch (e) {
        Statamic.$toast.error(e.message);
    } finally {
        submitting.value = false;
    }
}

/**
 * Run something against a batch, surfacing any refusal as a toast.
 */
async function act(action, ...args) {
    acting.value = true;

    try {
        await action(...args);
    } catch (e) {
        Statamic.$toast.error(e.message);
    } finally {
        acting.value = false;
    }
}

// Images waiting on a folder. Where to save is chosen at save time, in
// Statamic's own asset browser, and remembered as the starting point for the
// next save.
const picker = reactive({ open: false, pending: [] });

function chooseFolder(entries) {
    picker.pending = entries;
    picker.open = true;
}

function saveTo({ container, folder }) {
    form.container = container;
    form.folder = folder;
    picker.open = false;

    picker.pending.forEach(([item, draft]) => act(save, item, { ...draft, container, folder }));
    picker.pending = [];
}

// The image waiting in the Upscale dialog: what it is, and how to point the
// server at it (an unsaved image by batch and index, or a saved one by asset).
const upscaling = reactive({ open: false, source: null, target: null });

/**
 * Whether any model can produce something larger than this quality. An image
 * with no recorded quality can always be tried.
 */
function canUpscale(quality) {
    const from = qualityRank(quality);

    return props.models.some((candidate) =>
        candidate.qualities.some((option) => from === null || qualityRank(option.value) > from),
    );
}

function askToUpscale(source, target) {
    upscaling.source = source;
    upscaling.target = target;
    upscaling.open = true;
}

async function confirmUpscale(choice) {
    upscaling.open = false;
    submitting.value = true;

    try {
        await upscale({ ...upscaling.target, ...choice });

        // The new image appears in the working area at the top of the results.
        composer.value?.$el?.scrollIntoView({ behavior: 'smooth', block: 'end' });
    } catch (e) {
        Statamic.$toast.error(e.message);
    } finally {
        submitting.value = false;
    }
}

function promptSettings() {
    return {
        prompt: form.prompt,
        model: form.model,
        instruction: form.instruction,
        aspect_ratio: form.aspectRatio,
        quality: form.quality,
        file_type: form.fileType,
        container: form.container,
        folder: form.folder,
    };
}

/**
 * Put a set of saved settings into the form. Shared by saved prompts and by
 * "Reuse prompt" in History, which hold the same things under the same names.
 */
function applySettings(settings) {
    const notices = [];

    // A model can be retired and an instruction deleted long after a prompt
    // was saved. Say so rather than silently using something else.
    if (settings.model && !props.models.some((candidate) => candidate.id === settings.model)) {
        notices.push(__('Its model is no longer available, so the default is selected.'));
    }

    if (settings.instruction && !instructionList.value.some((instruction) => instruction.id === settings.instruction)) {
        notices.push(__('Its system instruction has been deleted, so none is selected.'));
    }

    form.prompt = settings.prompt ?? '';
    form.model = settings.model ?? form.model;
    form.instruction = settings.instruction ?? null;
    form.aspectRatio = settings.aspect_ratio ?? form.aspectRatio;
    form.quality = settings.quality ?? form.quality;
    form.fileType = settings.file_type ?? form.fileType;
    form.container = settings.container ?? form.container;
    form.folder = settings.folder ?? '';

    reconcile();

    if (notices.length) {
        Statamic.$toast.info(notices.join(' '));
    }
}

function loadPrompt(prompt) {
    loadedPrompt.value = prompt?.id ?? null;

    if (prompt) {
        applySettings(prompt);
    }
}

function reuse(item) {
    loadedPrompt.value = null;

    applySettings({
        prompt: item.prompt,
        model: item.model,
        instruction: item.instruction,
        aspect_ratio: item.aspectRatio,
        quality: item.quality,
        file_type: item.fileType,
        container: item.container,
        folder: item.folder,
    });

    composer.value?.$el?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function persistPrompt(request, message) {
    savingPrompt.value = true;

    try {
        const response = await request();

        promptList.value = response.prompts;
        loadedPrompt.value = response.saved?.id ?? null;

        Statamic.$toast.success(message);
    } catch (e) {
        Statamic.$toast.error(e.message);
    } finally {
        savingPrompt.value = false;
    }
}

// A prompt remembers which system instruction it was saved with. An
// instruction that is still an unsaved draft has no id to remember, so it is
// saved first, the same as when generating.
const createPrompt = (name) =>
    persistPrompt(async () => {
        await instructionsPanel.value?.flush();

        return http('POST', props.urls.prompts, { name, ...promptSettings() });
    }, __('Prompt saved'));

const updatePrompt = () => {
    const current = promptList.value.find((prompt) => prompt.id === loadedPrompt.value);

    return persistPrompt(async () => {
        await instructionsPanel.value?.flush();

        return http('PATCH', `${props.urls.prompts}/${current.id}`, { name: current.name, ...promptSettings() });
    }, __('Prompt updated'));
};

const removePrompt = () =>
    persistPrompt(() => http('DELETE', `${props.urls.prompts}/${loadedPrompt.value}`), __('Prompt deleted'));

const suggestedPromptName = computed(() => form.prompt.trim().split(/\s+/).slice(0, 5).join(' ').slice(0, 120));
</script>

<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('Darkroom')" />

        <Header :title="__('Darkroom')" icon="ai-sparks" />

        <Alert
            v-if="!configured"
            class="dr-alert"
            variant="warning"
            :heading="__('No API key')"
            :text="__('Add GEMINI_API_KEY to your .env file to start generating images.')"
        />

        <Alert
            v-else-if="!containers.length"
            class="dr-alert"
            variant="warning"
            :heading="__('Nowhere to save images')"
            :text="__('You do not have permission to upload to any asset container.')"
        />

        <div class="dr-layout">
            <Panel ref="composer" class="dr-compose">
                <PanelHeader class="dr-compose-header">
                    <Heading>{{ __('Prompt') }}</Heading>

                    <SavedPrompts
                        :prompts="promptList"
                        :loaded="loadedPrompt"
                        :suggested-name="suggestedPromptName"
                        :can-save="form.prompt.trim() !== ''"
                        :busy="savingPrompt"
                        :disabled="!ready"
                        @load="loadPrompt"
                        @create="createPrompt"
                        @update="updatePrompt"
                        @remove="removePrompt"
                    />
                </PanelHeader>

                <Card>
                    <Textarea
                        v-model="form.prompt"
                        :rows="7"
                        :disabled="!ready"
                        :maxlength="limits.prompt"
                        :placeholder="__('Describe the image you want')"
                        @keydown.meta.enter="submit"
                        @keydown.ctrl.enter="submit"
                    />

                    <ControlsBar
                        v-model:model="form.model"
                        v-model:aspect-ratio="form.aspectRatio"
                        v-model:quality="form.quality"
                        v-model:batch-size="form.batchSize"
                        v-model:file-type="form.fileType"
                        :models="models"
                        :file-types="fileTypes"
                        :batch-max="limits.batchMax"
                        :disabled="!ready"
                    />

                    <div class="dr-generate">
                        <Description v-if="costNote">{{ costNote }}</Description>
                        <Button variant="primary" :loading="submitting" :disabled="!canGenerate" @click="submit">
                            {{ generateLabel }}
                        </Button>
                    </div>
                </Card>
            </Panel>

            <SystemInstructions
                ref="instructionsPanel"
                v-model="form.instruction"
                class="dr-instructions"
                :instructions="instructionList"
                :url="urls.instructions"
                :limit="limits.instruction"
                :prepended="model.prependsInstruction"
                :disabled="!configured"
                @update:instructions="instructionList = $event"
            />
        </div>

        <ResultsGrid
            v-for="batch in batches"
            :key="batch.id"
            :batch="batch"
            :file-types="fileTypes"
            :busy="acting"
            :can-upscale="ready && !generating && !submitting && canUpscale(batch.quality)"
            @upscale="(item) => askToUpscale({ quality: batch.quality, model: batch.model, aspectRatio: batch.aspectRatio }, { batch: batch.id, index: item.index })"
            @spent="refreshUsage"
            @save="(item, draft) => chooseFolder([[item, draft]])"
            @save-all="chooseFolder"
            @retry="(item) => act(retry, item)"
            @discard="(item) => act(discard, item)"
            @discard-batch="(target) => act(discardBatch, target)"
        />

        <Tabs v-model="tab" class="dr-archive">
            <TabList>
                <TabTrigger name="history" :text="saved.total ? __('History (:n)', { n: saved.total }) : __('History')" />
                <TabTrigger name="spend" :text="__('Spend')" />
            </TabList>

            <TabContent name="history">
                <Card class="dr-archive-card">
                    <History
                        :items="saved.items"
                        :total="saved.total"
                        :has-more="saved.nextPage !== null"
                        :loading="saved.loading"
                        :disabled="!ready || generating || submitting"
                        :can-upscale="canUpscale"
                        @reuse="reuse"
                        @upscale="(item) => askToUpscale({ quality: item.quality, model: item.model, aspectRatio: item.aspectRatio }, { asset: item.id })"
                        @more="moreHistory"
                    />
                </Card>
            </TabContent>

            <TabContent name="spend">
                <Card class="dr-archive-card">
                    <Spend :months="months" :url="urls.usage" />
                </Card>
            </TabContent>
        </Tabs>

        <FolderPicker
            v-model:open="picker.open"
            :containers="containers"
            :container="form.container"
            :folder="form.folder"
            :count="picker.pending.length"
            :folders-url="urls.folders"
            @choose="saveTo"
        />

        <UpscaleDialog
            v-model:open="upscaling.open"
            :models="models"
            :source="upscaling.source"
            :preferred="defaults.model"
            @confirm="confirmUpscale"
        />
    </div>
</template>

<style scoped>
.dr-alert {
    margin-bottom: 1.5rem;
}

.dr-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 0 1.5rem;
    align-items: start;
}

@media (min-width: 1100px) {
    .dr-layout {
        grid-template-columns: minmax(0, 2fr) minmax(18rem, 1fr);
    }
}

.dr-compose-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.dr-archive {
    margin-top: 0.5rem;
}

.dr-archive-card {
    margin-top: 1rem;
}

.dr-generate {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 1rem;
    margin-top: 1.25rem;
}
</style>
