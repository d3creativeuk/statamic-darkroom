<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\AssetUploaded;
use Statamic\Facades\Asset;

class SaveTest extends TestCase
{
    protected $assetContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assetContainer = $this->container();
        $this->actingAs($this->superUser());
    }

    /**
     * Generate a batch and return it once its images are ready to save.
     */
    protected function ready(array $overrides = [], ?string $image = null): array
    {
        Http::fake(['*' => Http::response($this->interactionsResponse($image ?? $this->jpeg(320, 180)))]);

        $created = $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload($overrides))
            ->assertCreated()
            ->json();

        return $this->getJson($created['urls']['show'])->json();
    }

    protected function dimensionsOf(string $path): array
    {
        return array_slice(getimagesizefromstring(Storage::disk('assets')->get($path)), 0, 2);
    }

    /**
     * Give the container a source preset, the way a site caps its uploads.
     */
    protected function capUploadsAt(int $pixels): void
    {
        config()->set('statamic.assets.image_manipulation.presets.capped', ['w' => $pixels, 'h' => $pixels, 'fit' => 'max']);

        $this->assetContainer->sourcePreset('capped')->save();
    }

    #[Test]
    public function saving_creates_an_asset_in_the_chosen_folder()
    {
        $batch = $this->ready();

        $saving = $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'red-bicycle', 'alt' => 'A red bicycle'])
            ->assertStatus(202)
            ->json();

        // Answered before the asset exists: the save runs after the response.
        $this->assertSame('saving', $saving['items'][0]['status']);

        $saved = $this->getJson($batch['urls']['show'])->json('items.0');

        $this->assertSame('saved', $saved['status']);
        $this->assertSame('assets::blog/red-bicycle.jpg', $saved['asset']['id']);
        $this->assertSame('blog/red-bicycle.jpg', $saved['asset']['path']);
        $this->assertStringContainsString('blog/red-bicycle.jpg', $saved['asset']['edit_url']);

        Storage::disk('assets')->assertExists('blog/red-bicycle.jpg');

        $this->assertSame('A red bicycle', Asset::find('assets::blog/red-bicycle.jpg')->get('alt'));
    }

    #[Test]
    public function the_folder_is_chosen_when_saving()
    {
        $batch = $this->ready(['folder' => 'blog']);

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'chosen', 'folder' => 'heroes/2026'])->assertStatus(202);

        Storage::disk('assets')->assertExists('heroes/2026/chosen.jpg');
        Storage::disk('assets')->assertMissing('blog/chosen.jpg');
    }

    #[Test]
    public function the_top_level_can_be_chosen_over_the_suggested_folder()
    {
        $batch = $this->ready(['folder' => 'blog']);

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'at-the-top', 'folder' => ''])->assertStatus(202);

        Storage::disk('assets')->assertExists('at-the-top.jpg');
    }

    #[Test]
    public function another_container_can_be_chosen_when_saving_if_allowed()
    {
        $this->container('marketing');

        $batch = $this->ready();

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'elsewhere', 'container' => 'marketing', 'folder' => 'social'])->assertStatus(202);

        $this->assertNotNull(Asset::find('marketing::social/elsewhere.jpg'));

        $this->actingAs($this->userWith(['use darkroom', 'upload assets assets'], 'limited'));

        $mine = $this->ready();

        $this->postJson($mine['items'][0]['urls']['save'], ['container' => 'marketing'])->assertForbidden();
    }

    #[Test]
    public function a_jpeg_is_stored_byte_for_byte_as_google_sent_it()
    {
        $original = $this->jpeg(320, 180);
        $batch = $this->ready([], $original);

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'untouched']);

        $this->assertSame($original, Storage::disk('assets')->get('blog/untouched.jpg'));
    }

    #[Test]
    public function the_image_keeps_its_full_size_even_when_the_container_caps_uploads()
    {
        $this->capUploadsAt(100);

        $batch = $this->ready();

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'full-size']);

        // If this ever fails, Statamic has changed how it decides whether to
        // run a source preset, and AssetSaver needs another way to skip it.
        $this->assertSame([320, 180], $this->dimensionsOf('blog/full-size.jpg'));
    }

    #[Test]
    public function the_containers_cap_can_be_switched_back_on()
    {
        $this->capUploadsAt(100);
        config()->set('statamic-darkroom.save.apply_source_preset', true);

        $batch = $this->ready();

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'capped']);

        $this->assertSame([100, 56], $this->dimensionsOf('blog/capped.jpg'));
    }

    #[Test]
    public function saving_fires_the_same_upload_event_as_a_normal_upload()
    {
        $batch = $this->ready();

        Event::fake([AssetUploaded::class]);

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'evented']);

        Event::assertDispatched(AssetUploaded::class, fn ($event) => $event->asset->path() === 'blog/evented.jpg');
    }

    #[Test]
    public function filenames_are_made_safe_and_lowercase()
    {
        config()->set('statamic.assets.lowercase', true);

        $batch = $this->ready();

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'Hero Image.final']);

        Storage::disk('assets')->assertExists('blog/hero-image-final.jpg');
    }

    #[Test]
    public function with_no_filename_given_the_suggested_one_is_used()
    {
        $batch = $this->ready(['prompt' => 'A Red Bicycle', 'batch_size' => 2]);

        $this->postJson($batch['items'][0]['urls']['save']);
        $this->postJson($batch['items'][1]['urls']['save']);

        Storage::disk('assets')->assertExists('blog/a-red-bicycle-1.jpg');
        Storage::disk('assets')->assertExists('blog/a-red-bicycle-2.jpg');
    }

    #[Test]
    public function a_name_that_is_already_taken_does_not_overwrite_the_existing_asset()
    {
        $batch = $this->ready(['batch_size' => 2]);

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'same-name']);
        $this->postJson($batch['items'][1]['urls']['save'], ['filename' => 'same-name']);

        $items = $this->getJson($batch['urls']['show'])->json('items');

        $this->assertSame('blog/same-name.jpg', $items[0]['asset']['path']);
        $this->assertNotSame('blog/same-name.jpg', $items[1]['asset']['path']);
        $this->assertStringStartsWith('blog/same-name-', $items[1]['asset']['path']);
        $this->assertCount(2, Storage::disk('assets')->files('blog'));
    }

    #[Test]
    public function the_root_folder_and_a_folder_that_does_not_exist_yet_both_work()
    {
        $root = $this->ready(['folder' => '']);
        $this->postJson($root['items'][0]['urls']['save'], ['filename' => 'at-root']);

        Storage::disk('assets')->assertExists('at-root.jpg');

        $nested = $this->ready(['folder' => '/New Folder/2026/']);
        $this->postJson($nested['items'][0]['urls']['save'], ['filename' => 'nested']);

        $this->assertSame('new-folder/2026', $nested['folder']);
        Storage::disk('assets')->assertExists('new-folder/2026/nested.jpg');
    }

    #[Test]
    public function it_converts_to_webp()
    {
        $batch = $this->ready();

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'converted', 'file_type' => 'webp']);

        $webp = getimagesizefromstring(Storage::disk('assets')->get('blog/converted.webp'));

        $this->assertSame('image/webp', $webp['mime']);
        $this->assertSame([320, 180], [$webp[0], $webp[1]]);
    }

    #[Test]
    public function png_is_not_offered()
    {
        $batch = $this->ready();

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'nope', 'file_type' => 'png'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file_type');

        Http::fake();

        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload(['file_type' => 'png']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file_type');
    }

    #[Test]
    public function the_batchs_file_type_is_used_unless_the_save_says_otherwise()
    {
        $batch = $this->ready(['file_type' => 'webp']);

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'by-default']);

        Storage::disk('assets')->assertExists('blog/by-default.webp');
    }

    #[Test]
    public function a_saved_image_gives_up_its_temporary_copies()
    {
        $batch = $this->ready();
        $store = app(BatchStore::class);

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'tidy']);

        // It is an asset now. History shows it from there.
        $this->assertNull($store->original($batch['id'], 1));
        $this->assertFalse($store->hasPreview($batch['id'], 1));
        $this->assertSame('saved', $store->item($batch['id'], 1)['status']);
    }

    #[Test]
    public function only_a_finished_image_can_be_saved()
    {
        $batch = $this->ready();

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'once'])->assertStatus(202);

        // Already saved.
        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'twice'])
            ->assertStatus(409)
            ->assertJson(['code' => 'not_ready']);

        Storage::disk('assets')->assertMissing('blog/twice.jpg');
    }

    #[Test]
    public function a_failed_save_puts_the_image_back_so_it_can_be_saved_again()
    {
        $batch = $this->ready();

        // The container is removed between generating and saving.
        $this->assetContainer->delete();

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'orphan'])->assertForbidden();

        $item = app(BatchStore::class)->item($batch['id'], 1);

        $this->assertSame('complete', $item['status']);
        $this->assertNotNull(app(BatchStore::class)->original($batch['id'], 1));
    }

    #[Test]
    public function saving_needs_permission_to_upload_to_the_container()
    {
        $this->actingAs($this->userWith(['use darkroom', 'upload assets assets']));

        $batch = $this->ready();

        // The permission is taken away after the image was generated.
        $this->setTestRoles(['editor-role' => ['access cp', 'use darkroom']]);

        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => 'denied'])->assertForbidden();

        Storage::disk('assets')->assertMissing('blog/denied.jpg');
    }

    #[Test]
    public function you_cannot_generate_into_a_container_you_cannot_upload_to()
    {
        Http::fake();

        $this->actingAs($this->userWith(['use darkroom']));

        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('container');

        Http::assertNothingSent();
    }
}
