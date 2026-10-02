<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class TrashTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->container();
        $this->actingAs($this->superUser());
    }

    /**
     * A saved image, as Darkroom leaves it, without going through generation.
     */
    protected function image(string $path, array $stamp = []): string
    {
        Storage::disk('assets')->put($path, $this->jpeg());

        $asset = AssetContainer::find('assets')->makeAsset($path);
        $asset->set(SavedImages::KEY, array_merge(['prompt' => 'A lighthouse', 'generated_at' => now()->getTimestamp()], $stamp))->save();

        return $asset->id();
    }

    protected function act(string $route, array $assets)
    {
        return $this->postJson(cp_route($route), ['assets' => $assets]);
    }

    protected function trash(): array
    {
        return $this->getJson(cp_route('darkroom.trash.index'))->assertOk()->json('items');
    }

    protected function entry(string $id, array $data): void
    {
        if (! Collection::find('pages')) {
            Collection::make('pages')->save();
        }

        Entry::make()->collection('pages')->id($id)->slug($id)->data($data)->save();
    }

    #[Test]
    public function moving_to_the_trash_takes_an_image_out_of_history_and_leaves_the_asset_in_place()
    {
        $this->travelTo('2026-10-02 12:00:00');

        $kept = $this->image('blog/kept.jpg');
        $binned = $this->image('blog/binned.jpg');

        $this->act('darkroom.trash.move', [$binned])->assertOk()->assertJson(['done' => [$binned], 'refused' => []]);

        $history = $this->getJson(cp_route('darkroom.history.index'))->json();
        $this->assertSame([$kept], array_column($history['items'], 'id'));
        $this->assertSame(1, $history['all']);

        $trash = $this->trash();
        $this->assertSame([$binned], array_column($trash, 'id'));
        $this->assertSame('binned.jpg', $trash[0]['basename']);
        $this->assertSame(now()->getTimestamp(), $trash[0]['trashedAt']);
        $this->assertSame(now()->addDays(30)->getTimestamp(), $trash[0]['deletesAt']);

        // Still a working asset, with its prompt, until it is deleted.
        Storage::disk('assets')->assertExists('blog/binned.jpg');
        $this->assertSame('A lighthouse', Asset::find($binned)->get(SavedImages::KEY)['prompt']);
        $this->assertSame('super', Asset::find($binned)->get(SavedImages::KEY)['trashed_by']);
    }

    #[Test]
    public function restoring_puts_an_image_back_in_history()
    {
        $id = $this->image('blog/back.jpg');

        $this->act('darkroom.trash.move', [$id]);
        $this->act('darkroom.trash.restore', [$id])->assertOk()->assertJson(['done' => [$id]]);

        $this->assertSame([], $this->trash());
        $this->assertSame([$id], array_column($this->getJson(cp_route('darkroom.history.index'))->json('items'), 'id'));
        $this->assertArrayNotHasKey('trashed_at', Asset::find($id)->get(SavedImages::KEY));
    }

    #[Test]
    public function deleting_for_good_only_works_from_the_trash()
    {
        $id = $this->image('blog/gone.jpg');

        // Not in the trash yet, so it is refused.
        $this->act('darkroom.trash.destroy', [$id])->assertOk()->assertJson(['done' => [], 'refused' => [$id]]);
        Storage::disk('assets')->assertExists('blog/gone.jpg');

        $this->act('darkroom.trash.move', [$id]);
        $this->act('darkroom.trash.destroy', [$id])->assertOk()->assertJson(['done' => [$id]]);

        Storage::disk('assets')->assertMissing('blog/gone.jpg');
        $this->assertNull(Asset::find($id));
        $this->assertSame([], $this->trash());
    }

    #[Test]
    public function an_asset_darkroom_did_not_save_is_refused()
    {
        Storage::disk('assets')->put('blog/someone-elses.jpg', $this->jpeg());
        AssetContainer::find('assets')->makeAsset('blog/someone-elses.jpg')->save();

        foreach (['darkroom.trash.move', 'darkroom.trash.destroy', 'darkroom.history.forget'] as $route) {
            $this->act($route, ['assets::blog/someone-elses.jpg', 'assets::nothing-here.jpg'])
                ->assertOk()
                ->assertJson(['done' => [], 'refused' => ['assets::blog/someone-elses.jpg', 'assets::nothing-here.jpg']]);
        }

        Storage::disk('assets')->assertExists('blog/someone-elses.jpg');
        $this->act('darkroom.trash.move', [])->assertStatus(422);
    }

    #[Test]
    public function moving_to_the_trash_needs_permission_to_delete_and_restoring_needs_permission_to_edit()
    {
        $id = $this->image('blog/guarded.jpg');

        $this->actingAs($this->userWith(['use darkroom', 'view assets assets', 'edit assets assets']));
        $this->act('darkroom.trash.move', [$id])->assertJson(['done' => [], 'refused' => [$id]]);

        $this->actingAs($this->userWith(['use darkroom', 'view assets assets', 'edit assets assets', 'delete assets assets'], 'deleter'));
        $this->act('darkroom.trash.move', [$id])->assertJson(['done' => [$id]]);

        $this->actingAs($this->userWith(['use darkroom', 'view assets assets'], 'viewer'));
        $this->assertSame([$id], array_column($this->trash(), 'id'));
        $this->act('darkroom.trash.restore', [$id])->assertJson(['done' => [], 'refused' => [$id]]);
        $this->act('darkroom.trash.destroy', [$id])->assertJson(['done' => [], 'refused' => [$id]]);

        // Someone who cannot see the container does not see its trash.
        $this->actingAs($this->userWith(['use darkroom'], 'outsider'));
        $this->assertSame([], $this->trash());
    }

    #[Test]
    public function usages_are_found_in_assets_fields_bard_and_links()
    {
        $hero = $this->image('blog/hero.jpg');
        $inline = $this->image('blog/inline.jpg');
        $unused = $this->image('blog/unused.jpg');

        $this->entry('home', ['title' => 'Home', 'hero' => 'blog/hero.jpg']);
        $this->entry('about', ['title' => 'About', 'body' => [
            ['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['src' => 'asset::assets::blog/inline.jpg']]]],
        ]]);
        $this->entry('team', ['title' => 'Team', 'gallery' => ['blog/hero.jpg', 'blog/other.jpg']]);

        $usages = $this->act('darkroom.usages', [$hero, $inline, $unused])->assertOk()->json('usages');

        $this->assertEqualsCanonicalizing(['Home', 'Team'], array_column($usages[$hero], 'title'));
        $this->assertSame(['About'], array_column($usages[$inline], 'title'));
        $this->assertSame('Entry', $usages[$inline][0]['type']);
        $this->assertNotNull($usages[$inline][0]['url']);
        $this->assertSame([], $usages[$unused]);
    }

    #[Test]
    public function keeping_an_image_stops_darkroom_listing_it_and_leaves_the_asset_alone()
    {
        $id = $this->image('blog/kept.jpg');
        Asset::find($id)->set('alt', 'A lighthouse at dusk')->save();

        $this->act('darkroom.history.forget', [$id])->assertOk()->assertJson(['done' => [$id]]);

        $asset = Asset::find($id);
        $this->assertNull($asset->get(SavedImages::KEY));
        $this->assertSame('A lighthouse at dusk', $asset->get('alt'));
        Storage::disk('assets')->assertExists('blog/kept.jpg');
        $this->assertSame([], $this->getJson(cp_route('darkroom.history.index'))->json('items'));
        $this->assertSame([], $this->trash());
    }

    #[Test]
    public function the_trash_empties_after_thirty_days_but_never_deletes_an_image_still_in_use()
    {
        $this->travelTo('2026-09-01 12:00:00');

        $old = $this->image('blog/old.jpg');
        $used = $this->image('blog/used.jpg');
        $this->act('darkroom.trash.move', [$old, $used]);

        $this->travelTo('2026-09-25 12:00:00');
        $recent = $this->image('blog/recent.jpg');
        $this->act('darkroom.trash.move', [$recent]);

        $this->entry('home', ['title' => 'Home', 'hero' => 'blog/used.jpg']);

        $this->travelTo('2026-10-01 12:00:01');

        $this->artisan('darkroom:prune')
            ->expectsOutputToContain('Deleted 1 trashed image.')
            ->expectsOutputToContain('Kept 1 still used on the site')
            ->assertSuccessful();

        Storage::disk('assets')->assertMissing('blog/old.jpg');
        Storage::disk('assets')->assertExists('blog/used.jpg');
        $this->assertNull(Asset::find($used)->get(SavedImages::KEY));
        $this->assertSame([$recent], array_column($this->trash(), 'id'));
    }

    #[Test]
    public function opening_the_page_empties_expired_trash_and_hands_over_what_is_left()
    {
        $this->travelTo('2026-09-01 12:00:00');
        $old = $this->image('blog/old.jpg');
        $this->act('darkroom.trash.move', [$old]);

        $this->travelTo('2026-10-02 12:00:00');
        $recent = $this->image('blog/recent.jpg');
        $this->act('darkroom.trash.move', [$recent]);

        $this->get(cp_route('darkroom.index'))->assertInertia(fn ($page) => $page
            ->where('trash.0.id', $recent)
            ->has('trash', 1)
            ->where('trashDays', 30)
            ->has('urls.trash')
            ->has('urls.restore')
            ->has('urls.destroy')
            ->has('urls.usages')
            ->has('urls.forget')
        );

        Storage::disk('assets')->assertMissing('blog/old.jpg');
    }
}
