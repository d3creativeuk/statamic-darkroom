# Changelog

All notable changes to `d3creative/statamic-darkroom` are documented here.

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Releases are git-tag driven
(`composer.json` carries no `version` field).

## [1.0.0] - 2026-09-30

First release.

### Added

- Generate images from the Control Panel with Nano Banana Pro, Nano Banana 2 or Nano Banana 2 Lite, using your own Google API key.
- Google's description of the chosen model is shown below the Model menu.
- Choose aspect ratio (Auto plus ten ratios), quality (1K, 2K, 4K, plus 0.5K on Nano Banana 2), file type (JPEG or WebP, with PNG behind `DARKROOM_PNG`) and a batch size of 1 to 4.
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
