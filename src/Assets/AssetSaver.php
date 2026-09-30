<?php

namespace D3Creative\Darkroom\Assets;

use D3Creative\Darkroom\Imaging\ImageEncoder;
use Facades\Statamic\Fields\Validator as FieldValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\AssetContainer;
use Statamic\Rules\AllowedFile;

/**
 * Turns a generated image into a real Statamic asset.
 */
class AssetSaver
{
    /**
     * @param  array<string, mixed>  $config  The statamic-darkroom config array.
     */
    public function __construct(protected ImageEncoder $encoder, protected array $config) {}

    public function save(
        string $binary,
        string $sourceMime,
        string $container,
        string $folder,
        string $filename,
        string $fileType,
        ?string $alt = null,
        array $data = [],
    ): AssetContract {
        $assetContainer = AssetContainer::findByHandle($container)
            ?? throw new \RuntimeException("The asset container \"{$container}\" no longer exists.");

        $bytes = $this->encoder->convert($binary, $sourceMime, $fileType, (int) ($this->config['encode']['quality'] ?? 90));

        $basename = Destinations::safeFilename($filename).'.'.$fileType;
        $path = ltrim(Destinations::safeFolder($folder).'/'.$basename, '/');

        $tmp = tempnam(sys_get_temp_dir(), 'darkroom');
        file_put_contents($tmp, $bytes);

        // Going through upload() rather than writing to the disk keeps
        // everything an editor's own upload gets: a safe lowercase filename,
        // a suffix when the name is taken, the asset events and preset warming.
        //
        // The one thing deliberately left out is the client MIME type. Statamic
        // only runs a container's source preset (the resize applied to
        // uploads) when the file arrives with an image MIME type, and its own
        // crop tool omits it for the same reason as here: the image is already
        // the size that was asked for. Passing it opts back in to the resize.
        $mime = ($this->config['save']['apply_source_preset'] ?? false) ? ImageEncoder::mime($fileType) : null;
        $file = new UploadedFile($tmp, $basename, $mime, null, true);

        try {
            $this->validate($file, $assetContainer);

            $asset = $assetContainer->makeAsset($path)->upload($file);
        } finally {
            @unlink($tmp);
        }

        if (! $asset) {
            throw new \RuntimeException('Statamic declined to create the asset.');
        }

        if ($alt !== null && trim($alt) !== '' && $asset->blueprint()->hasField('alt')) {
            $data['alt'] = trim($alt);
        }

        if ($data !== []) {
            foreach ($data as $key => $value) {
                $asset->set($key, $value);
            }

            $asset->save();
        }

        return $asset;
    }

    protected function validate(UploadedFile $file, $container): void
    {
        $rules = collect($container->validationRules())
            ->map(fn ($rule) => FieldValidator::parse($rule))
            ->all();

        Validator::make(
            ['file' => $file],
            ['file' => array_merge(['file', new AllowedFile], $rules)],
        )->validate();
    }
}
