<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

class RevisionThreadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->container();
        $this->actingAs($this->superUser());

        // Anything sent an image to change answers with a different one.
        Http::fake(fn (Request $request) => Http::response($this->interactionsResponse(
            is_array($request['input']) ? $this->jpeg(96, 72) : $this->jpeg(64, 48)
        )));
    }

    protected function draft(): array
    {
        $created = $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload([
            'prompt' => 'A young woman looking at her phone',
            'aspect_ratio' => '4:3',
        ]))->assertCreated()->json();

        return $this->getJson($created['urls']['show'])->json();
    }

    /**
     * Revise an unsaved image, or a saved one when given an asset id, and
     * return the new round as the page sees it once it has finished.
     */
    protected function round(array $from, string $note = 'Remove this door'): array
    {
        $created = $this->postJson(cp_route('darkroom.revisions.store'), array_merge($from, [
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
        return app(BatchStore::class)->find($batch['id']);
    }

    protected function save(array $batch, string $filename): string
    {
        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => $filename])->assertStatus(202);

        return $this->getJson($batch['urls']['show'])->json('items.0.asset.id');
    }

    protected function feed(string $thread, ?string $asset = null)
    {
        return $this->getJson(cp_route('darkroom.threads.show', $thread).($asset ? '?asset='.urlencode($asset) : ''));
    }

    #[Test]
    public function the_first_round_starts_a_thread_from_the_original()
    {
        $draft = $this->draft();
        $first = $this->round($this->target($draft));

        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $first['thread']['id']);
        $this->assertNull($first['thread']['parent']);
        $this->assertSame(1, $first['thread']['depth']);
        $this->assertSame(['batch' => $draft['id'], 'index' => 1], $this->stored($first)['thread']['origin']);
    }

    #[Test]
    public function a_round_of_a_round_carries_the_rounds_before_it()
    {
        $first = $this->round($this->target($this->draft()), 'Remove this door');
        $second = $this->round($this->target($first), 'Make the city taller');

        $this->assertSame($first['thread']['id'], $second['thread']['id']);
        $this->assertSame($first['id'], $second['thread']['parent']);
        $this->assertSame(2, $second['thread']['depth']);

        $ancestors = $this->stored($second)['thread']['ancestors'];
        $this->assertCount(1, $ancestors);
        $this->assertSame($first['id'], $ancestors[0]['id']);
        $this->assertSame('Remove this door', $ancestors[0]['notes'][0]['text']);
    }

    #[Test]
    public function revising_an_earlier_round_branches_from_it()
    {
        $first = $this->round($this->target($this->draft()));
        $this->round($this->target($first), 'Make the city taller');
        $branch = $this->round($this->target($first), 'Add a red kite');

        $this->assertSame($first['id'], $branch['thread']['parent']);
        $this->assertSame([$first['id']], array_column($this->stored($branch)['thread']['ancestors'], 'id'));
    }

    #[Test]
    public function saving_a_round_stamps_the_rounds_that_led_to_it()
    {
        $draft = $this->draft();
        $first = $this->round($this->target($draft), 'Remove this door');
        $second = $this->round($this->target($first), 'Make the city taller');
        $branch = $this->round($this->target($first), 'Add a red kite');

        // Saved after the rounds were made: the stamp still finds them.
        $origin = $this->save($draft, 'original');
        $firstAsset = $this->save($first, 'first');
        $id = $this->save($second, 'second');

        $thread = Asset::find($id)->get('darkroom')['thread'];

        $this->assertSame($first['thread']['id'], $thread['id']);
        $this->assertSame(['asset' => $origin], $thread['origin']);
        // Its own line only: the abandoned branch is not part of how it was made.
        $this->assertSame([$first['id'], $second['id']], array_column($thread['rounds'], 'id'));
        $this->assertSame($firstAsset, $thread['rounds'][0]['asset']);
        $this->assertSame('Make the city taller', $thread['rounds'][1]['notes'][0]['text']);
        $this->assertSame([96, 72], $thread['rounds'][1]['size']);
        $this->assertNotContains($branch['id'], array_column($thread['rounds'], 'id'));

        $listed = collect($this->getJson(cp_route('darkroom.history.index'))->json('items'))->firstWhere('id', $id);
        $this->assertSame($first['thread']['id'], $listed['thread']);
        $this->assertSame(2, $listed['rounds']);
    }

    #[Test]
    public function revising_a_saved_round_from_history_continues_its_thread()
    {
        $first = $this->round($this->target($this->draft()));
        $second = $this->round($this->target($first), 'Make the city taller');
        $id = $this->save($second, 'second');

        $third = $this->round(['asset' => $id], 'Add a red kite');

        $this->assertSame($first['thread']['id'], $third['thread']['id']);
        $this->assertSame($second['id'], $third['thread']['parent']);
        $this->assertSame(3, $third['thread']['depth']);
        $this->assertSame($id, $this->stored($third)['thread']['ancestors'][1]['asset']);
    }

    #[Test]
    public function the_feed_shows_every_round_with_where_it_came_from()
    {
        $draft = $this->draft();
        $first = $this->round($this->target($draft), 'Remove this door');
        $second = $this->round($this->target($first), 'Make the city taller');
        $branch = $this->round($this->target($first), 'Add a red kite');

        $this->deleteJson($second['items'][0]['urls']['destroy'])->assertOk();

        $feed = $this->feed($first['thread']['id'])->assertOk()->json();

        $this->assertSame('A young woman looking at her phone', $feed['prompt']);
        $this->assertSame($draft['id'], $feed['origin']['batch']);
        $this->assertNotNull($feed['origin']['image']);
        $this->assertSame([$first['id'], $second['id'], $branch['id']], array_column($feed['rounds'], 'id'));
        $this->assertSame([null, $first['id'], $first['id']], array_column($feed['rounds'], 'parent'));
        $this->assertSame('Make the city taller', $feed['rounds'][1]['notes'][0]['text']);
        $this->assertSame('Nano Banana Pro', $feed['rounds'][0]['modelLabel']);
        $this->assertSame(0.134, $feed['rounds'][0]['price']);
        $this->assertSame('complete', $feed['rounds'][0]['batch']['items'][0]['status']);
        $this->assertSame('discarded', $feed['rounds'][1]['batch']['items'][0]['status']);

        $this->feed(strtolower((string) \Illuminate\Support\Str::ulid()))->assertNotFound();
    }

    #[Test]
    public function a_saved_image_brings_back_its_rounds_after_temporary_storage_is_gone()
    {
        $this->travelTo('2026-10-02 12:00:00');

        $first = $this->round($this->target($this->draft()), 'Remove this door');
        $second = $this->round($this->target($first), 'Make the city taller');
        $id = $this->save($second, 'second');

        $this->travelTo('2026-10-04 12:00:00');
        app(BatchStore::class)->prune();

        $feed = $this->feed($first['thread']['id'], $id)->assertOk()->json();

        $this->assertSame([$first['id'], $second['id']], array_column($feed['rounds'], 'id'));
        $this->assertNull($feed['rounds'][0]['batch']);
        // Round 1's picture was kept in the library when round 2 was saved.
        $this->assertSame('assets::blog/revisions/second-round-1.jpg', $feed['rounds'][0]['asset']['id']);
        $this->assertSame($id, $feed['rounds'][1]['asset']['id']);
        $this->assertSame(cp_route('darkroom.assets.preview', ['asset' => $id]), $feed['rounds'][1]['asset']['preview']);
    }

    #[Test]
    public function the_feed_shows_only_your_own_rounds_and_images_you_can_see()
    {
        $first = $this->round($this->target($this->draft()));
        $id = $this->save($first, 'first');

        // Someone else revises the saved image, joining the same thread.
        $this->actingAs($this->userWith(['use darkroom', 'view assets assets', 'upload assets assets']));
        $theirs = $this->round(['asset' => $id], 'Add a red kite');

        $this->assertSame($first['thread']['id'], $theirs['thread']['id']);
        $this->assertSame([$first['id'], $theirs['id']], array_column($this->feed($first['thread']['id'], $id)->json('rounds'), 'id'));

        $this->actingAs($this->superUser());
        $this->assertSame([$first['id']], array_column($this->feed($first['thread']['id'])->json('rounds'), 'id'));

        $this->actingAs($this->userWith(['use darkroom'], 'outsider'));
        $this->feed($first['thread']['id'], $id)->assertForbidden();
    }

    #[Test]
    public function the_working_area_shows_one_card_per_thread()
    {
        $draft = $this->draft();
        $first = $this->round($this->target($draft));
        $second = $this->round($this->target($first), 'Make the city taller');

        $this->get(cp_route('darkroom.index'))->assertInertia(fn ($page) => $page
            ->where('batches.0.id', $second['id'])
            ->where('batches.1.id', $draft['id'])
            ->has('batches', 2)
        );

        // Discarding the newest round brings back the one before it.
        $this->deleteJson($second['items'][0]['urls']['destroy']);
        $this->get(cp_route('darkroom.index'))->assertInertia(fn ($page) => $page
            ->where('batches.0.id', $first['id'])
            ->has('batches', 2)
        );

        // Once the newest round is saved, the thread is in History instead.
        $this->save($first, 'first');
        $this->get(cp_route('darkroom.index'))->assertInertia(fn ($page) => $page
            ->where('batches.0.id', $draft['id'])
            ->has('batches', 1)
        );
    }

    #[Test]
    public function a_saved_round_is_still_found_after_it_is_moved()
    {
        $first = $this->round($this->target($this->draft()));
        $id = $this->save($first, 'first');
        $this->round(['asset' => $id], 'Add a red kite');

        // Moved in the asset browser: its id is its path, so the id the batch
        // recorded at save time no longer exists.
        Asset::find($id)->move('moved');
        $moved = 'assets::moved/first.jpg';
        $this->assertNull(Asset::find($id));

        $rounds = $this->feed($first['thread']['id'])->assertOk()->json('rounds');

        $this->assertSame($moved, $rounds[0]['asset']['id']);
        $this->assertSame(cp_route('darkroom.assets.preview', ['asset' => $moved]), $rounds[0]['asset']['preview']);
    }

    #[Test]
    public function revising_the_original_again_stays_in_the_thread_the_panel_is_showing()
    {
        $draft = $this->draft();
        $first = $this->round($this->target($draft));

        $again = $this->postJson(cp_route('darkroom.revisions.store'), [
            ...$this->target($draft),
            'thread' => $first['thread']['id'],
            'model' => 'gemini-3-pro-image',
            'quality' => '1K',
            'notes' => [['x' => 0.5, 'y' => 0.5, 'text' => 'Try a kite instead']],
        ])->assertCreated()->json();

        $this->assertSame($first['thread']['id'], $again['thread']['id']);
        $this->assertNull($again['thread']['parent']);
        $this->assertSame([$first['id'], $again['id']], array_column($this->feed($first['thread']['id'])->json('rounds'), 'id'));

        // Not that thread's original: a thread of its own.
        $other = $this->postJson(cp_route('darkroom.revisions.store'), [
            ...$this->target($this->draft()),
            'thread' => $first['thread']['id'],
            'model' => 'gemini-3-pro-image',
            'quality' => '1K',
            'notes' => [['x' => 0.5, 'y' => 0.5, 'text' => 'Something else']],
        ])->assertCreated()->json();

        $this->assertNotSame($first['thread']['id'], $other['thread']['id']);
    }

    #[Test]
    public function a_thread_being_worked_on_is_not_pruned_from_under_it()
    {
        $this->travelTo('2026-10-03 09:00:00');
        $first = $this->round($this->target($this->draft()));

        $this->travelTo('2026-10-03 20:00:00');
        $second = $this->round($this->target($first), 'Make the city taller');

        // A day after round 1, but round 2 is only 13 hours old.
        $this->travelTo('2026-10-04 09:30:00');
        app(BatchStore::class)->prune();

        $this->assertNotNull(app(BatchStore::class)->find($first['id']));
        $this->assertNotNull(app(BatchStore::class)->original($first['id'], 1));

        // A day after the last round, the whole thread goes.
        $this->travelTo('2026-10-04 20:30:00');
        app(BatchStore::class)->prune();

        $this->assertNull(app(BatchStore::class)->find($first['id']));
        $this->assertNull(app(BatchStore::class)->find($second['id']));
    }

    #[Test]
    public function a_thread_that_does_not_hold_together_on_an_asset_starts_a_new_one()
    {
        Storage::disk('assets')->put('blog/odd.jpg', $this->jpeg(80, 60));
        $asset = AssetContainer::find('assets')->makeAsset('blog/odd.jpg');
        $asset->set('darkroom', [
            'prompt' => 'A lighthouse',
            'thread' => ['id' => '../../etc', 'rounds' => [['id' => 'nonsense', 'notes' => 'no']]],
        ])->save();

        $round = $this->round(['asset' => $asset->id()]);

        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $round['thread']['id']);
        $this->assertNull($round['thread']['parent']);
        $this->assertSame(['asset' => $asset->id()], $this->stored($round)['thread']['origin']);
    }
}
