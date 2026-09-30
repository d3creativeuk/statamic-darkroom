# Changelog

## Unreleased

First version.

- Generate images from the Control Panel with Nano Banana Pro, Nano Banana 2 or Nano Banana 2 Lite, using your own Google API key.
- Choose aspect ratio (Auto plus ten ratios), quality (0.5K, 1K, 2K, 4K), file type (JPEG or WebP) and a batch size of 1 to 4.
- Upscale any unsaved or saved image to a larger size, on any model, with the pixel size and price of each option shown.
- Write alt text for an image with one click, using a Gemini text model on the same API key.
- Filenames are slugified as they are typed or pasted.
- Preview each image, then save it with a filename and alt text into a folder chosen in Statamic's own asset browser, where folders can also be created. Or discard it.
- Images are saved at full size, through Statamic's normal upload path.
- Reusable system instructions, with an optional default.
- Saved prompts that remember their settings.
- History tab listing every saved image with the prompt and settings that made it, and a button to reuse them.
- Spend tab with an estimated total per month, by model, and an itemised list.
- Estimated cost shown before generating.
- Generation and saving run after the response, so no queue worker is needed.
- `darkroom:smoke` and `darkroom:prune` commands.
