<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\Imaging\ImageEncoder;
use D3Creative\Darkroom\References\ReferenceStore;
use D3Creative\Darkroom\Support\Runtime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Asset;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Reference images: an upload from the user's computer, or an image chosen
 * from the asset library. Both become a reduced JPEG in the ReferenceStore,
 * and generating sends the ids of the ones to use.
 *
 * Nothing here creates or changes an asset, so Glide never sees a reference.
 */
class ReferenceController extends CpController
{
    // What Google and this server's image library can both read.
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    protected const MAX_PIXELS = 10000;

    public function store(Request $request, ReferenceStore $store, ImageEncoder $encoder)
    {
        $config = config('statamic-darkroom');
        $kb = (int) ($config['references']['max_upload_kb'] ?? 20480);
        $limit = self::size($kb);

        $request->validate([
            'image' => ['nullable', 'required_without:asset', 'file', 'mimes:'.implode(',', self::EXTENSIONS), 'max:'.$kb, 'dimensions:max_width='.self::MAX_PIXELS.',max_height='.self::MAX_PIXELS],
            'asset' => ['nullable', 'required_without:image', 'string', 'max:255'],
        ], [
            'image.required_without' => 'Choose an image.',
            'image.uploaded' => 'That image could not be uploaded. It may be larger than this server accepts.',
            'image.mimes' => 'Use a JPEG, PNG or WebP image.',
            'image.max' => "That image is too large. The limit is {$limit}.",
            'image.dimensions' => 'That image is too large. Use one no wider or taller than '.number_format(self::MAX_PIXELS).' pixels.',
        ]);

        [$binary, $record, $field] = $request->hasFile('image')
            ? $this->fromUpload($request)
            : $this->fromLibrary((string) $request->input('asset'), $kb, $limit);

        // Decoding a large photo needs more memory than PHP's default allows,
        // and running out cannot be caught.
        Runtime::extend($config, 60);

        try {
            $jpeg = $encoder->preview(
                $binary,
                (int) ($config['references']['max_edge'] ?? 1536),
                (int) ($config['references']['quality'] ?? 85),
            );
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Darkroom could not read that image.']);
        }

        [$width, $height] = $encoder->dimensions($jpeg);

        $user = User::current();
        $reference = $store->create($jpeg, $record, $user ? (string) $user->id() : null, (int) $width, (int) $height);

        return response()->json(self::present($reference), 201);
    }

    /**
     * The stored image, for the thumbnail on the page. Private to whoever
     * added it, and never a Glide URL.
     */
    public function show(string $id, ReferenceStore $store)
    {
        $user = User::current();

        abort_unless($user && $store->ownedBy($id, (string) $user->id()) && ($image = $store->image($id)) !== null, 404);

        return response($image, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  array<string, mixed>  $reference
     * @return array<string, mixed>
     */
    public static function present(array $reference): array
    {
        return [
            'id' => $reference['id'],
            'type' => $reference['type'],
            'name' => $reference['name'],
            'asset' => $reference['asset'] ?? null,
            'width' => $reference['width'] ?? null,
            'height' => $reference['height'] ?? null,
            'url' => cp_route('darkroom.references.show', $reference['id']),
        ];
    }

    /**
     * A limit in kilobytes as people read it, rounded down so a file that is
     * refused is always over the size it is told.
     */
    protected static function size(int $kb): string
    {
        return $kb < 1024
            ? number_format($kb).' KB'
            : rtrim(rtrim(number_format(floor($kb * 10 / 1024) / 10, 1), '0'), '.').' MB';
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    protected function fromUpload(Request $request): array
    {
        $file = $request->file('image');

        $name = ReferenceStore::safeName((string) $file->getClientOriginalName());

        return [(string) file_get_contents($file->getRealPath()), ['type' => 'upload', 'name' => $name], 'image'];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    protected function fromLibrary(string $id, int $kb, string $limit): array
    {
        $asset = Asset::find($id);

        if (! $asset) {
            throw ValidationException::withMessages(['asset' => 'That image is no longer in the asset library.']);
        }

        if (! Gate::forUser(User::current())->allows('view', $asset)) {
            throw ValidationException::withMessages(['asset' => 'You do not have permission to use that image.']);
        }

        if (! in_array(strtolower((string) $asset->extension()), self::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['asset' => 'Use a JPEG, PNG or WebP image.']);
        }

        if ((int) $asset->size() > $kb * 1024 || max((int) $asset->width(), (int) $asset->height()) > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['asset' => "That image is too large. The limit is {$limit} and ".number_format(self::MAX_PIXELS).' pixels.']);
        }

        return [(string) $asset->contents(), ['type' => 'asset', 'name' => ReferenceStore::safeName((string) $asset->basename()), 'asset' => $asset->id()], 'asset'];
    }
}
