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
import AssetViewer from '../../components/AssetViewer.vue';
import FolderPicker from '../../components/FolderPicker.vue';
import History from '../../components/History.vue';
import RemovalDialog from '../../components/RemovalDialog.vue';
import RevisionThread from '../../components/RevisionThread.vue';
import ResultsGrid from '../../components/ResultsGrid.vue';
import SavedPrompts from '../../components/SavedPrompts.vue';
import Spend from '../../components/Spend.vue';
import SystemInstructions from '../../components/SystemInstructions.vue';
import Trash from '../../components/Trash.vue';
import UpscaleDialog from '../../components/UpscaleDialog.vue';
import { qualityRank, usd } from '../../composables/format.js';
import { useGeneration } from '../../composables/useGeneration.js';
import { useRevisions } from '../../composables/useRevisions.js';
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
    trash: { type: Array, default: () => [] },
    trashDays: { type: Number, default: 30 },
    usage: { type: Array, required: true },
    usageIsEveryones: { type: Boolean, default: false },
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
const saved = reactive({ ...props.history, search: '', loading: false });
const months = ref(props.usage);
const binned = ref(props.trash);

// The Trash tab only exists while something is in it, so emptying it moves
// back to History.
watch(
    () => binned.value.length,
    (count) => {
        if (!count && tab.value === 'trash') {
            tab.value = 'history';
        }
    },
);

async function refreshTrash() {
    try {
        binned.value = (await http('GET', props.urls.trash)).items;
    } catch (e) {
        // Stale until the next refresh, as with History.
    }
}

function historyUrl(page, perPage) {
    const query = new URLSearchParams({ page, per_page: perPage });

    if (saved.search.trim()) {
        query.set('search', saved.search.trim());
    }

    return `${props.urls.history}?${query}`;
}

// Each search, page change or save landing asks again. Only the latest
// answer is kept, so a slow early reply cannot overwrite a newer one.
let historyRequest = 0;

/**
 * Load a page of History; by default the one on screen, so a save or an
 * edit refreshes it in place. The server moves back to the last page if
 * this one no longer exists.
 */
async function refreshHistory({ page = saved.meta.current_page, perPage = saved.meta.per_page, report = false } = {}) {
    const request = ++historyRequest;

    saved.loading = true;

    try {
        const fresh = await http('GET', historyUrl(page, perPage));

        if (request === historyRequest) {
            Object.assign(saved, fresh);
        }
    } catch (e) {
        // Unasked for, the list on screen is only stale, not wrong, and the
        // next save or a reload brings it up to date. Asked for, say so.
        if (report) {
            Statamic.$toast.error(e.message);
        }
    } finally {
        if (request === historyRequest) {
            saved.loading = false;
        }
    }
}

let searchTimer = null;

watch(
    () => saved.search,
    () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => refreshHistory({ page: 1 }), 300);
    },
);

function historyPage(page) {
    refreshHistory({ page, report: true });
}

// Kept as a user preference, as core's listings keep theirs, so the choice
// follows the user to the next visit and to other devices.
function historyPerPage(perPage) {
    Statamic.$preferences.set('darkroom.history.per_page', perPage);

    refreshHistory({ page: 1, perPage, report: true });
}

async function refreshUsage() {
    try {
        months.value = (await http('GET', props.urls.usage)).months;
    } catch (e) {
        // As above: stale until the next refresh.
    }
}

