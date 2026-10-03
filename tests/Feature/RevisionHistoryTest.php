<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Revisions\ThreadAssets;
use D3Creative\Darkroom\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class RevisionHistoryTest extends TestCase
{
    // Every image Google sends back is a different size, so each step's
    // bytes can be told apart.
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

    protected function save(array $batch, string $filename): string
    {
        $this->postJson($batch['items'][0]['urls']['save'], ['filename' => $filename])->assertStatus(202);

        return $this->getJson($batch['urls']['show'])->json('items.0.asset.id');
    }

    protected function kept(string $path): ?array
    {
        return Asset::find('assets::'.$path)?->get(ThreadAssets::REVISION_KEY);
    }

    protected function revisions(): array
    {
        return Storage::disk('assets')->files('blog/revisions');
    }

    #[Test]
    public function saving_a_revised_image_keeps_the_original_and_every_round_before_it()
    {
        $draft = $this->draft();
        $first = $this->round($this->target($draft));
        $second = $this->round($this->target($first), 'Make the city taller');

        $store = app(BatchStore::class);
        $original = $store->original($draft['id'], 1);
        $roundOne = $store->original($first['id'], 1);

        $id = $this->save($second, 'kite');

        $this->assertSame('assets::blog/kite.jpg', $id);
        $this->assertSame(['blog/revisions/kite-original.jpg', 'blog/revisions/kite-round-1.jpg'], $this->revisions());

        // Full size, exactly as Google sent them.
        $this->assertSame($original, Storage::disk('assets')->get('blog/revisions/kite-original.jpg'));
        $this->assertSame($roundOne, Storage::disk('assets')->get('blog/revisions/kite-round-1.jpg'));

        $tag = $this->kept('blog/revisions/kite-round-1.jpg');
        $this->assertSame($first['thread']['id'], $tag['thread']);
        $this->assertSame($first['id'], $tag['round']);
        $this->assertSame(1, $tag['step']);
        $this->assertSame([$second['id']], $tag['saved']);
        $this->assertSame('A young woman looking at her phone', $tag['prompt']);
        $this->assertSame([getimagesizefromstring($roundOne)[0], 72], $tag['size']);
        $this->assertSame('origin', $this->kept('blog/revisions/kite-original.jpg')['round']);

        // Kept steps are tagged, not stamped: History lists only the image.
        $this->assertNull(Asset::find('assets::blog/revisions/kite-round-1.jpg')->get('darkroom'));
        $this->assertSame([$id], array_column($this->getJson(cp_route('darkroom.history.index'))->json('items'), 'id'));
        $this->assertNull($this->getJson($second['urls']['show'])->json('items.0.historyMissing'));
    }

    #[Test]
    public function a_step_that_is_already_an_asset_is_referred_to_not_copied()
    {
        $draft = $this->draft();
        $start = $this->save($draft, 'start');

        $first = $this->round(['asset' => $start]);
        $second = $this->round($this->target($first), 'Make the city taller');

        $this->save($first, 'first');
        $this->save($second, 'second');

        $this->assertSame([], $this->revisions());
        $this->assertSame(
            [$first['thread']['id'] => [$first['id'], $second['id']]],
            Asset::find($start)->get(ThreadAssets::ORIGIN_KEY),
        );

        // Tagged apart from its own stamp, which History reads.
        $this->assertArrayNotHasKey('origin_of', Asset::find($start)->get('darkroom'));
    }

    #[Test]
    public function two_saved_images_share_the_steps_they_have_in_common()
    {
        $first = $this->round($this->target($this->draft()));
        $left = $this->round($this->target($first), 'Make the city taller');
        $right = $this->round($this->target($first), 'Add a red kite');

        $this->save($left, 'left');
        $this->save($right, 'right');

        $this->assertSame(['blog/revisions/left-original.jpg', 'blog/revisions/left-round-1.jpg'], $this->revisions());
        $this->assertSame([$left['id'], $right['id']], $this->kept('blog/revisions/left-round-1.jpg')['saved']);
        $this->assertSame([$left['id'], $right['id']], $this->kept('blog/revisions/left-original.jpg')['saved']);
    }

    #[Test]
    public function a_step_whose_image_has_already_gone_is_noted()
    {
        $first = $this->round($this->target($this->draft()));
        $second = $this->round($this->target($first), 'Make the city taller');

        // Round 1's own image and the copy round 2 was made from are both gone.
        app(BatchStore::class)->deleteItemFiles($first['id'], 1);
        Storage::disk('local')->delete("statamic-darkroom/batches/{$second['id']}/source.bin");

        $this->save($second, 'kite');

        $this->assertSame(['blog/revisions/kite-original.jpg'], $this->revisions());
        $this->assertSame([1], $this->getJson($second['urls']['show'])->json('items.0.historyMissing'));
    }

    #[Test]
    public function nothing_is_kept_when_it_is_switched_off()
    {
        config()->set('statamic-darkroom.revise.history.save', false);

        $first = $this->round($this->target($this->draft()));
        $this->save($this->round($this->target($first)), 'kite');

        $this->assertSame([], $this->revisions());
    }

    #[Test]
    public function revising_a_kept_step_branches_from_its_round()
    {
        $first = $this->round($this->target($this->draft()));
        $second = $this->round($this->target($first), 'Make the city taller');
        $this->save($second, 'kite');

        $branch = $this->round(['asset' => 'assets::blog/revisions/kite-round-1.jpg'], 'Add a red kite');

        $this->assertSame($first['thread']['id'], $branch['thread']['id']);
        $this->assertSame($first['id'], $branch['thread']['parent']);
        $this->assertSame(2, $branch['thread']['depth']);
        $this->assertSame('A young woman looking at her phone', $branch['prompt']);
        $this->assertSame('4:3', $branch['aspectRatio']);

        // From the kept original: the start of the same thread.
        $again = $this->round(['asset' => 'assets::blog/revisions/kite-original.jpg'], 'Try something else');

        $this->assertSame($first['thread']['id'], $again['thread']['id']);
        $this->assertNull($again['thread']['parent']);
    }

    #[Test]
    public function the_history_of_images_saved_before_it_was_kept_is_filled_in_once()
    {
        config()->set('statamic-darkroom.revise.history.save', false);
        $first = $this->round($this->target($this->draft()));
        $this->save($this->round($this->target($first)), 'kite');

        config()->set('statamic-darkroom.revise.history.save', true);

        $this->get(cp_route('darkroom.index'))->assertOk();
        $this->assertSame(['blog/revisions/kite-original.jpg', 'blog/revisions/kite-round-1.jpg'], $this->revisions());

        $this->get(cp_route('darkroom.index'))->assertOk();
        $this->assertCount(2, $this->revisions());
    }

    #[Test]
    public function deleting_a_saved_image_deletes_the_steps_kept_for_it()
    {
        $first = $this->round($this->target($this->draft()));
        $id = $this->save($this->round($this->target($first)), 'kite');

        Asset::find($id)->delete();

        $this->assertSame([], $this->revisions());
    }

    #[Test]
    public function shared_steps_stay_until_no_saved_image_needs_them()
    {
        $first = $this->round($this->target($this->draft()));
        $left = $this->save($this->round($this->target($first), 'Make the city taller'), 'left');
        $right = $this->save($this->round($this->target($first), 'Add a red kite'), 'right');

        Asset::find($left)->delete();

        $this->assertCount(2, $this->revisions());

        Asset::find($right)->delete();

        $this->assertSame([], $this->revisions());
    }

    #[Test]
    public function a_step_used_on_the_site_is_kept()
    {
        $first = $this->round($this->target($this->draft()));
        $id = $this->save($this->round($this->target($first)), 'kite');

        Collection::make('pages')->save();
        Entry::make()->collection('pages')->id('home')->slug('home')->data(['title' => 'Home', 'hero' => 'blog/revisions/kite-round-1.jpg'])->save();

        Asset::find($id)->delete();

        $this->assertSame(['blog/revisions/kite-round-1.jpg'], $this->revisions());
        $this->assertSame([], $this->kept('blog/revisions/kite-round-1.jpg')['saved']);
    }

    #[Test]
    public function darkroom_trash_keeps_the_steps_until_the_image_is_deleted_for_good()
    {
        $first = $this->round($this->target($this->draft()));
        $id = $this->save($this->round($this->target($first)), 'kite');

        $this->postJson(cp_route('darkroom.trash.move'), ['assets' => [$id]])->assertOk();
        $this->assertCount(2, $this->revisions());

        $this->postJson(cp_route('darkroom.trash.destroy'), ['assets' => [$id]])->assertOk();
        $this->assertSame([], $this->revisions());
    }

    #[Test]
    public function deleting_an_earlier_round_saved_as_its_own_image_keeps_a_copy_for_later_ones()
    {
        $first = $this->round($this->target($this->draft()));
        $second = $this->round($this->target($first), 'Make the city taller');
        $a = $this->save($first, 'first');
        $this->save($second, 'second');

        $bytes = Storage::disk('assets')->get('blog/first.jpg');

        Asset::find($a)->delete();

        // Round 1 is now a kept step of the second image's history.
        Storage::disk('assets')->assertMissing('blog/first.jpg');
        $this->assertSame($bytes, Storage::disk('assets')->get('blog/revisions/second-round-1.jpg'));
        $this->assertSame([$second['id']], $this->kept('blog/revisions/second-round-1.jpg')['saved']);
        $this->assertSame($first['id'], $this->kept('blog/revisions/second-round-1.jpg')['round']);
    }

    #[Test]
    public function deleting_an_original_keeps_a_copy_for_the_images_made_from_it()
    {
        $start = $this->save($this->draft(), 'start');
        $kite = $this->round(['asset' => $start]);
        $this->save($kite, 'kite');

        $bytes = Storage::disk('assets')->get('blog/start.jpg');

        Asset::find($start)->delete();

        $this->assertSame($bytes, Storage::disk('assets')->get('blog/revisions/kite-original.jpg'));
        $this->assertSame('origin', $this->kept('blog/revisions/kite-original.jpg')['round']);
        $this->assertSame([$kite['id']], $this->kept('blog/revisions/kite-original.jpg')['saved']);
    }

    #[Test]
    public function an_image_darkroom_stops_listing_releases_its_steps()
    {
        $first = $this->round($this->target($this->draft()));
        $id = $this->save($this->round($this->target($first)), 'kite');

        app(\D3Creative\Darkroom\History\Trash::class)->forget(Asset::find($id));

        Storage::disk('assets')->assertExists('blog/kite.jpg');
        $this->assertSame([], $this->revisions());
    }

    #[Test]
    public function an_earlier_round_darkroom_stops_listing_keeps_a_copy_for_later_ones()
    {
        $first = $this->round($this->target($this->draft()));
        $second = $this->round($this->target($first), 'Make the city taller');
        $a = $this->save($first, 'first');
        $this->save($second, 'second');

        app(\D3Creative\Darkroom\History\Trash::class)->forget(Asset::find($a));

        // Unstamped, it can no longer be found as round 1, so a copy stands in.
        Storage::disk('assets')->assertExists('blog/first.jpg');
        $this->assertSame([$second['id']], $this->kept('blog/revisions/second-round-1.jpg')['saved']);
    }

    #[Test]
    public function an_image_made_from_a_kept_step_is_saved_beside_the_image_not_inside_its_revisions()
    {
        $first = $this->round($this->target($this->draft()));
        $this->save($this->round($this->target($first)), 'kite');

        $branch = $this->round(['asset' => 'assets::blog/revisions/kite-round-1.jpg'], 'Add a red kite');

        $this->assertSame('blog', $branch['folder']);

        $this->save($branch, 'branch');

        Storage::disk('assets')->assertExists('blog/branch.jpg');
        $this->assertNotContains('blog/revisions/revisions', Storage::disk('assets')->directories('blog/revisions'));
        // Its earlier steps are the ones it was made from, shared, not copied.
        $this->assertContains($branch['id'], $this->kept('blog/revisions/kite-round-1.jpg')['saved']);
        $this->assertContains($branch['id'], $this->kept('blog/revisions/kite-original.jpg')['saved']);
        $this->assertCount(2, $this->revisions());
    }

    #[Test]
    public function an_original_asset_is_untagged_once_no_saved_image_needs_it()
    {
        $start = $this->save($this->draft(), 'start');
        $id = $this->save($this->round(['asset' => $start]), 'kite');

        $this->assertNotNull(Asset::find($start)->get(ThreadAssets::ORIGIN_KEY));

        Asset::find($id)->delete();

        // The original is the user's own image: untagged, never deleted.
        $this->assertNotNull(Asset::find($start));
        $this->assertNull(Asset::find($start)->get(ThreadAssets::ORIGIN_KEY));
    }
}
