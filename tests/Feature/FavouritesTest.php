<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

class FavouritesTest extends TestCase
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

    protected function heart(string $id, bool $favourite = true)
    {
        return $this->postJson(cp_route('darkroom.favourites.update'), ['asset' => $id, 'favourite' => $favourite]);
    }

    protected function history(array $query = []): array
    {
        return $this->getJson(cp_route('darkroom.history.index', $query))->assertOk()->json();
    }

    #[Test]
    public function a_hearted_image_is_listed_in_favourites_and_stays_in_history()
    {
        $liked = $this->image('blog/liked.jpg', ['generated_at' => 100]);
        $other = $this->image('blog/other.jpg', ['generated_at' => 200]);

        $this->heart($liked)->assertOk()->assertExactJson(['favourite' => true]);

        $history = $this->history();
        $this->assertSame([$other, $liked], array_column($history['items'], 'id'));
        $this->assertSame([false, true], array_column($history['items'], 'favourite'));
        $this->assertSame(2, $history['all']);
        $this->assertSame(1, $history['favourites']);

        $favourites = $this->history(['favourites' => 1]);
        $this->assertSame([$liked], array_column($favourites['items'], 'id'));
        $this->assertSame(1, $favourites['all']);
        $this->assertSame(1, $favourites['total']);
        $this->assertSame(1, $favourites['meta']['total']);
    }

    #[Test]
    public function taking_the_heart_away_leaves_no_trace_on_the_asset()
    {
        $id = $this->image('blog/liked.jpg');

        $this->heart($id);
        $this->assertSame(['super'], Asset::find($id)->get(SavedImages::KEY)['favourites']);

        $this->heart($id, false)->assertOk()->assertExactJson(['favourite' => false]);

        $this->assertArrayNotHasKey('favourites', Asset::find($id)->get(SavedImages::KEY));
        $this->assertSame('A lighthouse', Asset::find($id)->get(SavedImages::KEY)['prompt']);
        $this->assertSame([], $this->history(['favourites' => 1])['items']);
    }

    #[Test]
    public function favourites_are_personal()
    {
        $id = $this->image('blog/liked.jpg');

        $this->heart($id);

        // Viewing is enough to heart an image: it changes nothing anyone
        // else sees.
        $this->actingAs($this->userWith(['use darkroom', 'view assets assets'], 'viewer'));

        $this->assertSame(0, $this->history()['favourites']);
        $this->assertFalse($this->history()['items'][0]['favourite']);

        $this->heart($id)->assertOk();
        $this->assertSame(['super', 'viewer'], Asset::find($id)->get(SavedImages::KEY)['favourites']);

        $this->heart($id, false)->assertOk();
        $this->assertSame(['super'], Asset::find($id)->get(SavedImages::KEY)['favourites']);
    }

    #[Test]
    public function only_images_in_history_can_be_hearted_by_someone_who_can_see_them()
    {
        $id = $this->image('blog/liked.jpg');

        $this->actingAs($this->userWith(['use darkroom'], 'outsider'));
        $this->heart($id)->assertNotFound();

        $this->actingAs($this->superUser());

        // An asset Darkroom did not save.
        Storage::disk('assets')->put('blog/plain.jpg', $this->jpeg());
        AssetContainer::find('assets')->makeAsset('blog/plain.jpg')->save();
        $this->heart('assets::blog/plain.jpg')->assertNotFound();
        $this->assertNull(Asset::find('assets::blog/plain.jpg')->get(SavedImages::KEY));

        $this->heart('assets::blog/missing.jpg')->assertNotFound();
        $this->postJson(cp_route('darkroom.favourites.update'), ['asset' => $id, 'favourite' => 'yes please'])
            ->assertJsonValidationErrors('favourite');
    }

    #[Test]
    public function a_trashed_favourite_leaves_favourites_and_comes_back_when_restored()
    {
        $id = $this->image('blog/liked.jpg');

        $this->heart($id);
        $this->postJson(cp_route('darkroom.trash.move'), ['assets' => [$id]])->assertJson(['done' => [$id]]);

        $this->assertSame([], $this->history(['favourites' => 1])['items']);
        $this->assertSame(0, $this->history()['favourites']);
        $this->heart($id, false)->assertNotFound();

        $this->postJson(cp_route('darkroom.trash.restore'), ['assets' => [$id]])->assertJson(['done' => [$id]]);

        $this->assertSame([$id], array_column($this->history(['favourites' => 1])['items'], 'id'));
    }

    #[Test]
    public function favourites_can_be_searched()
    {
        $dusk = $this->image('blog/dusk.jpg', ['prompt' => 'A lighthouse at dusk']);
        $dawn = $this->image('blog/dawn.jpg', ['prompt' => 'A lighthouse at dawn']);
        $this->image('blog/unliked-dusk.jpg', ['prompt' => 'A harbour at dusk']);

        $this->heart($dusk);
        $this->heart($dawn);

        $found = $this->history(['favourites' => 1, 'search' => 'dusk']);

        $this->assertSame([$dusk], array_column($found['items'], 'id'));
        $this->assertSame(1, $found['total']);
        // The tab still counts every favourite.
        $this->assertSame(2, $found['all']);
    }
}
