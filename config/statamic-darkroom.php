<?php

// Every aspect ratio Google documents for the Gemini 3 image models, in the
// order the menu shows them. "auto" sends no ratio and lets the model choose.
$aspectRatios = ['auto', '1:1', '3:4', '4:3', '2:3', '3:2', '9:16', '16:9', '5:4', '4:5', '21:9'];

// Pixel sizes Google returns, measured from real responses. 2K and 4K are
// exact multiples of 1K. 0.5K is not a clean half (it is rounded to steps of
// 16 pixels), so it has its own table. The same sizes came back from all
// three models.
$dimensions = [
    '512' => [
        '1:1' => [512, 512],
        '3:4' => [448, 592],
        '4:3' => [592, 448],
        '2:3' => [416, 624],
        '3:2' => [624, 416],
        '9:16' => [384, 688],
        '16:9' => [688, 384],
        '5:4' => [576, 464],
        '4:5' => [464, 576],
        '21:9' => [784, 336],
    ],
    '1K' => [
        '1:1' => [1024, 1024],
        '3:4' => [896, 1200],
        '4:3' => [1200, 896],
        '2:3' => [848, 1264],
        '3:2' => [1264, 848],
        '9:16' => [768, 1376],
        '16:9' => [1376, 768],
        '5:4' => [1152, 928],
        '4:5' => [928, 1152],
        '21:9' => [1584, 672],
    ],
];

