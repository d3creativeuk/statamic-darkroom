<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class BatchLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->container();
        $this->actingAs($this->superUser());
    }

    protected function store(): BatchStore
    {
        return app(BatchStore::class);
    }

    /**
     * Generate and return the finished batch as the page would see it.
     */
    protected function generate(array $overrides = []): array
    {
        return $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload($overrides))
            ->assertCreated()
            ->json();
    }

    #[Test]
    public function generating_answers_straight_away_and_finishes_in_the_background()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse($this->jpeg(320, 180)))]);

        $created = $this->generate();

        // The response is written before the job runs, so it still shows the
        // image as waiting.
        $this->assertCount(1, $created['items']);
        $this->assertSame('pending', $created['items'][0]['status']);
        $this->assertNull($created['items'][0]['urls']['preview']);

        $batch = $this->getJson($created['urls']['show'])->assertOk()->json();

        $this->assertSame('complete', $batch['items'][0]['status']);
        $this->assertSame(320, $batch['items'][0]['width']);
        $this->assertSame(180, $batch['items'][0]['height']);
        $this->assertSame(1, $batch['items'][0]['attempts']);
        $this->assertSame('Nano Banana Pro', $batch['modelLabel']);
        $this->assertSame('blog', $batch['folder']);
    }

    #[Test]
    public function the_request_carries_the_chosen_model_quality_and_aspect_ratio()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->generate(['model' => 'gemini-3.1-flash-image', 'quality' => '4K', 'aspect_ratio' => '4:3']);

        Http::assertSent(fn (Request $request) => $request['model'] === 'gemini-3.1-flash-image'
            && $request['response_format']['image_size'] === '4K'
            && $request['response_format']['aspect_ratio'] === '4:3');
    }

    #[Test]
    public function the_three_required_aspect_ratios_are_accepted_for_every_model()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        foreach (array_keys(config('statamic-darkroom.models')) as $model) {
            foreach (['4:3', '1:1', '16:9'] as $ratio) {
                $this->generate(['model' => $model, 'quality' => '1K', 'aspect_ratio' => $ratio]);
            }
        }

        Http::assertSentCount(9);
    }

    #[Test]
    public function auto_is_accepted_and_sends_no_aspect_ratio()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->generate(['aspect_ratio' => 'auto']);

        Http::assertSent(fn (Request $request) => ! array_key_exists('aspect_ratio', $request['response_format']));
    }

    #[Test]
    public function the_preview_is_served_privately_as_a_jpeg()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse($this->jpeg(320, 180)))]);

        $created = $this->generate();
        $batch = $this->getJson($created['urls']['show'])->json();

        $response = $this->get($batch['items'][0]['urls']['preview'])->assertOk();

        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function the_preview_is_scaled_down_but_the_original_is_kept_whole()
    {
        config()->set('statamic-darkroom.preview.max_edge', 100);

        $original = $this->jpeg(400, 300);
        Http::fake(['*' => Http::response($this->interactionsResponse($original))]);

        $created = $this->generate();

        $this->assertSame($original, $this->store()->original($created['id'], 1));

        [$width, $height] = getimagesizefromstring(
            $this->store()->disk()->get($this->store()->previewPath($created['id'], 1))
        );

        $this->assertSame([100, 75], [$width, $height]);
    }

    #[Test]
    public function batch_size_defaults_to_one()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $created = $this->generate();

        $this->assertCount(1, $created['items']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_batch_sends_one_request_per_image()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $created = $this->generate(['batch_size' => 3]);
        $batch = $this->getJson($created['urls']['show'])->json();

        Http::assertSentCount(3);

        $this->assertSame(['complete', 'complete', 'complete'], array_column($batch['items'], 'status'));
        $this->assertSame([1, 2, 3], array_column($batch['items'], 'index'));
    }

    #[Test]
    public function batch_filenames_are_numbered_and_a_single_image_is_not()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $single = $this->generate(['prompt' => 'A Red Bicycle, leaning on a wall!']);

        $this->assertSame('a-red-bicycle-leaning-on-a', $single['items'][0]['filename']);

        $this->getJson($single['urls']['show']);
        $this->delete($single['urls']['destroy'])->assertNoContent();

        $batch = $this->generate(['prompt' => 'A Red Bicycle', 'batch_size' => 2]);

        $this->assertSame(['a-red-bicycle-1', 'a-red-bicycle-2'], array_column($batch['items'], 'filename'));
    }

    #[Test]
    public function one_failed_image_does_not_sink_the_others()
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->interactionsResponse())
            ->push($this->fixture('interactions-error-400'), 400)
            ->push($this->interactionsResponse()),
        ]);

        $created = $this->generate(['batch_size' => 3]);
        $batch = $this->getJson($created['urls']['show'])->json();

        $this->assertSame(['complete', 'failed', 'complete'], array_column($batch['items'], 'status'));
        $this->assertSame('invalid_request', $batch['items'][1]['error']['code']);
        $this->assertFalse($batch['items'][1]['error']['retryable']);
        $this->assertNull($batch['items'][1]['urls']['preview']);
    }

    #[Test]
    public function a_batch_larger_than_the_maximum_is_refused()
    {
        Http::fake();

        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload(['batch_size' => 5]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('batch_size');

        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload(['batch_size' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('batch_size');

        Http::assertNothingSent();
    }

    #[Test]
    public function a_quality_the_model_cannot_produce_is_refused_before_anything_is_sent()
    {
        Http::fake();

        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload([
            'model' => 'gemini-3.1-flash-lite-image',
            'quality' => '2K',
        ]))->assertStatus(422)->assertJsonValidationErrors('quality');

        Http::assertNothingSent();
    }

    #[Test]
    public function an_unknown_aspect_ratio_model_or_file_type_is_refused()
    {
        Http::fake();

        foreach ([
            'aspect_ratio' => '7:5',
            'model' => 'gemini-2.5-flash-image',
            'file_type' => 'gif',
            'container' => 'nowhere',
        ] as $field => $value) {
            $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload([$field => $value]))
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function without_an_api_key_nothing_is_generated()
    {
        Http::fake();
        config()->set('statamic-darkroom.api_key', null);

        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload())
            ->assertStatus(422)
            ->assertJson(['code' => 'missing_api_key']);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_second_batch_is_refused_while_one_is_still_generating()
    {
        Http::fake();

        // A batch whose job has not run: its image is still pending.
        $this->store()->create(['user' => 'super', 'prompt' => 'Waiting'], 1);

        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload())
            ->assertStatus(409)
            ->assertJson(['code' => 'in_flight']);

        Http::assertNothingSent();
    }

    #[Test]
    public function someone_elses_batch_in_flight_does_not_block_you()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->store()->create(['user' => 'someone-else', 'prompt' => 'Waiting'], 1);

        $this->generate();

        Http::assertSentCount(1);
    }

    #[Test]
    public function an_image_that_never_finishes_is_eventually_marked_as_timed_out()
    {
        $batch = $this->store()->create(['user' => 'super', 'prompt' => 'Stuck', 'model' => 'gemini-3-pro-image',
            'quality' => '2K', 'aspect_ratio' => '16:9', 'file_type' => 'jpg', 'container' => 'assets'], 1);

        $this->store()->updateItem($batch['id'], 1, ['status' => 'generating']);

        $this->travel(10)->minutes();

        $item = $this->getJson(cp_route('darkroom.batches.show', $batch['id']))->assertOk()->json('items.0');

        $this->assertSame('failed', $item['status']);
        $this->assertSame('timed_out', $item['error']['code']);
        $this->assertTrue($item['error']['retryable']);

        // Settled for good, so it no longer blocks a new batch.
        $this->assertFalse($this->store()->inFlightFor('super'));
    }

    #[Test]
    public function a_failed_image_can_be_tried_again_on_its_own()
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->interactionsResponse())
            ->push($this->fixture('interactions-error-400'), 400)
            ->push($this->interactionsResponse($this->jpeg(64, 64))),
        ]);

        $created = $this->generate(['batch_size' => 2]);
        $batch = $this->getJson($created['urls']['show'])->json();

        $this->assertSame(['complete', 'failed'], array_column($batch['items'], 'status'));

        // Retrying an image that did not fail is refused.
        $this->postJson($batch['items'][0]['urls']['retry'])->assertStatus(409);

        $this->postJson($batch['items'][1]['urls']['retry'])->assertStatus(202);

        $batch = $this->getJson($created['urls']['show'])->json();

        $this->assertSame(['complete', 'complete'], array_column($batch['items'], 'status'));
        $this->assertSame(64, $batch['items'][1]['width']);
        $this->assertNull($batch['items'][1]['error']);

        // Two for the batch, one for the retry. The good image was not redone.
        Http::assertSentCount(3);
    }

    #[Test]
    public function discarding_an_image_removes_its_files()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $created = $this->generate(['batch_size' => 2]);
        $batch = $this->getJson($created['urls']['show'])->json();

        $after = $this->deleteJson($batch['items'][0]['urls']['destroy'])->assertOk()->json();

        $this->assertSame(['discarded', 'complete'], array_column($after['items'], 'status'));
        $this->assertNull($this->store()->original($created['id'], 1));
        $this->assertFalse($this->store()->hasPreview($created['id'], 1));
        $this->assertNotNull($this->store()->original($created['id'], 2));
    }

    #[Test]
    public function discarding_a_batch_leaves_nothing_behind()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $created = $this->generate(['batch_size' => 2]);

        $this->deleteJson($created['urls']['destroy'])->assertNoContent();

        $this->assertNull($this->store()->find($created['id']));
        $this->assertSame([], $this->store()->disk()->allFiles('statamic-darkroom/batches'));
        $this->getJson($created['urls']['show'])->assertNotFound();
    }

    #[Test]
    public function a_batch_belongs_to_whoever_generated_it()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $created = $this->generate();
        $batch = $this->getJson($created['urls']['show'])->json();

        $this->actingAs($this->userWith(['use darkroom', 'upload assets assets'], 'other'));

        $this->getJson($created['urls']['show'])->assertForbidden();
        $this->get($batch['items'][0]['urls']['preview'])->assertForbidden();
        $this->postJson($batch['items'][0]['urls']['save'])->assertForbidden();
        $this->deleteJson($batch['items'][0]['urls']['destroy'])->assertForbidden();
        $this->deleteJson($created['urls']['destroy'])->assertForbidden();

        $this->assertNotNull($this->store()->find($created['id']));
    }

    #[Test]
    public function unfinished_batches_come_back_newest_first_for_a_page_reload()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $first = $this->generate(['prompt' => 'First']);
        $this->travel(2)->seconds();
        $second = $this->generate(['prompt' => 'Second']);

        $open = $this->store()->openFor('super');

        $this->assertSame([$second['id'], $first['id']], array_column($open, 'id'));

        // Once every image in a batch is dealt with, it stops coming back.
        $this->deleteJson(cp_route('darkroom.items.destroy', [$second['id'], 1]));

        $this->assertSame([$first['id']], array_column($this->store()->openFor('super'), 'id'));
        $this->assertSame([], $this->store()->openFor('someone-else'));
    }

    #[Test]
    public function old_batches_are_pruned()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $old = $this->generate(['prompt' => 'Old']);

        $this->travel(25)->hours();

        $new = $this->generate(['prompt' => 'New']);

        $this->artisan('darkroom:prune')->expectsOutput('Pruned 1 batch.')->assertSuccessful();

        $this->assertNull($this->store()->find($old['id']));
        $this->assertNotNull($this->store()->find($new['id']));
    }
}
