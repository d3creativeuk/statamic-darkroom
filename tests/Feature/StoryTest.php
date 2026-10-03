<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class StoryTest extends TestCase
{
    protected int $images = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container();
        $this->actingAs($this->superUser());

        Http::fake(fn (Request $request) => Http::response($this->interactionsResponse(
            $this->jpeg(90 + (++$this->images), 72)
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

    protected function round(array $target, string $note = 'Remove this door', ?string $general = null): array
    {
        $created = $this->postJson(cp_route('darkroom.revisions.store'), array_merge($target, [
            'model' => 'gemini-3-pro-image',
            'quality' => '1K',
            'notes' => [['x' => 0.25, 'y' => 0.75, 'text' => $note]],
            'general' => $general,
        ]))->assertCreated()->json();

        return $this->getJson($created['urls']['show'])->json();
    }

    protected function target(array $batch): array
    {
        return ['batch' => $batch['id'], 'index' => 1];
    }

    protected function save(array $batch, string $filename, array $where = []): string
    {
        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => $filename] + $where)->assertStatus(202);

        return $this->getJson($batch['urls']['show'])->json('items.0.asset.id');
    }

    protected function story(string $asset)
    {
        return $this->getJson(cp_route('darkroom.story.show', ['asset' => $asset]));
    }

    protected function forget(string $asset)
    {
        return $this->postJson(cp_route('darkroom.story.forget'), ['asset' => $asset]);
    }

    protected function revisions(): array
    {
        return Storage::disk('assets')->files('blog/revisions');
    }

    /**
     * Two rounds revising a new image, the second saved as blog/kite.jpg.
     *
     * @return array{0: array, 1: array, 2: string}
     */
    protected function kite(): array
    {
        $first = $this->round($this->target($this->draft()));
        $second = $this->round($this->target($first), 'Make the city taller', 'Warmer light');

        return [$first, $second, $this->save($second, 'kite')];
    }

    #[Test]
    public function it_shows_every_step_from_the_original_to_the_saved_image()
    {
        [$first, $second, $id] = $this->kite();

        $story = $this->story($id)->assertOk()->json();

        $this->assertSame($first['thread']['id'], $story['thread']);
        $this->assertSame('A young woman looking at her phone', $story['prompt']);
        $this->assertSame('blog/revisions/kite-original.jpg', $story['original']['path']);
        $this->assertSame('kept', $story['original']['kind']);
        $this->assertFalse($story['earlier']);

        $this->assertCount(2, $story['steps']);
        [$one, $two] = $story['steps'];

        $this->assertSame(1, $one['number']);
        $this->assertSame($first['id'], $one['round']);
        $this->assertSame([['x' => 0.25, 'y' => 0.75, 'text' => 'Remove this door']], $one['notes']);
        $this->assertSame('Nano Banana Pro', $one['modelLabel']);
        $this->assertSame('1K', $one['qualityLabel']);
        // Each step's notes were pinned on the image before it.
        $this->assertSame('blog/revisions/kite-original.jpg', $one['from']['path']);
        $this->assertSame('blog/revisions/kite-round-1.jpg', $one['result']['path']);

        $this->assertSame(2, $two['number']);
        $this->assertSame('Make the city taller', $two['notes'][0]['text']);
        $this->assertSame('Warmer light', $two['general']);
        $this->assertSame('blog/revisions/kite-round-1.jpg', $two['from']['path']);
        $this->assertSame('blog/kite.jpg', $two['result']['path']);
        $this->assertSame('saved', $two['result']['kind']);
        $this->assertStringContainsString('darkroom/assets/preview', $two['result']['preview']);

        $this->assertSame(['id' => $id, 'path' => 'blog/kite.jpg'], array_intersect_key($story['saved'], ['id' => 0, 'path' => 0]));
        $this->assertTrue($story['canDeleteHistory']);
        $this->assertTrue($story['canRevise']);
    }

    #[Test]
    public function an_earlier_round_saved_as_its_own_image_is_shown_as_that_image()
    {
        $first = $this->round($this->target($this->draft()));
        $second = $this->round($this->target($first), 'Make the city taller');
        $this->save($first, 'first');
        $id = $this->save($second, 'second');

        $step = $this->story($id)->json('steps.0.result');

        $this->assertSame('blog/first.jpg', $step['path']);
        $this->assertSame('saved', $step['kind']);
    }

    #[Test]
    public function a_step_the_viewer_may_not_see_is_hidden_but_still_counted()
    {
        Storage::fake('private');
        AssetContainer::make('private')->disk('private')->title('Private')->save();

        $start = $this->save($this->draft(), 'start', ['container' => 'private', 'folder' => 'blog']);
        $kite = $this->save($this->round(['asset' => $start]), 'kite', ['container' => 'assets', 'folder' => 'blog']);

        $this->actingAs($this->userWith(['use darkroom', 'view assets assets']));

        $story = $this->story($kite)->assertOk()->json();

        $this->assertSame(['hidden' => true], $story['original']);
        $this->assertSame(['hidden' => true], $story['steps'][0]['from']);
        $this->assertSame('blog/kite.jpg', $story['steps'][0]['result']['path']);
        $this->assertFalse($story['canDeleteHistory']);
        $this->assertFalse($story['canRevise']);
    }

    #[Test]
    public function the_story_needs_view_permission_on_the_image()
    {
        [, , $id] = $this->kite();

        $this->actingAs($this->userWith(['use darkroom']));
        $this->story($id)->assertForbidden();

        $this->actingAs($this->superUser());
        $this->story('assets::blog/missing.jpg')->assertNotFound();

        // An image that was never revised has no story.
        $plain = $this->save($this->draft(), 'plain');
        $this->story($plain)->assertNotFound();
    }

    #[Test]
    public function a_long_story_says_its_earliest_rounds_are_left_out()
    {
        [, , $id] = $this->kite();

        // As if it had been revised past the most rounds a saved image keeps.
        $asset = Asset::find($id);
        $stamp = $asset->get(SavedImages::KEY);
        array_shift($stamp['thread']['rounds']);
        $asset->set(SavedImages::KEY, $stamp)->save();

        $story = $this->story($id)->assertOk()->json();

        $this->assertTrue($story['earlier']);
        $this->assertCount(1, $story['steps']);
        // Its notes were pinned on the round before it, not on the original.
        $this->assertSame('blog/revisions/kite-round-1.jpg', $story['steps'][0]['from']['path']);
    }

    #[Test]
    public function the_story_holds_together_after_the_image_and_its_steps_are_moved()
    {
        [, , $id] = $this->kite();

        Asset::find($id)->move('archive', 'kite-final');
        Asset::find('assets::blog/revisions/kite-round-1.jpg')->move('old', 'step');

        $story = $this->story('assets::archive/kite-final.jpg')->assertOk()->json();

        $this->assertSame('old/step.jpg', $story['steps'][0]['result']['path']);
        $this->assertSame('old/step.jpg', $story['steps'][1]['from']['path']);
        $this->assertSame('archive/kite-final.jpg', $story['steps'][1]['result']['path']);
    }

    #[Test]
    public function deleting_the_history_removes_the_kept_steps_and_the_story_but_not_the_image()
    {
        [, , $id] = $this->kite();

        $result = $this->forget($id)->assertOk()->json();

        $this->assertEqualsCanonicalizing(['blog/revisions/kite-original.jpg', 'blog/revisions/kite-round-1.jpg'], $result['deleted']);
        $this->assertSame([], $result['kept']);
        $this->assertSame([], $this->revisions());

        Storage::disk('assets')->assertExists('blog/kite.jpg');
        $stamp = Asset::find($id)->get(SavedImages::KEY);
        $this->assertArrayNotHasKey('thread', $stamp);
        $this->assertArrayNotHasKey('revision', $stamp);
        $this->assertSame('A young woman looking at her phone', $stamp['prompt']);

        // Still in History, as a plain image.
        $this->assertNull($this->getJson(cp_route('darkroom.history.index'))->json('items.0.thread'));
        $this->story($id)->assertNotFound();
    }

    #[Test]
    public function deleting_the_history_keeps_steps_another_image_or_a_page_still_needs()
    {
        $first = $this->round($this->target($this->draft()));
        $left = $this->save($this->round($this->target($first), 'Make the city taller'), 'left');
        $right = $this->save($this->round($this->target($first), 'Add a red kite'), 'right');

        $kept = $this->forget($left)->assertOk()->json('kept');

        $this->assertEqualsCanonicalizing([
            ['path' => 'blog/revisions/left-original.jpg', 'reason' => 'shared'],
            ['path' => 'blog/revisions/left-round-1.jpg', 'reason' => 'shared'],
        ], $kept);
        $this->story($right)->assertOk()->assertJsonPath('steps.0.result.path', 'blog/revisions/left-round-1.jpg');

        Collection::make('pages')->save();
        Entry::make()->collection('pages')->id('home')->slug('home')->data(['title' => 'Home', 'hero' => 'blog/revisions/left-round-1.jpg'])->save();

        $result = $this->forget($right)->assertOk()->json();

        $this->assertSame(['blog/revisions/left-original.jpg'], $result['deleted']);
        $this->assertSame([['path' => 'blog/revisions/left-round-1.jpg', 'reason' => 'used']], $result['kept']);
    }

    #[Test]
    public function deleting_the_history_of_an_earlier_round_keeps_a_copy_for_the_image_after_it()
    {
        $first = $this->round($this->target($this->draft()));
        $second = $this->round($this->target($first), 'Make the city taller');
        $a = $this->save($first, 'first');
        $b = $this->save($second, 'second');

        $this->forget($a)->assertOk();

        Storage::disk('assets')->assertExists('blog/first.jpg');
        $this->story($a)->assertNotFound();
        $this->story($b)->assertOk()->assertJsonPath('steps.0.result.path', 'blog/revisions/second-round-1.jpg');
        $this->story($b)->assertJsonPath('original.path', 'blog/revisions/first-original.jpg');
    }

    #[Test]
    public function deleting_the_history_needs_edit_permission_on_the_image()
    {
        [, , $id] = $this->kite();

        $this->actingAs($this->userWith(['use darkroom', 'view assets assets']));
        $this->forget($id)->assertForbidden();

        $this->assertCount(2, $this->revisions());
        $this->assertArrayHasKey('thread', Asset::find($id)->get(SavedImages::KEY));
    }

    #[Test]
    public function steps_the_user_may_not_delete_are_kept_and_listed()
    {
        [, , $id] = $this->kite();

        $this->actingAs($this->userWith(['use darkroom', 'view assets assets', 'edit assets assets']));

        $result = $this->forget($id)->assertOk()->json();

        $this->assertSame([], $result['deleted']);
        $this->assertEqualsCanonicalizing(['permission'], array_unique(array_column($result['kept'], 'reason')));
        $this->assertCount(2, $this->revisions());
        $this->assertArrayNotHasKey('thread', Asset::find($id)->get(SavedImages::KEY));
    }
}
