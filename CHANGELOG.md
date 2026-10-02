# Changelog

All notable changes to `d3creative/statamic-darkroom` are documented here.

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Releases are git-tag driven
(`composer.json` carries no `version` field).

## [Unreleased]

### Added

- **Revise.** Pin numbered notes to spots on an image ("remove this door", "add a cityscape here"), add a message for the whole image, and send it back to the model for a new version. Available on unsaved images and in History. The original is left alone.
- **Revision threads.** The Revise panel stays open and shows every round with its notes and the image that came back. Revise from any round to go back and try again. The working area shows one card per thread, with Revisions (n) to reopen it. A saved round keeps the line of rounds that made it, so History can bring the feed back, and is marked Revised.
- **Rounds remember each other.** The model sees the earlier rounds, so a note can say "undo that". This keeps revision rounds on Google's side for up to 55 days; set `DARKROOM_REVISION_MEMORY=false` to keep nothing there. New images and upscales are never kept.

## [1.0.1] - 2026-10-02

### Changed

- The README no longer has a Testing section. Running the test suite is only needed to work on Darkroom itself.
- The README lists the requirement as Statamic 6 rather than a point release. Composer still enforces the exact version.

## [1.0.0] - 2026-10-02

First release.

### Added

- Generate images from the Control Panel with Nano Banana Pro, Nano Banana 2 or Nano Banana 2 Lite, using your own Google API key.
- Google's description of the chosen model is shown below the Model menu.
- Choose aspect ratio (Auto plus ten ratios), quality (1K, 2K, 4K, plus 0.5K on Nano Banana 2), file type (JPEG, WebP or PNG) and a batch size of 1 to 4.
- Estimated cost shown before generating.
- Preview each image, then save it with a filename and alt text into a folder chosen in Statamic's own asset browser, where folders can also be created. Or discard it.
- Filenames are slugified as they are typed or pasted.
- Images are saved at full size, through Statamic's normal upload path.
- Write alt text for an image with one click, using a Gemini text model on the same API key.
- Upscale any unsaved or saved image to a larger size, on any model, with the pixel size and price of each option shown.
- Reusable system instructions, with an optional default.
- Saved prompts that remember their settings.
- History tab listing every saved image, searchable by prompt, filename, alt text or system instruction, and paged like the Assets listing. Click an image to edit the asset in Statamic's own editor without leaving Darkroom, or reuse its prompt and settings, or upscale it.
- Trash. Tick images in History and move them to Trash, where they stay in the asset library and keep working until they are deleted after 30 days. Restore them, or delete them forever from the Trash tab, which appears only while it has anything in it. Darkroom checks whether an image is used on the site first, offers to keep it in the asset library instead, and never deletes a used image automatically.
- Spend tab with an estimated total per month, by model, and an itemised list. Each user sees their own images; the **See everyone's spend in Darkroom** permission shows everyone's.
- Generation and saving run after the response, so no queue worker is needed.
- An expired session says to reload and log in again.
- `darkroom:smoke` and `darkroom:prune` commands.
- Uninstall instructions in the README, including what Darkroom leaves behind.
