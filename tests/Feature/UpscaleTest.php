<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Api\GenerateContentClient;
use D3Creative\Darkroom\Api\ImageRequest;
use D3Creative\Darkroom\Api\InteractionsClient;
use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Jobs\GenerateBatch;
use D3Creative\Darkroom\Tests\TestCase;
use D3Creative\Darkroom\Usage\UsageLog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;

class UpscaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->container();
        $this->actingAs($this->superUser());
    }

    /**
     * Generate one draft and return its finished batch.
     */
    protected function draft(array $overrides = [], ?string $image = null): array
    {
        Http::fake(['*' => Http::response($this->interactionsResponse($image ?? $this->jpeg(64, 36)))]);

        $created = $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload(array_merge([
            'prompt' => 'A lighthouse at dusk',
            'model' => 'gemini-3.1-flash-image',
            'quality' => '512',
        ], $overrides)))->assertCreated()->json();

        return $this->getJson($created['urls']['show'])->json();
    }

    protected function upscale(array $payload)
    {
        return $this->postJson(cp_route('darkroom.upscales.store'), $payload);
    }

    #[Test]
    public function the_half_k_quality_is_sent_as_512_and_only_offered_on_nano_banana_2()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload(['model' => 'gemini-3.1-flash-image', 'quality' => '512']))
            ->assertCreated()
            ->assertJsonPath('quality', '512')
            ->assertJsonPath('qualityLabel', '0.5K');

        foreach (['gemini-3-pro-image', 'gemini-3.1-flash-lite-image'] as $model) {
            $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload(['model' => $model, 'quality' => '512']))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['quality' => 'cannot produce 0.5K images']);
        }

        Http::assertSentCount(1);
        $this->assertSame('512', Http::recorded()[0][0]['response_format']['image_size']);
    }

    #[Test]
    public function both_clients_send_an_input_image_alongside_the_prompt()
    {
        $image = $this->jpeg(8, 8);

        $request = new ImageRequest('Enlarge this', 'gemini-3-pro-image', '2K', '16:9', references: [
            ['mime_type' => 'image/jpeg', 'data' => $image],
        ]);

        $interactions = (new InteractionsClient(config('statamic-darkroom')))->payload($request);

        $this->assertSame([
            ['type' => 'text', 'text' => 'Enlarge this'],
            ['type' => 'image', 'mime_type' => 'image/jpeg', 'data' => base64_encode($image)],
        ], $interactions['input']);

        $generate = (new GenerateContentClient(config('statamic-darkroom')))->payload($request);

        $this->assertSame([
            ['text' => 'Enlarge this'],
            ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($image)]],
        ], $generate['contents'][0]['parts']);
    }

    #[Test]
    public function an_unsaved_image_is_upscaled_by_sending_it_back_at_a_larger_size()
    {
        $source = $this->jpeg(64, 36);

        // The first matching fake wins, so this one is registered ahead of the
        // draft's and answers both calls: small for the draft, large when it
        // is sent an image to enlarge.
        Http::fake(fn (Request $request) => Http::response($this->interactionsResponse(
            is_array($request['input']) ? $this->jpeg(256, 144) : $source
        )));

        $draft = $this->draft(['aspect_ratio' => '4:3']);

        $created = $this->upscale(['batch' => $draft['id'], 'index' => 1, 'model' => 'gemini-3-pro-image', 'quality' => '2K'])
            ->assertCreated()
            ->json();

        $this->assertNotSame($draft['id'], $created['id']);
        $this->assertSame('A lighthouse at dusk', $created['prompt']);
        $this->assertSame('2K', $created['quality']);
        $this->assertSame('0.5K', $created['upscaledFrom']);
        $this->assertSame('4:3', $created['aspectRatio']);
        $this->assertSame('Nano Banana Pro', $created['modelLabel']);
        $this->assertCount(1, $created['items']);

        $sent = collect(Http::recorded())->last()[0];

        $this->assertSame('gemini-3-pro-image', $sent['model']);
        $this->assertSame(['type' => 'image', 'image_size' => '2K', 'aspect_ratio' => '4:3'], $sent['response_format']);
        $this->assertSame(config('statamic-darkroom.upscale.prompt'), $sent['input'][0]['text']);
        $this->assertSame(['type' => 'image', 'mime_type' => 'image/jpeg', 'data' => base64_encode($source)], $sent['input'][1]);

        $done = $this->getJson($created['urls']['show'])->json('items.0');

        $this->assertSame('complete', $done['status']);
        $this->assertSame(256, $done['width']);
    }

    #[Test]
    public function the_system_instruction_is_left_out_of_an_upscale()
    {
        $instruction = $this->postJson(cp_route('darkroom.instructions.store'), ['title' => 'House', 'body' => 'Line drawings only.'])->json('saved');

        $draft = $this->draft(['instruction' => $instruction['id']]);

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->upscale(['batch' => $draft['id'], 'index' => 1, 'model' => 'gemini-3.1-flash-image', 'quality' => '1K'])->assertCreated();

        Http::assertSent(fn (Request $request) => ! isset($request['system_instruction'])
            && ! str_contains($request['input'][0]['text'], 'Line drawings only.'));
    }

    #[Test]
    public function the_draft_is_left_as_it_was()
    {
        $draft = $this->draft();

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->upscale(['batch' => $draft['id'], 'index' => 1, 'model' => 'gemini-3-pro-image', 'quality' => '2K'])->assertCreated();

        $this->assertSame('complete', $this->getJson($draft['urls']['show'])->json('items.0.status'));
        $this->assertNotNull(app(BatchStore::class)->original($draft['id'], 1));
    }

    #[Test]
    public function discarding_the_draft_afterwards_does_not_strand_the_upscale()
    {
        $draft = $this->draft();
        $store = app(BatchStore::class);

        // Create the upscale batch without running it, then discard the draft
        // it came from, as could happen between the click and the job.
        $upscale = $store->create([
            'kind' => 'upscale', 'user' => 'super', 'prompt' => 'A lighthouse at dusk', 'model' => 'gemini-3-pro-image',
            'quality' => '2K', 'aspect_ratio' => '16:9', 'file_type' => 'jpg', 'container' => 'assets', 'folder' => 'blog',
            'upscaled_from' => '512', 'source_mime' => 'image/jpeg',
        ], 1);
        $store->putSource($upscale['id'], $store->original($draft['id'], 1));

        $this->deleteJson($draft['urls']['destroy'])->assertNoContent();

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        GenerateBatch::dispatchSync($upscale['id']);

        $this->assertSame('complete', $store->item($upscale['id'], 1)['status']);
        Http::assertSent(fn (Request $request) => is_array($request['input']) && $request['input'][1]['type'] === 'image');
    }

    #[Test]
    public function an_upscale_with_no_source_fails_without_calling_google()
    {
        $store = app(BatchStore::class);

        $upscale = $store->create([
            'kind' => 'upscale', 'user' => 'super', 'prompt' => 'Lost', 'model' => 'gemini-3-pro-image',
            'quality' => '2K', 'aspect_ratio' => '16:9', 'file_type' => 'jpg', 'container' => 'assets', 'folder' => '',
        ], 1);

        Http::fake();

        GenerateBatch::dispatchSync($upscale['id']);

        $item = $store->item($upscale['id'], 1);

        $this->assertSame('failed', $item['status']);
        $this->assertSame('source_missing', $item['error']['code']);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_target_must_be_larger_than_the_source()
    {
        $draft = $this->draft(['quality' => '2K']);

        Http::fake();

        foreach (['512', '1K', '2K'] as $quality) {
            $this->upscale(['batch' => $draft['id'], 'index' => 1, 'model' => 'gemini-3.1-flash-image', 'quality' => $quality])
                ->assertStatus(422)
                ->assertJsonValidationErrors('quality');
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function the_target_must_be_a_quality_the_model_can_produce()
    {
        $draft = $this->draft();

        Http::fake();

        $this->upscale(['batch' => $draft['id'], 'index' => 1, 'model' => 'gemini-3.1-flash-lite-image', 'quality' => '4K'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quality');

        $this->upscale(['batch' => $draft['id'], 'index' => 1, 'model' => 'gemini-2.5-flash-image', 'quality' => '2K'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('model');

        $this->upscale(['model' => 'gemini-3-pro-image', 'quality' => '2K'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['batch', 'asset']);

        Http::assertNothingSent();
    }

    #[Test]
    public function an_upscale_is_logged_as_spend_at_the_new_size()
    {
        $this->travelTo('2026-09-30 12:00:00');

        $draft = $this->draft();

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->upscale(['batch' => $draft['id'], 'index' => 1, 'model' => 'gemini-3-pro-image', 'quality' => '4K'])->assertCreated();

        $entries = app(UsageLog::class)->entries('2026-09');

        $this->assertCount(2, $entries);
        $this->assertSame('upscale', $entries[0]['kind']);
        // The 4K image plus the source sent with it, which Google bills as input.
        $this->assertSame(0.2411, $entries[0]['price']);
        $this->assertSame('Upscale: A lighthouse at dusk', $entries[0]['prompt']);
        $this->assertSame('generate', $entries[1]['kind']);
        $this->assertSame(0.045, $entries[1]['price']);
    }

    #[Test]
    public function a_saved_upscale_is_named_after_its_size_and_remembers_where_it_came_from()
    {
        $draft = $this->draft();

        Http::fake(['*' => Http::response($this->interactionsResponse($this->jpeg(256, 144)))]);

        $created = $this->upscale(['batch' => $draft['id'], 'index' => 1, 'model' => 'gemini-3-pro-image', 'quality' => '2K'])->json();
        $batch = $this->getJson($created['urls']['show'])->json();

        $this->assertSame('a-lighthouse-at-dusk-2k', $batch['items'][0]['filename']);

        $this->postJson($batch['items'][0]['urls']['save'])->assertStatus(202);

        Storage::disk('assets')->assertExists('blog/a-lighthouse-at-dusk-2k.jpg');

        $stamp = Asset::find('assets::blog/a-lighthouse-at-dusk-2k.jpg')->get('darkroom');

        $this->assertSame('A lighthouse at dusk', $stamp['prompt']);
        $this->assertSame('2K', $stamp['quality']);
        $this->assertSame('512', $stamp['upscaled_from']);
        $this->assertSame('gemini-3-pro-image', $stamp['model']);

        $item = $this->getJson(cp_route('darkroom.history.index'))->json('items.0');

        $this->assertSame('2K', $item['qualityLabel']);
        $this->assertSame('0.5K', $item['upscaledFrom']);
    }

    #[Test]
    public function a_saved_asset_can_be_upscaled_from_history()
    {
        $source = $this->jpeg(64, 36);
        $draft = $this->draft(['aspect_ratio' => '1:1', 'file_type' => 'webp', 'folder' => 'heroes'], $source);

        $this->postJson($draft['items'][0]['urls']['save'], ['filename' => 'lighthouse'])->assertStatus(202);

        $stored = Storage::disk('assets')->get('heroes/lighthouse.webp');

        Http::fake(['*' => Http::response($this->interactionsResponse($this->jpeg(256, 256)))]);

        $created = $this->upscale(['asset' => 'assets::heroes/lighthouse.webp', 'model' => 'gemini-3-pro-image', 'quality' => '4K'])
            ->assertCreated()
            ->json();

        // Everything it needs comes from the asset: prompt, shape, type, place.
        $this->assertSame('A lighthouse at dusk', $created['prompt']);
        $this->assertSame('0.5K', $created['upscaledFrom']);
        $this->assertSame('1:1', $created['aspectRatio']);
        $this->assertSame('webp', $created['fileType']);
        $this->assertSame('heroes', $created['folder']);
        $this->assertSame('a-lighthouse-at-dusk-4k', $created['items'][0]['filename']);

        $sent = collect(Http::recorded())->last()[0];

        $this->assertSame('image/webp', $sent['input'][1]['mime_type']);
        $this->assertSame(base64_encode($stored), $sent['input'][1]['data']);

        // The asset it came from is untouched.
        $this->assertSame($stored, Storage::disk('assets')->get('heroes/lighthouse.webp'));
    }

    #[Test]
    public function an_asset_darkroom_did_not_make_can_still_be_upscaled()
    {
        Storage::disk('assets')->put('blog/by-hand.jpg', $this->jpeg(64, 36));
        Asset::make()->container('assets')->path('blog/by-hand.jpg')->save();

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $created = $this->upscale(['asset' => 'assets::blog/by-hand.jpg', 'model' => 'gemini-3-pro-image', 'quality' => '2K'])
            ->assertCreated()
            ->json();

        // No ratio is known, so the model takes its shape from the image.
        $this->assertSame('auto', $created['aspectRatio']);
        $this->assertSame('by-hand.jpg', $created['prompt']);

        Http::assertSent(fn (Request $request) => ! array_key_exists('aspect_ratio', $request['response_format']));
    }

    #[Test]
    public function someone_elses_draft_cannot_be_upscaled()
    {
        $draft = $this->draft();

        $this->actingAs($this->userWith(['use darkroom', 'upload assets assets', 'view assets assets'], 'other'));

        Http::fake();

        $this->upscale(['batch' => $draft['id'], 'index' => 1, 'model' => 'gemini-3-pro-image', 'quality' => '2K'])->assertForbidden();

        Http::assertNothingSent();
    }

    #[Test]
    public function an_asset_needs_view_and_upload_permission_to_be_upscaled()
    {
        $draft = $this->draft();
        $this->postJson($draft['items'][0]['urls']['save'], ['filename' => 'lighthouse'])->assertStatus(202);

        Http::fake();

        $payload = ['asset' => 'assets::blog/lighthouse.jpg', 'model' => 'gemini-3-pro-image', 'quality' => '2K'];

        $this->actingAs($this->userWith(['use darkroom'], 'blind'));
        $this->upscale($payload)->assertForbidden();

        $this->actingAs($this->userWith(['use darkroom', 'view assets assets'], 'viewer'));
        $this->upscale($payload)->assertStatus(422)->assertJsonValidationErrors('container');

        $this->upscale(['asset' => 'assets::blog/missing.jpg', 'model' => 'gemini-3-pro-image', 'quality' => '2K'])->assertNotFound();

        Http::assertNothingSent();
    }

    #[Test]
    public function upscaling_waits_its_turn_like_any_other_generation()
    {
        $draft = $this->draft();

        app(BatchStore::class)->create(['user' => 'super', 'prompt' => 'Still going'], 1);

        Http::fake();

        $this->upscale(['batch' => $draft['id'], 'index' => 1, 'model' => 'gemini-3-pro-image', 'quality' => '2K'])
            ->assertStatus(409)
            ->assertJson(['code' => 'in_flight']);

        Http::assertNothingSent();
    }
}