return [

    /*
    |--------------------------------------------------------------------------
    | Google API
    |--------------------------------------------------------------------------
    |
    | The key is only ever read on the server. "interactions" is the API Google
    | recommends for new work; "generate_content" is the older endpoint, kept
    | as a fallback behind the same interface.
    |
    */

    'api_key' => env('GEMINI_API_KEY'),

    'api' => env('DARKROOM_API', 'interactions'),

    'base_url' => env('DARKROOM_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Keyed by Google's model ID. "qualities" maps each resolution the model
    | supports to its price per image in USD, which drives both the Quality
    | menu and the cost estimate. Adding or retiring a model is an edit here.
    |
    | "description" is shown under the Model menu. These are Google's own.
    |
    | "system_instruction" is "native" when the model honours the API's system
    | instruction field, or "prepend" when it ignores that field and the
    | instruction has to go ahead of the prompt instead.
    |
    | "dimensions" holds pixel sizes by quality and ratio. A quality without
    | its own table is worked out from 1K. Leave it out when unknown.
    |
    | "512" is the 0.5K size, for quick drafts. The API accepts it on all three
    | models, but only Nano Banana 2 charges less for it. Pro and Lite bill it
    | as 1K and were no faster at it (Pro about 20s, Lite about 3s at either
    | size), so it is only offered where it saves money.
    |
    */

    'default_model' => env('DARKROOM_MODEL', 'gemini-3-pro-image'),

    'models' => [

        'gemini-3-pro-image' => [
            'label' => 'Nano Banana Pro',
            'description' => 'State-of-the-art image generation and editing model.',
            'qualities' => ['1K' => 0.134, '2K' => 0.134, '4K' => 0.24],
            'aspect_ratios' => $aspectRatios,
            'system_instruction' => 'native',
            'dimensions' => $dimensions,
        ],

        'gemini-3.1-flash-image' => [
            'label' => 'Nano Banana 2',
            'description' => 'Pro-level visual intelligence with Flash-speed efficiency and reality-grounded generation capabilities.',
            'qualities' => ['512' => 0.045, '1K' => 0.0672, '2K' => 0.101, '4K' => 0.151],
            'aspect_ratios' => $aspectRatios,
            'system_instruction' => 'native',
            'dimensions' => $dimensions,
        ],

        'gemini-3.1-flash-lite-image' => [
            'label' => 'Nano Banana 2 Lite',
            'description' => 'Our smallest and most cost effective image generation and editing model, built for at scale usage.',
            'qualities' => ['1K' => 0.0336],
            'aspect_ratios' => $aspectRatios,
            'system_instruction' => 'prepend',
            'dimensions' => $dimensions,
        ],

    ],

    // Force one mode for every model. Leave null to use each model's own.
    'system_instruction_mode' => env('DARKROOM_SYSTEM_INSTRUCTION_MODE'),

    /*
    |--------------------------------------------------------------------------
    | Batches
    |--------------------------------------------------------------------------
    |
    | A batch of N is N separate requests sent at the same time. "concurrency"
    | caps how many are in flight at once.
    |
    */

    'batch' => [
        'max' => 4,
        'concurrency' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | What the form starts with. A null container means the first one the user
    | is allowed to upload to.
    |
    */

    'defaults' => [
        'aspect_ratio' => '16:9',
        'quality' => '2K',
        'file_type' => 'jpg',
        'container' => env('DARKROOM_CONTAINER'),
        'folder' => env('DARKROOM_FOLDER', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Saving
    |--------------------------------------------------------------------------
    |
    | Google only returns JPEG. Saving as JPEG keeps those bytes untouched;
    | WebP is converted on this server at "encode.quality". PNG is a lossless
    | copy of that JPEG: it adds no detail and is several times the size, but
    | some uses need the format.
    |
    | Images are stored at the size they were generated. Set
    | "apply_source_preset" to true to run the container's source preset (for
    | example a 2000px cap) on them like any other upload.
    |
    */

    'file_types' => [
        'jpg' => 'JPEG',
        'webp' => 'WebP',
        'png' => 'PNG',
    ],

    'encode' => [
        'quality' => 90,
    ],

    'save' => [
        'apply_source_preset' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Requests
    |--------------------------------------------------------------------------
    |
    | Generation runs after the HTTP response has been sent, so these limits
    | are about Google, not the browser. "deadline" is the total seconds one
    | batch may take, retries included, before it is reported as timed out.
    |
    */

    'timeout' => 180,

    'connect_timeout' => 10,

    'retry' => [
        'attempts' => 3,
        'backoff' => [2, 6],
        'max_retry_after' => 30,
        'deadline' => 300,
    ],

    // Raised for the background work only, and only if it is currently lower.
    'memory_limit' => '1024M',

    /*
    |--------------------------------------------------------------------------
    | Temporary storage
    |--------------------------------------------------------------------------
    |
    | Generated images wait here, outside the web root, until they are saved
    | or discarded. Anything older than "retention_hours" is pruned.
    |
    */

    'temp' => [
        'disk' => 'local',
        'path' => 'statamic-darkroom/batches',
        'retention_hours' => 24,
    ],

    'preview' => [
        'max_edge' => 1600,
        'quality' => 82,
    ],

    /*
    |--------------------------------------------------------------------------
    | Saved prompts and system instructions
    |--------------------------------------------------------------------------
    |
    | Plain YAML files. A null path means resources/addons/statamic-darkroom/,
    | which Statamic's Git integration already tracks.
    |
    */

    'prompts' => [
        'path' => null,
        'max_length' => 8000,
    ],

    'instructions' => [
        'path' => null,
        'max_length' => 8000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Upscaling
    |--------------------------------------------------------------------------
    |
    | An image is upscaled by sending it back to the model with this prompt and
    | a larger size. The model redraws it, so the composition holds but fine
    | detail such as small text can change. Nano Banana Pro is the most
    | faithful. The system instruction is not sent: the image already carries
    | the style, and an instruction would invite the model to reinterpret it.
    |
    */

    'upscale' => [
        'prompt' => 'Reproduce this exact image at a higher resolution. Keep the composition, subject, colours, textures, lettering and every detail identical. Do not add, remove, move or restyle anything.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Alt text
    |--------------------------------------------------------------------------
    |
    | Written by one of Google's text models, which can see images, using the
    | same API key. A few tenths of a cent each; the prices below are per
    | million tokens and only used for the Spend tab.
    |
    */

    'alt_text' => [
        'model' => env('DARKROOM_ALT_TEXT_MODEL', 'gemini-3.5-flash-lite'),
        'label' => 'Gemini 3.5 Flash-Lite',
        'input_price' => 0.30,
        'output_price' => 2.50,
        'timeout' => 60,
        'prompt' => "Write alt text for this image, for a web page. One sentence, under 125 characters, in British English. Describe what is actually shown, including any words in the image that matter. Do not start with 'Image of', 'Picture of' or similar. Reply with the alt text only.",
    ],

    /*
    |--------------------------------------------------------------------------
    | History and spend
    |--------------------------------------------------------------------------
    |
    | History lists the assets Darkroom has saved, newest first. The prompt
    | and settings are stored on each asset, so there is nothing to configure
    | beyond how many to show at a time.
    |
    | The usage log records every image Google returns, saved or not, with its
    | list price at the time. It is kept outside the temporary storage above
    | so pruning never touches it.
    |
    */

    'history' => [
        'per_page' => 12,
    ],

    'usage' => [
        'disk' => 'local',
        'path' => 'statamic-darkroom/usage',
    ],

    // Milliseconds between status checks while a batch is generating.
    'poll_interval' => 2000,

];
