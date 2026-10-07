<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\Jobs\GenerateBatch;
use D3Creative\Darkroom\Models\ModelRegistry;
use D3Creative\Darkroom\References\ReferenceStore;
use D3Creative\Darkroom\Tests\TestCase;
use D3Creative\Darkroom\Usage\UsageLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\AssetCreated;
use Statamic\Events\AssetUploaded;
use Statamic\Events\GlideImageGenerated;
use Statamic\Facades\Asset;

class ReferenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->container();
        $this->actingAs($this->superUser());
    }

    protected function store(): ReferenceStore
    {
        return app(ReferenceStore::class);
    }

    protected function png(int $width, int $height, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($width, $height);

        if ($transparent) {
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        } else {
            imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));
        }

        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    protected function upload(string $binary, string $name = 'style.png')
    {
        return $this->post(
            cp_route('darkroom.references.store'),
            ['image' => UploadedFile::fake()->createWithContent($name, $binary)],
            ['Accept' => 'application/json'],
        );
    }

    /**
     * Upload an image and return the reference as the page gets it.
     */
    protected function reference(string $name = 'style.png', ?string $binary = null): array
    {
        return $this->upload($binary ?? $this->png(64, 48), $name)->assertCreated()->json();
    }

    protected function libraryImage(string $path = 'photos/cat.png', ?string $binary = null): string
    {
        Storage::disk('assets')->put($path, $binary ?? $this->png(80, 60));
        $this->container()->makeAsset($path)->save();

        return 'assets::'.$path;
    }

    protected function generate(array $overrides = [])
    {
        return $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload($overrides));
    }

    #[Test]
    public function an_upload_is_kept_as_a_reduced_jpeg_and_never_becomes_an_asset()
    {
        config()->set('statamic-darkroom.references.max_edge', 1000);

        $reference = $this->reference('Holiday Photo.png', $this->png(3000, 1000));

        $this->assertSame('upload', $reference['type']);
        $this->assertSame('Holiday Photo.png', $reference['name']);
        $this->assertNull($reference['asset']);
        $this->assertSame([1000, 333], [$reference['width'], $reference['height']]);
        $this->assertSame(cp_route('darkroom.references.show', $reference['id']), $reference['url']);

        $stored = $this->store()->image($reference['id']);
        $this->assertSame('image/jpeg', getimagesizefromstring($stored)['mime']);

        $this->assertSame([], Storage::disk('assets')->allFiles());
    }

    #[Test]
    public function transparency_comes_out_on_white()
    {
        $reference = $this->reference('cut-out.png', $this->png(40, 40, transparent: true));

        $image = imagecreatefromstring($this->store()->image($reference['id']));
        $rgb = imagecolorsforindex($image, imagecolorat($image, 20, 20));

        $this->assertGreaterThan(245, $rgb['red']);
        $this->assertGreaterThan(245, $rgb['green']);
        $this->assertGreaterThan(245, $rgb['blue']);
    }

    #[Test]
    public function only_jpeg_png_and_webp_images_are_taken()
    {
        $this->upload('GIF89a', 'moving.gif')->assertStatus(422)->assertJsonValidationErrors(['image' => 'Use a JPEG, PNG or WebP image.']);
        $this->upload('<svg xmlns="http://www.w3.org/2000/svg"/>', 'logo.svg')->assertStatus(422)->assertJsonValidationErrors('image');
        $this->upload('just some words', 'notes.txt')->assertStatus(422)->assertJsonValidationErrors('image');

        // The right name on the wrong bytes.
        $this->upload('not really a picture', 'fake.jpg')->assertStatus(422)->assertJsonValidationErrors('image');

        $this->assertSame([], $this->store()->disk()->allFiles('statamic-darkroom/references'));
    }

    #[Test]
    public function an_upload_over_the_limit_is_refused_with_the_limit()
    {
        config()->set('statamic-darkroom.references.max_upload_kb', 1);

        $this->upload($this->png(400, 400).str_repeat("\0", 2048))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image' => 'That image is too large. The limit is 1 KB.']);

        // A library image is checked at its original size.
        $id = $this->libraryImage('photos/big.png', $this->png(400, 400).str_repeat("\0", 2048));

        $this->postJson(cp_route('darkroom.references.store'), ['asset' => $id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset' => 'That image is too large. The limit is 1 KB and 10,000 pixels.']);
    }

    #[Test]
    public function names_are_made_safe_to_show_before_they_are_kept()
    {
        // Some Control Panel messages are HTML, and a filename is whatever
        // its owner typed.
        $this->assertSame('-img src=x onerror=alert(1)-.png', $this->reference('<img src=x onerror=alert(1)>.png')['name']);

        // Not valid UTF-8, as only a hand-made request could send.
        $this->assertSame('photo??.png', $this->reference("photo\xB1\xFF.png")['name']);

        // A name stored before this was cleaned is cleaned as it is read.
        $asset = $this->libraryImage('photos/old.png');
        Asset::find($asset)->set(SavedImages::KEY, [
            'prompt' => 'Old',
            'generated_at' => now()->getTimestamp(),
            'references' => [['type' => 'upload', 'name' => '<i onmouseover=alert(1)>x.png']],
        ])->save();

        $listed = collect($this->getJson(cp_route('darkroom.history.index'))->json('items'))->firstWhere('id', $asset);

        $this->assertSame('-i onmouseover=alert(1)-x.png', $listed['references'][0]['name']);
    }

    #[Test]
    public function a_reference_image_is_only_shown_to_whoever_added_it()
    {
        $reference = $this->reference();

        $this->get($reference['url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($this->userWith(['use darkroom']));

        $this->get($reference['url'])->assertNotFound();
    }

    #[Test]
    public function an_image_from_the_library_needs_only_permission_to_view_it()
    {
        $id = $this->libraryImage();

        $this->actingAs($this->userWith(['use darkroom', 'view assets assets']));

        $reference = $this->postJson(cp_route('darkroom.references.store'), ['asset' => $id])->assertCreated()->json();

        $this->assertSame('asset', $reference['type']);
        $this->assertSame('cat.png', $reference['name']);
        $this->assertSame($id, $reference['asset']);
        $this->assertSame('image/jpeg', getimagesizefromstring($this->store()->image($reference['id']))['mime']);

        $this->actingAs($this->userWith(['use darkroom'], 'outsider'));

        $this->postJson(cp_route('darkroom.references.store'), ['asset' => $id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset' => 'You do not have permission to use that image.']);
    }

    #[Test]
    public function a_missing_or_unusable_library_image_is_refused()
    {
        $this->postJson(cp_route('darkroom.references.store'), ['asset' => 'assets::gone.jpg'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset' => 'That image is no longer in the asset library.']);

        Storage::disk('assets')->put('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->container()->makeAsset('logo.svg')->save();

        $this->postJson(cp_route('darkroom.references.store'), ['asset' => 'assets::logo.svg'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset' => 'Use a JPEG, PNG or WebP image.']);
    }

    #[Test]
    public function glide_never_touches_a_reference_image()
    {
        $asset = $this->libraryImage();
        $before = Storage::disk('assets')->allFiles();

        Event::fake([AssetUploaded::class, AssetCreated::class, GlideImageGenerated::class]);
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $uploaded = $this->reference();
        $picked = $this->postJson(cp_route('darkroom.references.store'), ['asset' => $asset])->assertCreated()->json();

        $this->get($uploaded['url'])->assertOk();
        $this->generate(['references' => [$uploaded['id'], $picked['id']]])->assertCreated();

        Event::assertNotDispatched(AssetUploaded::class);
        Event::assertNotDispatched(AssetCreated::class);
        Event::assertNotDispatched(GlideImageGenerated::class);

        $this->assertSame($before, Storage::disk('assets')->allFiles());
    }

    #[Test]
    public function references_go_to_google_in_order_after_the_prompt_with_the_system_instruction()
    {
        $instruction = $this->postJson(cp_route('darkroom.instructions.store'), ['title' => 'House', 'body' => 'Muted colours.'])->json('saved');

        // Different images, so a wrong order would show.
        $ids = array_column([$this->reference('one.png', $this->png(64, 48)), $this->reference('two.png', $this->png(48, 64)), $this->reference('three.png', $this->png(40, 40))], 'id');

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->generate([
            'prompt' => 'A lighthouse in the style of image 1',
            'instruction' => $instruction['id'],
            'references' => $ids,
        ])->assertCreated()->assertJsonPath('references.1.name', 'two.png');

        Http::assertSentCount(1);
        $sent = Http::recorded()[0][0];

        $this->assertSame(['type' => 'text', 'text' => 'A lighthouse in the style of image 1'], $sent['input'][0]);
        $this->assertCount(4, $sent['input']);

        foreach ($ids as $position => $id) {
            $this->assertSame(
                ['type' => 'image', 'mime_type' => 'image/jpeg', 'data' => base64_encode($this->store()->image($id))],
                $sent['input'][$position + 1],
            );
        }

        // Unlike an upscale or a revision, the style still applies.
        $this->assertSame('Muted colours.', $sent['system_instruction']);
    }

    #[Test]
    public function lite_gets_the_instruction_ahead_of_the_prompt_and_the_images_after()
    {
        $instruction = $this->postJson(cp_route('darkroom.instructions.store'), ['title' => 'House', 'body' => 'Muted colours.'])->json('saved');
        $reference = $this->reference();

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->generate([
            'model' => 'gemini-3.1-flash-lite-image',
            'quality' => '1K',
            'instruction' => $instruction['id'],
            'references' => [$reference['id']],
        ])->assertCreated();

        $sent = Http::recorded()[0][0];

        $this->assertStringStartsWith('Muted colours.', $sent['input'][0]['text']);
        $this->assertStringContainsString('A red bicycle', $sent['input'][0]['text']);
        $this->assertSame('image', $sent['input'][1]['type']);
        $this->assertArrayNotHasKey('system_instruction', $sent->data());
    }

    #[Test]
    public function the_generate_content_api_sends_references_as_inline_data()
    {
        config()->set('statamic-darkroom.api', 'generate_content');

        // Different images, so a wrong order would show.
        $ids = array_column([$this->reference('one.png', $this->png(64, 48)), $this->reference('two.png', $this->png(48, 64))], 'id');

        Http::fake(['*' => Http::response($this->generateContentResponse())]);

        $this->generate(['references' => $ids])->assertCreated();

        $parts = Http::recorded()[0][0]['contents'][0]['parts'];

        $this->assertSame('A red bicycle leaning against a white wall', $parts[0]['text']);
        $this->assertSame(['mimeType' => 'image/jpeg', 'data' => base64_encode($this->store()->image($ids[0]))], $parts[1]['inlineData']);
        $this->assertSame(['mimeType' => 'image/jpeg', 'data' => base64_encode($this->store()->image($ids[1]))], $parts[2]['inlineData']);
    }

    #[Test]
    public function every_image_in_a_batch_and_a_retry_sends_the_references()
    {
        $reference = $this->reference();

        Http::fake(['*' => Http::sequence()
            ->push($this->interactionsResponse())
            ->push($this->fixture('interactions-error-400'), 400)
            ->push($this->interactionsResponse()),
        ]);

        $created = $this->generate(['batch_size' => 2, 'references' => [$reference['id']]])->assertCreated()->json();
        $batch = $this->getJson($created['urls']['show'])->json();

        $this->postJson($batch['items'][1]['urls']['retry'])->assertStatus(202);

        Http::assertSentCount(3);

        foreach (Http::recorded() as [$request]) {
            $this->assertSame(base64_encode($this->store()->image($reference['id'])), $request['input'][1]['data']);
        }
    }

    #[Test]
    public function a_batch_keeps_its_own_copies_so_pruning_the_uploads_cannot_strand_it()
    {
        $reference = $this->reference();
        $image = $this->store()->image($reference['id']);

        $batches = app(BatchStore::class);
        $batch = $batches->create($this->generatePayload(['user' => 'super', 'references' => [['type' => 'upload', 'name' => 'style.png']]]), 1);
        $batches->putReferences($batch['id'], [$image]);

        // The upload goes, as a prune would take it.
        $this->store()->disk()->deleteDirectory('statamic-darkroom/references/'.$reference['id']);
        $this->assertNull($this->store()->find($reference['id']));

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        GenerateBatch::dispatchSync($batch['id']);

        $this->assertSame(base64_encode($image), Http::recorded()[0][0]['input'][1]['data']);
        $this->assertSame('complete', $batches->find($batch['id'])['items'][0]['status']);
    }

    #[Test]
    public function a_batch_whose_references_have_gone_fails_without_calling_google()
    {
        Http::fake();

        $batches = app(BatchStore::class);
        $batch = $batches->create($this->generatePayload(['user' => 'super', 'references' => [['type' => 'upload', 'name' => 'style.png']]]), 1);

        GenerateBatch::dispatchSync($batch['id']);

        $item = $batches->find($batch['id'])['items'][0];

        $this->assertSame('failed', $item['status']);
        $this->assertSame('reference_missing', $item['error']['code']);
        $this->assertFalse($item['error']['retryable']);
        Http::assertNothingSent();
    }

    #[Test]
    public function references_that_are_not_yours_expired_repeated_or_too_many_are_refused()
    {
        Http::fake();

        $mine = $this->reference();

        $this->actingAs($this->userWith(['use darkroom', 'view assets assets', 'upload assets assets'], 'other'));
        $theirs = $this->reference();
        $this->actingAs($this->superUser());

        $this->generate(['references' => [$theirs['id']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['references' => 'A reference image has expired. Add it again.']);

        $this->generate(['references' => [$mine['id'], $mine['id']]])->assertStatus(422)->assertJsonValidationErrors('references.1');

        $this->generate(['references' => array_fill(0, 15, $mine['id'])])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['references' => 'Use at most 14 reference images.']);

        $this->travel(25)->hours();
        $this->store()->prune();

        $this->generate(['references' => [$mine['id']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['references' => 'A reference image has expired.']);

        $this->assertSame([], app(BatchStore::class)->openFor('super'));
        Http::assertNothingSent();
    }

    #[Test]
    public function a_set_too_large_for_one_request_is_refused()
    {
        // About 100 bytes, less than any image.
        config()->set('statamic-darkroom.references.max_request_mb', 0.0001);

        $reference = $this->reference();

        $this->generate(['references' => [$reference['id']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['references' => 'These reference images are too large together.']);
    }

    #[Test]
    public function without_references_the_prompt_goes_as_plain_text()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->generate()->assertCreated()->assertJsonPath('references', []);

        $this->assertSame('A red bicycle leaning against a white wall', Http::recorded()[0][0]['input']);
    }

    #[Test]
    public function spend_counts_each_image_sent_with_the_prompt()
    {
        $this->travelTo('2026-10-05 12:00:00');

        // Different images, so a wrong order would show.
        $ids = array_column([$this->reference('one.png', $this->png(64, 48)), $this->reference('two.png', $this->png(48, 64)), $this->reference('three.png', $this->png(40, 40))], 'id');

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->generate(['references' => $ids])->assertCreated();

        // 2K on Pro, plus three input images at $0.0011.
        $this->assertSame(0.1373, app(UsageLog::class)->entries('2026-10')[0]['price']);
    }

    #[Test]
    public function a_saved_image_records_its_references_and_history_shows_and_finds_them()
    {
        $asset = $this->libraryImage('photos/linocut.png');
        $picked = $this->postJson(cp_route('darkroom.references.store'), ['asset' => $asset])->json();
        $uploaded = $this->reference('Holiday Photo.png');

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $created = $this->generate(['references' => [$picked['id'], $uploaded['id']]])->json();
        $batch = $this->getJson($created['urls']['show'])->json();

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'bicycle'])->assertStatus(202);

        $this->assertSame([
            ['type' => 'asset', 'name' => 'linocut.png', 'asset' => $asset],
            ['type' => 'upload', 'name' => 'Holiday Photo.png'],
        ], Asset::find('assets::blog/bicycle.jpg')->get('darkroom')['references']);

        $listed = collect($this->getJson(cp_route('darkroom.history.index'))->json('items'))->firstWhere('id', 'assets::blog/bicycle.jpg');

        $this->assertSame([
            ['type' => 'asset', 'name' => 'linocut.png', 'asset' => $asset],
            ['type' => 'upload', 'name' => 'Holiday Photo.png', 'asset' => null],
        ], $listed['references']);

        $found = $this->getJson(cp_route('darkroom.history.index', ['search' => 'holiday']))->json('items');

        $this->assertSame(['assets::blog/bicycle.jpg'], array_column($found, 'id'));
    }

    #[Test]
    public function an_upscale_of_a_referenced_image_sends_only_its_source_and_keeps_the_record()
    {
        $reference = $this->reference();

        Http::fake(['*' => Http::response($this->interactionsResponse($this->jpeg(64, 36)))]);

        $created = $this->generate(['model' => 'gemini-3.1-flash-image', 'quality' => '1K', 'references' => [$reference['id']]])->json();

        $upscale = $this->postJson(cp_route('darkroom.upscales.store'), ['batch' => $created['id'], 'index' => 1, 'model' => 'gemini-3-pro-image', 'quality' => '4K'])
            ->assertCreated()
            ->assertJsonPath('references.0.name', 'style.png')
            ->json();

        $sent = collect(Http::recorded())->last()[0];

        $this->assertCount(2, $sent['input']);

        $batch = $this->getJson($upscale['urls']['show'])->json();

        $this->assertSame('complete', $batch['items'][0]['status']);

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'bigger'])->assertStatus(202);

        $this->assertSame('style.png', Asset::find('assets::blog/bigger.jpg')->get('darkroom')['references'][0]['name']);
    }

    #[Test]
    public function a_reference_still_being_written_is_not_pruned_as_debris()
    {
        // create() writes the image first and its record last.
        $id = strtolower((string) Str::ulid());
        $this->store()->disk()->put("statamic-darkroom/references/{$id}/image.jpg", $this->jpeg());

        $this->assertSame(0, $this->store()->prune());

        $this->travel(25)->hours();

        $this->assertSame(1, $this->store()->prune());
        $this->assertFalse($this->store()->disk()->exists("statamic-darkroom/references/{$id}"));
    }

    #[Test]
    public function a_config_published_before_input_prices_existed_still_charges_for_them()
    {
        // A published config replaces the whole models array.
        config()->set('statamic-darkroom.models', collect(config('statamic-darkroom.models'))
            ->map(fn (array $model) => collect($model)->except('input_image_price')->all())
            ->all());

        $this->assertSame(0.0011, app(ModelRegistry::class)->inputImagePrice('gemini-3-pro-image'));
        $this->assertSame(0.1373, app(ModelRegistry::class)->priceWithInputs('gemini-3-pro-image', '2K', 3));
    }

    #[Test]
    public function the_smoke_command_sends_references_and_leaves_an_unpriced_size_unpriced()
    {
        $this->travelTo('2026-10-06 12:00:00');

        $file = $this->scratch('style.png');
        app('files')->ensureDirectoryExists(dirname($file));
        file_put_contents($file, $this->png(64, 48));

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        // Pro accepts 512 but the config has no price for it.
        $this->artisan('darkroom:smoke', ['prompt' => 'A lighthouse', '--model' => 'gemini-3-pro-image', '--quality' => '512', '--reference' => [$file]])
            ->assertSuccessful();

        $sent = Http::recorded()[0][0];

        $this->assertSame('A lighthouse', $sent['input'][0]['text']);
        $this->assertSame('image/jpeg', $sent['input'][1]['mime_type']);
        $this->assertNull(app(UsageLog::class)->entries('2026-10')[0]['price']);
    }

    #[Test]
    public function an_upscale_never_sends_reference_files_even_if_its_batch_had_them()
    {
        $store = app(BatchStore::class);
        $batch = $store->create([
            'kind' => 'upscale', 'user' => 'super', 'prompt' => 'A lighthouse', 'model' => 'gemini-3-pro-image',
            'quality' => '2K', 'aspect_ratio' => '16:9', 'file_type' => 'jpg', 'container' => 'assets', 'folder' => '',
            'source_mime' => 'image/jpeg', 'references' => [['type' => 'upload', 'name' => 'style.png']],
        ], 1);

        $store->putSource($batch['id'], $this->jpeg(64, 36));
        $store->putReferences($batch['id'], [$this->jpeg(8, 8)]);

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        GenerateBatch::dispatchSync($batch['id']);

        $this->assertCount(2, Http::recorded()[0][0]['input']);
    }

    #[Test]
    public function old_references_are_removed_by_the_prune_command_and_by_opening_the_page()
    {
        $first = $this->reference();

        $this->travel(25)->hours();

        $this->artisan('darkroom:prune')->expectsOutput('Removed 1 reference image.')->assertSuccessful();
        $this->assertNull($this->store()->find($first['id']));

        $second = $this->reference();

        $this->travel(25)->hours();

        $this->get(cp_route('darkroom.index'))->assertOk();
        $this->assertNull($this->store()->find($second['id']));
    }
}
