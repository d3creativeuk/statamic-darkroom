<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Tests\TestCase;
use D3Creative\Darkroom\Usage\UsageLog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;

class RevisionMemoryTest extends TestCase
{
    // How many turns Google has kept, for handing out ids.
    protected int $turns = 0;

    /** @var array<int, string> Conversations Google no longer has. */
    protected array $gone = [];

    protected bool $overloaded = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container();
        $this->actingAs($this->superUser());

        Sleep::fake();

        // One closure for every call, because the first matching fake wins. A
        // kept turn gets the next id; carrying on a conversation Google no
        // longer has gets the 404 it really answers with.
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), ':generateContent')) {
                return Http::response($this->generateContentResponse($this->jpeg(96, 72)));
            }

            $previous = $request['previous_interaction_id'] ?? null;

            if ($previous !== null && in_array($previous, $this->gone, true)) {
                return Http::response($this->fixture('interactions-error-not-found'), 404);
            }

            if ($previous !== null && $this->overloaded) {
                return Http::response(['error' => ['code' => 'unavailable', 'message' => 'Overloaded']], 503);
            }

            $changed = is_array($request['input']) || $previous !== null;

            return Http::response($this->interactionsResponse(
                $changed ? $this->jpeg(96, 72) : $this->jpeg(64, 48),
                $request['store'] ? 'v1_Turn'.(++$this->turns).'kept' : null,
            ));
        });
    }

    protected function draft(): array
    {
        $created = $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload([
            'prompt' => 'A young woman looking at her phone',
            'aspect_ratio' => '4:3',
        ]))->assertCreated()->json();

        return $this->getJson($created['urls']['show'])->json();
    }

    protected function round(array $target, string $note = 'Remove this door'): array
    {
        $created = $this->postJson(cp_route('darkroom.revisions.store'), array_merge($target, [
            'model' => 'gemini-3-pro-image',
            'quality' => '1K',
            'notes' => [['x' => 0.5, 'y' => 0.5, 'text' => $note]],
        ]))->assertCreated()->json();

        return $this->getJson($created['urls']['show'])->json();
    }

    protected function target(array $batch): array
    {
        return ['batch' => $batch['id'], 'index' => 1];
    }

    protected function stored(array $batch): array
    {
        return app(BatchStore::class)->item($batch['id'], 1);
    }

    protected function sent(int $back = 0): Request
    {
        $recorded = Http::recorded();

        return $recorded[count($recorded) - 1 - $back][0];
    }

    #[Test]
    public function the_first_round_is_kept_and_sends_the_image()
    {
        $draft = $this->draft();

        // A new image is never kept on Google's side.
        $this->assertFalse($this->sent()['store']);

        $first = $this->round($this->target($draft));

        $sent = $this->sent();
        $this->assertTrue($sent['store']);
        $this->assertArrayNotHasKey('previous_interaction_id', $sent->data());
        $this->assertStringStartsWith('Edit this image. Make only these changes:', $sent['input'][0]['text']);
        $this->assertSame('image', $sent['input'][1]['type']);

        $this->assertSame('v1_Turn1kept', $this->stored($first)['interaction']);
        $this->assertNull($first['items'][0]['memory']);
        // The conversation id stays on the server.
        $this->assertArrayNotHasKey('interaction', $first['items'][0]);
    }

    #[Test]
    public function the_next_round_carries_the_conversation_on_with_only_the_notes()
    {
        $first = $this->round($this->target($this->draft()));
        $second = $this->round($this->target($first), 'Make the city taller');

        $sent = $this->sent();
        $this->assertSame('v1_Turn1kept', $sent['previous_interaction_id']);
        $this->assertTrue($sent['store']);
        $this->assertIsString($sent['input']);
        $this->assertStringStartsWith('Edit your last image. Make only these changes:', $sent['input']);
        $this->assertStringContainsString('Make the city taller', $sent['input']);

        $this->assertSame('complete', $second['items'][0]['status']);
        $this->assertSame('continued', $second['items'][0]['memory']);
        $this->assertSame('v1_Turn2kept', $this->stored($second)['interaction']);
    }

    #[Test]
    public function a_branch_carries_on_the_conversation_of_the_round_it_starts_from()
    {
        $first = $this->round($this->target($this->draft()));
        $this->round($this->target($first), 'Make the city taller');
        $this->round($this->target($first), 'Add a red kite');

        $this->assertSame('v1_Turn1kept', $this->sent()['previous_interaction_id']);
    }

    #[Test]
    public function a_conversation_google_no_longer_has_is_sent_again_from_the_image()
    {
        $this->travelTo('2026-10-02 12:00:00');

        $first = $this->round($this->target($this->draft()));
        $this->gone[] = 'v1_Turn1kept';

        $second = $this->round($this->target($first), 'Make the city taller');

        $tried = $this->sent(1);
        $again = $this->sent();

        $this->assertSame('v1_Turn1kept', $tried['previous_interaction_id']);
        $this->assertArrayNotHasKey('previous_interaction_id', $again->data());
        $this->assertStringStartsWith('Edit this image.', $again['input'][0]['text']);
        $this->assertSame('image', $again['input'][1]['type']);
        $this->assertTrue($again['store']);

        $this->assertSame('complete', $second['items'][0]['status']);
        $this->assertSame('lost', $second['items'][0]['memory']);

        // Straight on, and charged once.
        Sleep::assertNeverSlept();
        $this->assertCount(1, collect(app(UsageLog::class)->entries('2026-10'))->where('batch', $second['id']));
    }

    #[Test]
    public function a_conversation_older_than_google_keeps_it_is_not_tried()
    {
        $this->travelTo('2026-08-01 12:00:00');
        $first = $this->round($this->target($this->draft()));

        $this->travelTo('2026-09-25 12:00:00');
        $second = $this->round($this->target($first));

        $this->assertArrayNotHasKey('previous_interaction_id', $this->sent()->data());
        $this->assertSame('image', $this->sent()['input'][1]['type']);
        $this->assertNull($second['items'][0]['memory']);
    }

    #[Test]
    public function a_saved_round_carries_its_conversation_on_unless_the_image_has_changed_since()
    {
        $first = $this->round($this->target($this->draft()));
        $this->postJson($first['items'][0]['urls']['save'], ['filename' => 'first'])->assertStatus(202);
        $id = $this->getJson($first['urls']['show'])->json('items.0.asset.id');

        $second = $this->round(['asset' => $id]);

        $this->assertSame('v1_Turn1kept', $this->sent()['previous_interaction_id']);
        $this->assertSame('continued', $second['items'][0]['memory']);

        // Cropped since it was saved: the model would carry on from its own
        // uncropped image, so this round starts from the image as it is now.
        $asset = Asset::find($id);
        Storage::disk('assets')->put($asset->path(), $this->jpeg(50, 50));
        $asset->cacheStore()->forget($asset->metaCacheKey());
        $asset->writeMeta($asset->generateMeta());
        $this->assertSame(50, Asset::find($id)->width());

        $third = $this->round(['asset' => $id]);

        $this->assertArrayNotHasKey('previous_interaction_id', $this->sent()->data());
        $this->assertSame(base64_encode($this->jpeg(50, 50)), $this->sent()['input'][1]['data']);
        $this->assertNull($third['items'][0]['memory']);
    }

    #[Test]
    public function with_memory_switched_off_nothing_is_kept_or_carried_on()
    {
        $first = $this->round($this->target($this->draft()));

        config()->set('statamic-darkroom.revise.remember', false);

        $second = $this->round($this->target($first));

        $this->assertFalse($this->sent()['store']);
        $this->assertArrayNotHasKey('previous_interaction_id', $this->sent()->data());
        $this->assertSame('image', $this->sent()['input'][1]['type']);
        $this->assertNull($second['items'][0]['memory']);
    }

    #[Test]
    public function the_older_api_never_carries_a_conversation_on()
    {
        $first = $this->round($this->target($this->draft()));

        config()->set('statamic-darkroom.api', 'generate_content');

        $second = $this->round($this->target($first));

        $this->assertStringContainsString(':generateContent', $this->sent()->url());
        // The image goes with the notes, as every round did before memory.
        $this->assertSame(base64_encode(app(BatchStore::class)->source($second['id'])), $this->sent()['contents'][0]['parts'][1]['inlineData']['data']);
        $this->assertSame('complete', $second['items'][0]['status']);
        $this->assertNull($second['items'][0]['memory']);
    }

    #[Test]
    public function upscales_are_still_never_kept()
    {
        $draft = $this->draft();

        $this->postJson(cp_route('darkroom.upscales.store'), ['batch' => $draft['id'], 'index' => 1, 'model' => 'gemini-3-pro-image', 'quality' => '4K'])
            ->assertCreated();

        $this->assertFalse($this->sent()['store']);
    }

    #[Test]
    public function trying_again_carries_the_conversation_on_again()
    {
        $first = $this->round($this->target($this->draft()));

        $this->overloaded = true;
        $second = $this->round($this->target($first));

        $this->assertSame('failed', $second['items'][0]['status']);
        $this->assertTrue($second['items'][0]['error']['retryable']);

        $this->overloaded = false;
        $this->postJson($second['items'][0]['urls']['retry'])->assertStatus(202);

        $this->assertSame('v1_Turn1kept', $this->sent()['previous_interaction_id']);
        $this->assertSame('continued', $this->getJson($second['urls']['show'])->json('items.0.memory'));
    }
}