const { batches, generating, put, generate, upscale, revise, save, retry, discard, discardBatch } = useGeneration({
    initial: props.batches,
    url: props.urls.batches,
    upscaleUrl: props.urls.upscales,
    reviseUrl: props.urls.revisions,
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

// The saved image open in core's asset editor, if any. Anything can change in
// there (alt text, a rename, a delete), so History is read again on close.
const viewing = ref(null);

function openAsset(item) {
    // The editor needs the container's browser settings, which only exist
    // for containers the user can upload to. Anywhere else, fall back to the
    // asset's own page, in a new tab so this one stays put.
    if (!props.containers.some((container) => container.handle === item.container)) {
        window.open(item.editUrl, '_blank', 'noopener');

        return;
    }

    viewing.value = { id: item.id, container: item.container, folder: item.folder };
}

function assetClosed() {
    viewing.value = null;

    refreshHistory();
}

// Moving to the trash and deleting for good. Both ask first where the images
// are used, so nothing in use is removed without someone choosing that.
const removal = reactive({ open: false, mode: 'trash', ids: [], used: [] });

function nameOf(id) {
    const item = [...saved.items, ...binned.value].find((candidate) => candidate.id === id);

    return item?.basename ?? item?.path?.split('/').pop() ?? id;
}

async function usedAmong(ids) {
    const { usages } = await http('POST', props.urls.usages, { assets: ids });

    return ids.filter((id) => usages[id]?.length).map((id) => ({ id, name: nameOf(id), places: usages[id] }));
}

/**
 * Run one of the trash endpoints and report what happened. Anything the user
 * may not touch comes back refused rather than failing the rest.
 */
async function runRemoval(url, ids, done) {
    if (!ids.length) {
        return;
    }

    const result = await http('POST', url, { assets: ids });

    if (result.done.length) {
        Statamic.$toast.success(done(result.done.length));
    }

    if (result.refused.length) {
        Statamic.$toast.error(
            result.refused.length === 1
                ? __('1 image was left as it was. You may not have permission to change it.')
                : __(':n images were left as they were. You may not have permission to change them.', { n: result.refused.length }),
        );
    }
}

const moved = (n) => (n === 1 ? __('Moved 1 image to Trash.') : __('Moved :n images to Trash.', { n }));

async function trashImages(ids) {
    acting.value = true;

    try {
        const used = await usedAmong(ids);

        if (used.length) {
            Object.assign(removal, { open: true, mode: 'trash', ids, used });

            return;
        }

        await runRemoval(props.urls.trash, ids, moved);
        await Promise.all([refreshHistory(), refreshTrash()]);
    } catch (e) {
        Statamic.$toast.error(e.message);
    } finally {
        acting.value = false;
    }
}

async function destroyImages(ids) {
    acting.value = true;

    try {
        Object.assign(removal, { open: true, mode: 'destroy', ids, used: await usedAmong(ids) });
    } catch (e) {
        Statamic.$toast.error(e.message);
    } finally {
        acting.value = false;
    }
}

async function restoreImages(ids) {
    acting.value = true;

    try {
        await runRemoval(props.urls.restore, ids, (n) => (n === 1 ? __('Restored 1 image to History.') : __('Restored :n images to History.', { n })));
        await Promise.all([refreshHistory(), refreshTrash()]);
    } catch (e) {
        Statamic.$toast.error(e.message);
    } finally {
        acting.value = false;
    }
}

async function removalChosen(choice) {
    const { mode, ids, used } = removal;
    const usedIds = used.map((image) => image.id);

    removal.open = false;
    acting.value = true;

    try {
        if (mode === 'destroy') {
            await runRemoval(props.urls.destroy, ids, (n) => (n === 1 ? __('Deleted 1 image.') : __('Deleted :n images.', { n })));
        } else if (choice === 'keep') {
            await runRemoval(props.urls.forget, usedIds, (n) =>
                n === 1 ? __('Kept 1 image in the asset library. Darkroom no longer lists it.') : __('Kept :n images in the asset library. Darkroom no longer lists them.', { n }),
            );
            await runRemoval(props.urls.trash, ids.filter((id) => !usedIds.includes(id)), moved);
        } else {
            await runRemoval(props.urls.trash, ids, moved);
        }

        await Promise.all([refreshHistory(), refreshTrash()]);
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

// The Revise panel and the thread of rounds it is working through.
const revisions = useRevisions({ batches, put, revise, urls: props.urls });

// The working area: one card per revision thread, its newest round that was
// not discarded or failed, and none once that round is saved (it is in
// History then). Everything else shows as it is, newest first.
const working = computed(() => {
    const settled = new Set();

    return [...batches.value]
        .sort((a, b) => b.createdAt - a.createdAt)
        .filter((batch) => {
            const thread = batch.thread?.id;

            if (!thread) {
                return true;
            }

            const status = batch.items[0]?.status;

            if (settled.has(thread) || ['discarded', 'failed'].includes(status)) {
                return false;
            }

            settled.add(thread);

            return status !== 'saved';
        });
});

function imageBase(batch, item) {
    return {
        key: batch.thread ? `round:${batch.id}` : 'origin',
        name: item.filename,
        image: item.status === 'complete' ? item.urls.preview : null,
        model: batch.model,
        quality: batch.quality,
        aspectRatio: batch.aspectRatio,
        target: item.status === 'complete' ? { batch: batch.id, index: item.index } : null,
    };
}

function reviseImage(batch, item = batch.items[0]) {
    revisions.open({ base: imageBase(batch, item), threadId: batch.thread?.id ?? null });
}

function reviseSaved(item) {
    revisions.open({
        base: {
            key: 'asset',
            name: item.path.split('/').pop(),
            image: item.preview,
            model: item.model,
            quality: item.quality,
            aspectRatio: item.aspectRatio,
            target: { asset: item.id },
        },
        threadId: item.thread ?? null,
        asset: item.thread ? item.id : null,
    });
}

async function sendRound(changes) {
    submitting.value = true;

    try {
        await revisions.send(changes);
    } catch (e) {
        Statamic.$toast.error(e.message);
    } finally {
        submitting.value = false;
    }
}

// Saving from the feed uses the image's suggested filename; the card in the
// working area is there for choosing a name and writing alt text first.
function saveRound(round) {
    chooseFolder([[round.item, { filename: round.item.filename, alt: '', file_type: round.batch.fileType }]]);
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
            v-for="batch in working"
            :key="batch.id"
            :batch="batch"
            :file-types="fileTypes"
            :busy="acting"
            :can-upscale="ready && !generating && !submitting && canUpscale(batch.quality)"
            :can-revise="ready && !generating && !submitting"
            @upscale="(item) => askToUpscale({ quality: batch.quality, model: batch.model, aspectRatio: batch.aspectRatio }, { batch: batch.id, index: item.index })"
            @revise="(item) => reviseImage(batch, item)"
            @thread="reviseImage(batch)"
            @spent="refreshUsage"
            @save="(item, draft) => chooseFolder([[item, draft]])"
            @save-all="chooseFolder"
            @retry="(item) => act(retry, item)"
            @discard="(item) => act(discard, item)"
            @discard-batch="(target) => act(discardBatch, target)"
        />

        <Tabs v-model="tab" class="dr-archive">
            <TabList>
                <TabTrigger name="history" :text="saved.all ? __('History (:n)', { n: saved.all }) : __('History')" />
                <TabTrigger name="spend" :text="__('Spend')" />
                <TabTrigger v-if="binned.length" name="trash" :text="__('Trash (:n)', { n: binned.length })" />
            </TabList>

            <TabContent name="history">
                <Card class="dr-archive-card">
                    <History
                        v-model:search="saved.search"
                        :items="saved.items"
                        :all="saved.all"
                        :meta="saved.meta"
                        :loading="saved.loading"
                        :disabled="!ready || generating || submitting || acting"
                        :can-upscale="canUpscale"
                        @reuse="reuse"
                        @open="openAsset"
                        @upscale="(item) => askToUpscale({ quality: item.quality, model: item.model, aspectRatio: item.aspectRatio }, { asset: item.id })"
                        @revise="reviseSaved"
                        @page="historyPage"
                        @per-page="historyPerPage"
                        @trash="trashImages"
                    />
                </Card>
            </TabContent>

            <TabContent v-if="binned.length" name="trash">
                <Card class="dr-archive-card">
                    <Trash :items="binned" :days="trashDays" :disabled="acting" @restore="restoreImages" @destroy="destroyImages" />
                </Card>
            </TabContent>

            <TabContent name="spend">
                <Card class="dr-archive-card">
                    <Spend :months="months" :url="urls.usage" :everyones="usageIsEveryones" />
                </Card>
            </TabContent>
        </Tabs>

        <AssetViewer :containers="containers" :asset="viewing" @closed="assetClosed" />

        <RemovalDialog
            v-model:open="removal.open"
            :mode="removal.mode"
            :count="removal.ids.length"
            :used="removal.used"
            :days="trashDays"
            @choose="removalChosen"
        />

        <FolderPicker
            v-model:open="picker.open"
            :containers="containers"
            :container="form.container"
            :folder="form.folder"
            :count="picker.pending.length"
            :folders-url="urls.folders"
            @choose="saveTo"
        />

        <RevisionThread
            v-model:open="revisions.state.open"
            :models="models"
            :base="revisions.state.base"
            :origin="revisions.state.origin"
            :prompt="revisions.state.prompt"
            :rounds="revisions.rounds.value"
            :loading="revisions.state.loading"
            :busy="generating || submitting || acting || revisions.busy.value"
            :preferred="defaults.model"
            @send="sendRound"
            @choose="revisions.choose"
            @save="saveRound"
            @discard="(round) => act(discard, round.item)"
            @retry="(round) => act(retry, round.item)"
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
