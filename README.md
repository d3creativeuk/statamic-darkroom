# Darkroom

Generate images with Google's Nano Banana models from the Statamic Control Panel and save them straight into your asset library.

Darkroom uses your own Google API key. You write a prompt, pick a model, aspect ratio, quality and folder, preview what comes back, and save the ones you want as normal Statamic assets.

![Darkroom by D3 Creative](art/darkroom-art.jpg)

## Requirements

- Statamic 6.34 or later
- PHP 8.3 or later
- A Google Gemini API key on a project with billing enabled. Google's image models have no free tier.

## Installation

```bash
composer require d3creative/statamic-darkroom
```

Add your key to `.env`:

```
GEMINI_API_KEY=your-key-here
```

Darkroom then appears in the Control Panel sidebar under **Tools → Addons**, alongside your other addons. Users who cannot configure addons do not have that menu, so for them it appears directly under **Tools**.

The key is only ever read on the server. It is never sent to the browser.

## Permissions

Super users can use Darkroom straight away. Everyone else needs two permissions:

- **Generate images with Darkroom** (`use darkroom`)
- Permission to upload to at least one asset container

Only containers a user may upload to are offered as destinations.

## Using it

| Control | What it does |
|---|---|
| Model | Nano Banana Pro, Nano Banana 2 or Nano Banana 2 Lite |
| Aspect ratio | Auto, 1:1, 3:4, 4:3, 2:3, 3:2, 9:16, 16:9, 5:4, 4:5 or 21:9. Auto lets the model choose |
| Quality | 0.5K, 1K, 2K or 4K, with the pixel size shown where it is known. Lite produces 0.5K and 1K only |
| File type | JPEG or WebP |
| Batch size | 1 to 4 images from the same prompt. Always starts at 1 |

The estimated cost is shown next to the Generate button before you spend anything.

Generated images are held in temporary storage and shown as previews. For each one you can set the filename, alt text and file type, then **Save to folder…** or **Discard**. Filenames are made URL-safe as you type or paste: lowercase, with spaces and punctuation turned into hyphens. Nothing reaches your asset library until you save it. If you reload the page, unsaved images are still there.

### Choosing where to save

**Save to folder…** opens Statamic's own asset browser in a panel, laid out like the drawer core uses when you pick an asset: the same search, folders, breadcrumbs and **Create Folder** as the Assets section. Go to the folder you want, or create one, then **Save here**. Core's search matches files only, so as you type, any folders whose names match are also offered above the results, including one you created a moment ago that is still empty; click one to go straight into it. **Save all** on a batch asks once and saves every unsaved image in it to the same place. The browser opens on wherever you saved last.

### Alt text

**Write** next to the alt text box asks one of Google's text models to describe the image, using the same API key. It sees the image and the prompt that made it, and replies with one plain sentence in British English, under 125 characters, including any words in the image that matter. It takes a few seconds and costs a few hundredths of a cent. Edit the suggestion before saving if it needs it; **Rewrite** asks again.

The model is `gemini-3.5-flash-lite` by default. Change `alt_text.model` to use another.

### Drafting small, then upscaling

0.5K is for quick drafts: a 16:9 image comes back at 688 × 384 in a few seconds. When one is worth keeping, **Upscale** sends it back to the model and asks for the same image at a larger size. Upscale is on every unsaved image and on every image in History, so you can also enlarge something you saved earlier.

You choose the model and the target size, with the pixel size and the cost shown for each. The result appears as a new image to preview and save; the one it came from is left alone.

Two things to know:

- **It is a redraw, not an enlargement.** The composition, subject and colours hold, but fine detail can shift, small lettering in particular. In testing, Nano Banana Pro kept text and detail intact where Nano Banana 2 altered some of it, so Pro is suggested by default.
- **0.5K only saves money on Nano Banana 2.** Pro and Lite accept the size but bill it as a 1K image. The cheapest draft of all is Nano Banana 2 Lite.

The system instruction is not sent with an upscale. The image already carries the style.

### History

Once an image is saved it moves to the **History** tab: every image Darkroom has saved, newest first, with the prompt, model, aspect ratio and quality that made it. **Reuse prompt** puts all of that back into the form.

The history is not a separate log. The prompt and settings are stored on the asset itself, under a `darkroom` key in its metadata. So the history follows your assets wherever they are synced, editing an asset later keeps it, and deleting an asset removes it from the list. You only see images from containers you are allowed to view.

### Spend

The **Spend** tab shows an estimated total for each month, broken down by model, with a list of every image behind it. Alt text calls are included in the total and listed separately.

Google charges when an image is generated, so every image it returns is counted, including upscales, ones you went on to discard and ones made with `darkroom:smoke`. Images that failed are not counted. Each is recorded at the list price in your config at the time.

These are estimates in US dollars. Your Google invoice is the real figure: it also counts the small number of text tokens in each request, and prices can change before the config is updated.

### System instructions

