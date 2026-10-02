# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Statamic 6 addon (Darkroom) that generates images with Google's Nano Banana models from the Control Panel and saves them as assets. One CP page, registered as an Inertia page, backed by JSON endpoints. In the sidebar it is a child of Tools → Addons, beside the settings links Statamic adds for other addons; core only creates that parent for users who can `configure addons`, so everyone else gets a top-level Tools item instead (`ServiceProvider::bootAddon`).

This repo is the standalone addon (PHP + compiled JS). The host Statamic site used for development is the sibling project at `../d3creative`, and `package.json` resolves `@statamic/cms` via `file:../d3creative/vendor/statamic/cms/resources/dist-package`.

**The host is linked by a Composer path repository** (`../statamic-darkroom`, symlinked), so PHP changes here are live in the host immediately. That `repositories` entry and the `@dev` requirement in the host's `composer.json` must not be committed or deployed: production has no sibling directory. Switch the host to a tagged release first.

## Build and publish

```bash
npm install            # one-time
npm run build          # vite production build to public/build/
npm run dev            # vite build --watch (no HMR)
```

`npm run build` writes only to this addon's `public/build/`. The host serves a **copy** at `d3creative/public/vendor/statamic-darkroom/build/`. After every build, republish from the host:

```bash
cd ../d3creative && php artisan vendor:publish --tag=statamic-darkroom --force
```

Use `--tag`, not `--provider`: the provider form would also force-overwrite a published config. Publishing does not remove old hashed files, so delete any `addon-*.js` / `addon-*.css` in the host copy that the manifest no longer lists.

## Tests

```bash
composer install
vendor/bin/phpunit
```

`tests/TestCase.php` extends Statamic's `AddonTestCase`. No test reaches Google: `Http::preventStrayRequests()` is on, and responses come from `tests/__fixtures__/api/*.json`, which are real responses captured on 30 September 2026 with the image data swapped for an 8x8 JPEG.

Things that will bite when writing tests:

- **Set addon config after boot** (`config()->set()` in `setUp`), not in `getEnvironmentSetUp`. Statamic merges the addon config one level deep, so setting a nested key before the merge replaces that whole branch of the defaults.
- **Jobs run inside the request.** `dispatchAfterResponse()` fires when the test kernel terminates, so by the time `postJson()` returns, generation or saving has already finished. The response body itself still shows the state before the job ran.
- **`Http::fake` does not record a failed connection**, so `assertSentCount` undercounts. Assert on `Sleep` instead.
- **Use `now()`, not `time()`,** anywhere a test needs to travel through time. `BatchStore` does.
- `generate_presets_on_upload` is switched off in `getEnvironmentSetUp` because Statamic's listener reads it when it subscribes.

The Vue side has no tests. Verify it in the host CP.

## Architecture

- **`src/Api`**: `ImageGenerator` interface, two drivers behind `AbstractGeminiClient` (which owns auth, concurrency via `Http::pool`, retries and error mapping). `InteractionsClient` is the default; `GenerateContentClient` is the fallback (`DARKROOM_API=generate_content`). `generateMany()` never throws per image: each key comes back as an `ImageResult` or a `DarkroomException`.
- **`src/Models/ModelRegistry`**: everything that depends on the chosen model (menus, validation, price, how a system instruction is delivered) reads the `models` config through this.
- **`src/Generations`**: `BatchStore` keeps unsaved images on the `local` disk under `statamic-darkroom/batches/{ulid}/`. `batch.json` is written once; each image has its own `item-N.json` so two PHP workers finishing at once cannot overwrite each other. Stale in-progress items are settled on read (`expire()`). `BatchPresenter` shapes a batch for the page and builds its URLs.
- **`src/Jobs`**: `GenerateBatch` and `SaveItem`, both dispatched with `dispatchAfterResponse()` so no queue worker is needed and no web server timeout applies. Neither throws; outcomes are written to the item's status.
- **`src/Assets/AssetSaver`**: saves through `$container->makeAsset($path)->upload($file)`.
- **`src/Support/YamlStore`**: base for `PromptStore` and `InstructionStore`, writing `resources/addons/statamic-darkroom/*.yaml` in the host.
- **`src/History/SavedImages`**: the History tab. There is no history store: `SaveItem` writes a `darkroom` key (prompt, model, quality, aspect ratio, instruction id and title, time, user) onto the asset, and this queries each viewable container for assets that have one. Statamic's asset query builder works on one container at a time, hence the loop and the sort in PHP. Search is a word-by-word filter in PHP over prompt, path, alt and instruction title; the response carries `total` (matches), `all` (everything, for the tab label) and a Laravel-resource-shaped `meta` that core's `<Pagination>` reads. Page size is limited to `statamic.cp.pagination_size` and `pagination_size_options`, so the Per Page menu always contains the current value; the choice is the `darkroom.history.per_page` user preference, set client-side with `Statamic.$preferences` as core listings do and read server-side with `Preference::get` for the first render. Fine at hundreds of images; at thousands, this is the place to add an index.
- **`src/Usage/UsageLog`**: the Spend tab. One JSON Lines file per month under `storage/app/statamic-darkroom/usage/`, appended with `LOCK_EX`. `GenerateBatch` records an entry the moment Google returns an image, before anything else can fail, because that is when it is charged. `record()` never throws.
- **`resources/js`**: `pages/darkroom/Index.vue` owns the form; `composables/useGeneration.js` owns batches and polling; components are presentational.