Tone and style notes that are sent along with every image, such as a house illustration style or a colour palette. Save as many as you like, give each a title, and mark one as the default to have it selected whenever the page loads.

Nano Banana Pro and Nano Banana 2 honour system instructions directly. Nano Banana 2 Lite ignores them, so for that model Darkroom places the instruction at the start of the prompt instead. The page tells you when this applies.

### Saved prompts

Save a prompt together with its model, aspect ratio, quality, file type, folder and system instruction, then load it again later. Batch size is never saved with a prompt, so loading one cannot multiply what the next click costs.

Saved prompts and system instructions are stored as YAML in `resources/addons/statamic-darkroom/`, so they can be committed with the rest of your site.

## What gets saved

- **Full size.** Images are stored at the size they were generated. A container's source preset (for example a 2000px cap on uploads) is not applied to them. Set `save.apply_source_preset` to `true` to change that.
- **JPEG is untouched.** Google returns JPEG. Saving as JPEG keeps those exact bytes with no re-compression. WebP is converted on your server.
- **Like any other upload.** Saving goes through Statamic's own upload path, so filenames are made safe, a name that is already taken gets a suffix instead of overwriting, the usual asset events fire and Glide presets are warmed.

Full-size sources are large. A 4K JPEG is around 9 MB.

## Prices

Google bills per image, in US dollars. These are the list prices Darkroom ships with, correct on 30 September 2026. Check [Google's pricing page](https://ai.google.dev/gemini-api/docs/pricing) and update the config if they change.

| Model | 0.5K | 1K | 2K | 4K |
|---|---|---|---|---|
| Nano Banana Pro | $0.134 | $0.134 | $0.134 | $0.24 |
| Nano Banana 2 | $0.045 | $0.0672 | $0.101 | $0.151 |
| Nano Banana 2 Lite | $0.0336 | $0.0336 | not available | not available |

A batch costs the price of one image multiplied by the batch size. An upscale costs the price of one image at the size you upscale to. The figure shown in the Control Panel is an estimate from these numbers, not a bill.

## Configuration

Publish the config file to change anything:

```bash
php artisan vendor:publish --tag=statamic-darkroom-config
```

| Key | Default | Purpose |
|---|---|---|
| `api_key` | `GEMINI_API_KEY` | Your Google API key |
| `default_model` | `gemini-3-pro-image` | The model selected on first visit. Also `DARKROOM_MODEL` |
| `models` | three models | Each model's label, qualities with prices, aspect ratios and how it takes a system instruction. Add or retire a model here |
| `batch.max` | `4` | Largest batch allowed |
| `defaults` | 16:9, 2K, JPEG | What the form starts with, including a default container and folder |
| `file_types` | JPEG, WebP | The file types offered when saving |
| `encode.quality` | `90` | Compression used when converting to WebP |
| `save.apply_source_preset` | `false` | Run the container's source preset on saved images |
| `temp.retention_hours` | `24` | How long unsaved images are kept |
| `history.per_page` | `12` | How many saved images History shows at a time |
| `upscale.prompt` | see config | The instruction sent with an image when upscaling it |
| `alt_text.model` | `gemini-3.5-flash-lite` | The text model that writes alt text. Also `DARKROOM_ALT_TEXT_MODEL` |
| `alt_text.prompt` | see config | What that model is asked to write |
| `api` | `interactions` | Which Google endpoint to use. `generate_content` is the older one, kept as a fallback. Also `DARKROOM_API` |

## How generation runs

An image takes anywhere from five seconds to a minute, which is too long for a normal page request. Darkroom answers the request immediately, generates after the response has been sent, and the page checks back every couple of seconds. This needs no queue worker.

If Google is overloaded or rate limited, each image is tried up to three times. A rejected request, a bad key or a blocked prompt is not retried, and the reason is shown on the image's card.

Saving works the same way, because warming Glide presets from a large source can take a while.

Unsaved images live in `storage/app/statamic-darkroom/batches/`, outside the web root, and are private to the user who generated them. Anything older than the retention period is removed whenever the page is opened, and daily by `darkroom:prune` if your site runs Laravel's scheduler.

The spend log is kept separately in `storage/app/statamic-darkroom/usage/`, one file per month, and is never pruned. It is not in Git, so each environment keeps its own.

## Commands

```bash
# Generate one real image to check your key and connection. This costs money.
php artisan darkroom:smoke "a red bicycle" --model=gemini-3.1-flash-lite-image

# Remove unsaved images older than the retention period.
php artisan darkroom:prune
```

## Good to know

- Google's documentation states that every generated image carries an invisible SynthID watermark. It cannot be turned off.
- Your prompts and images are handled under Google's Gemini API terms, not by D3 Creative. Nothing is sent anywhere else.
- Apart from upscaling, generating from a reference image is not supported yet.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The tests never contact Google. Responses are faked from fixtures captured from the real API.

## Licence

MIT. Built by [D3 Creative](https://d3creative.uk).