## Decisions worth knowing before changing things

- **Full-size saves rely on omitting the client MIME type.** `Statamic\Assets\Uploader::processSourceFile()` only applies a container's `source_preset` when the `UploadedFile` carries an image MIME type. Core's own `AssetsController::crop()` omits it for the same reason. `SaveTest::the_image_keeps_its_full_size_even_when_the_container_caps_uploads` guards this; if it fails after a Statamic update, core has changed how it decides.
- **Prompts and instructions are not addon settings.** `Statamic\Addons\Settings` runs every string through `Antlers::parse()`, which would evaluate any `{{ }}` in a prompt.
- **Batch size is never stored** with a saved prompt or remembered between visits, so nothing can silently multiply the cost of a click.
- **The folder is chosen at save time, in core's own asset browser.** `FolderPicker.vue` mounts the globally registered `<asset-browser>` in a `Stack`, the way core's asset selector does, so navigation, breadcrumbs and Create Folder are core's. `Destinations::forFrontend()` builds its container data and columns by calling the core Assets fieldtype's `preload()`, with `can_upload` forced off. The save request carries `container` and `folder`; the batch's own folder is only where the picker opens. Core's asset search matches file paths only and never lists folders, so the picker adds its own folder matches above the results, from `darkroom.folders` (read fresh when the drawer opens and when a search starts, so a folder created seconds ago is found). Choosing a match clears core's search box by sending it the Escape key its own handler listens for; the box keeps its text inside the listing, and setting the browser's `searchQuery` does not clear it. (Statamic's `asset_folder` fieldtype was rejected earlier: outside an entry form it ignores `mode: select`.)
- **A saved image leaves the working grid.** `ResultsGrid` hides `saved` items; they show in History instead, and the page refreshes History and Spend from `useGeneration`'s `onChange` callback.
- **Spend is per user** unless the viewer has `view all darkroom spend` (`UsageLog::VIEW_ALL`, a child of `use darkroom`; super users pass the Gate). The itemised list shows prompts, and History already hides images from containers a user cannot view, so Spend must not show everyone's by default. `darkroom:smoke` entries have no user and only appear in the everyone view.
- **Folders containing a `..` segment are rejected at validation** (`Destinations::folderRule()`). `safeFolder()` keeps `..`; Flysystem would refuse a path out of the container, but only after the save had been queued and failed with a raw "Path traversal detected".
- **Spend entries are appended in time order** and read back reversed, so never write an out-of-order line into a month file.
- **PNG is offered by default.** It was briefly opt-in behind `DARKROOM_PNG`, then made standard on request: it is converted from Google's JPEG, adds no detail and is several times the size, but some uses need the format. `file_types` in config is the only list of what is offered.
- **0.5K is offered on Nano Banana 2 only.** Pro and Lite accept `512` but bill it as 1K and were no faster (Pro about 20s, Lite about 3s at either size), so it was dropped from their `qualities`. Their `dimensions` still carry the 0.5K table, which is harmless.
- **Model descriptions are Google's own text**, from the `description` key in each model's config, shown below the Model menu.
- **Upscaling is a second kind of batch** (`kind: upscale`, made by `UpscaleController`). The source image is copied into the batch as `source.bin` when it is created, so discarding or pruning the original cannot strand the job. `GenerateBatch` swaps in the fixed prompt from `upscale.prompt`, attaches the image and drops the system instruction, while the batch keeps the original prompt so History and "Reuse prompt" still describe the picture. The target must rank above the source (`ModelRegistry::rank`).
- **Alt text comes from a text model, not the image model** (`Api\AltTextWriter`, `alt_text.model`, default `gemini-3.5-flash-lite`). It runs inside the request because it takes 2 to 6 seconds, uses the 1600px preview rather than the original, and is logged in Spend with `kind: alt_text`, priced from the token counts in the response. `UsageLog` counts those separately from images.
- **All three models share one `dimensions` table** in config, keyed by quality then ratio. 2K and 4K are scaled from 1K exactly. **0.5K is not a clean half** (it is rounded to steps of 16: 4:3 is 592 × 448, not 600 × 448), so it has its own measured table. Every size returned in testing matched, from all three models.
- **A quality's API value and its label differ for one size.** The API says `512`; people see 0.5K (`ModelRegistry::qualityLabel`, `qualityLabel()` in `format.js`). PHP turns the config key `'512'` into an integer, so `qualities()` casts back to strings.
- **History opens assets in core's editor without leaving the page.** Core does not register its asset editor for addons, but `<asset-browser>` opens it for whatever `initial-editing-asset-id` it is given. `AssetViewer.vue` mounts the browser inside a hidden element with that prop; the editor is a Stack, so it portals out and shows normally. The browser emits `navigated` with a path ending in `/edit` while editing and without it on close, which is when the viewer unmounts and History is reloaded (alt text, renames and deletes all show up). Containers the user cannot upload to have no browser data, so those fall back to the asset's own page in a new tab.
- **An unsaved system instruction is saved automatically** before generating or saving a prompt (`SystemInstructions.vue` exposes `flush()`), because the server sends the saved text, not what is on screen.

## What Google's API actually does

Verified with real calls on 30 September 2026. The official docs contradict each other on several of these.

- Model IDs: `gemini-3-pro-image`, `gemini-3.1-flash-image`, `gemini-3.1-flash-lite-image`. The `-preview` IDs are shut down.
- **Output is always JPEG.** Asking for PNG is rejected with `invalid_request`.
- Interactions response: `steps[]`, skip `type: "thought"` (its `signature` alone is megabytes), take the image from the `model_output` step.
- generateContent: `generationConfig.imageConfig` works. The newer `responseFormat.image` block rejects `"1K"` and `"16:9"` and wants enum names.
- Pixel sizes match the `dimensions` table in config. 0.5K, 2K and 4K are exact multiples of 1K.
- **`512` (0.5K) is accepted by all three models**, though the docs list it for Nano Banana 2 only. `0.5K` as a value is rejected. Output tokens show the billing: Nano Banana 2 charges 747 tokens for it, while Pro and Lite charge 1120, the same as 1K.
- **Nano Banana 2 Lite is limited to 1K by Google.** 2K and 4K are refused: Interactions answers 404 "Requested entity was not found", generateContent answers 400 "Image size 2K is not supported for this model".
- **Expired sessions** come back as 401 "Unauthenticated." or 419; `useHttp.js` replaces those with a plain message to reload and log in, since the page uses `fetch` and so bypasses core's own session handling.
- **Upscaling works by image input.** Send the image with a "reproduce this exactly" prompt and a larger size. Both endpoints accept it (`input` parts on Interactions, `inlineData` on generateContent). It is a redraw: composition holds, small text can change. Pro preserved lettering that Nano Banana 2 altered. An input image costs a fraction of a cent.
- **Auto** (no aspect ratio) lets the model choose; it is not always 1:1.
- **System instructions:** Pro and Nano Banana 2 obey the native field even when the prompt contradicts it. **Lite ignores it**, hence `system_instruction => 'prepend'` for that model.
- Six concurrent requests were accepted without rate limiting. Latency: Lite about 5s, Nano Banana 2 about 11s, Pro 16 to 36s.
- Blocked-generation response shapes were **not** captured live. The clients match any reason containing safety/prohibited/blocked/recitation and keep a redacted copy of the response in the log.

## Statamic 6 gotchas

- Config file must be named after the addon slug: `config/statamic-darkroom.php`, read as `config('statamic-darkroom.*')`.
- `AssetContainer` has no `allowUploads()` or `createFolders()` in v6. Calling them falls through to augmentation and throws. Use `Gate::forUser($user)->allows('store', [AssetContract::class, $container])`.
- A Statamic user has no `can()` method for the same reason.
- `vite.config.js` must use `@statamic/cms/vite-plugin` so Vue is shared with the CP.
- **Only Tailwind classes already in the CP bundle work.** Custom layout goes in `<style scoped>`, which Vite emits as a CSS file listed in the manifest and Statamic loads automatically. Colours use `currentColor` and `color-mix` so they follow light and dark mode.
- Axios is not exported to addons. `composables/useHttp.js` uses `fetch` with the CSRF token from `Statamic.$config.get('csrfToken')`.
- Icons come from Statamic's set by name. There is a `plus` but no `minus`; check `resources/svg/icons/` in the host before using a name.
- Register CP pages inside `Statamic.booting()`; the CP layout is applied automatically.
