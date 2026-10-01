<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;

class HistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->container();
        $this->actingAs($this->superUser());
    }

    /**
     * Generate one image and save it, returning the asset id.
     */
    protected function generateAndSave(array $overrides = [], array $save = []): string
    {
        Http::fake(['*' => Http::response($this->interactionsResponse($this->jpeg(320, 180)))]);

        $created = $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload($overrides))->assertCreated()->json();
        $batch = $this->getJson($created['urls']['show'])->json();

        $this->postJson($batch['items'][0]['urls']['save'], $save)->assertStatus(202);

        return $this->getJson($created['urls']['show'])->json('items.0.asset.id');
    }

    protected function history(int $page = 1, array $query = []): array
    {
        return $this->getJson(cp_route('darkroom.history.index', ['page' => $page, ...$query]))->assertOk()->json();
    }

    #[Test]
    public function saving_records_the_prompt_and_settings_on_the_asset()
    {
        $instruction = $this->postJson(cp_route('darkroom.instructions.store'), ['title' => 'House style', 'body' => 'Line drawings only.'])->json('saved');

        $id = $this->generateAndSave([
            'prompt' => 'A lighthouse at dusk',
            'model' => 'gemini-3.1-flash-image',
            'quality' => '4K',
            'aspect_ratio' => '4:3',
            'instruction' => $instruction['id'],
        ], ['filename' => 'lighthouse', 'alt' => 'A lighthouse']);

        $stamp = Asset::find($id)->get('darkroom');

        $this->assertSame('A lighthouse at dusk', $stamp['prompt']);
        $this->assertSame('gemini-3.1-flash-image', $stamp['model']);
        $this->assertSame('4K', $stamp['quality']);
        $this->assertSame('4:3', $stamp['aspect_ratio']);
        $this->assertSame($instruction['id'], $stamp['instruction']);
        $this->assertSame('House style', $stamp['instruction_title']);
        $this->assertSame('super', $stamp['user']);
        $this->assertIsInt($stamp['generated_at']);

        // The title is kept for display. The instruction's text is not copied
        // onto the asset.
        $this->assertArrayNotHasKey('instruction_text', $stamp);
        $this->assertSame('A lighthouse', Asset::find($id)->get('alt'));
    }

    #[Test]
    public function saved_images_are_listed_with_what_is_needed_to_reuse_them()
    {
        $this->generateAndSave(['prompt' => 'A lighthouse at dusk', 'aspect_ratio' => '4:3'], ['filename' => 'lighthouse', 'alt' => 'A lighthouse']);

        $history = $this->history();

        $this->assertSame(1, $history['total']);
        $this->assertSame(1, $history['meta']['last_page']);

        $item = $history['items'][0];

        $this->assertSame('assets::blog/lighthouse.jpg', $item['id']);
        $this->assertSame('blog/lighthouse.jpg', $item['path']);
        $this->assertSame('assets', $item['container']);
        $this->assertSame('blog', $item['folder']);
        $this->assertSame('jpg', $item['fileType']);
        $this->assertSame('A lighthouse', $item['alt']);
        $this->assertSame('A lighthouse at dusk', $item['prompt']);
        $this->assertSame('gemini-3-pro-image', $item['model']);
        $this->assertSame('Nano Banana Pro', $item['modelLabel']);
        $this->assertSame('2K', $item['quality']);
        $this->assertSame('4:3', $item['aspectRatio']);
        $this->assertSame(320, $item['width']);
        $this->assertStringContainsString('thumbnails', $item['thumbnail']);
        $this->assertStringContainsString('lighthouse.jpg', $item['editUrl']);
    }

    #[Test]
    public function the_newest_image_comes_first_and_the_list_is_paged()
    {
        $this->threeImagesPagedInTwos();

        $one = $this->history();

        $this->assertSame(['Third', 'Second'], array_column($one['items'], 'prompt'));
        $this->assertSame(3, $one['total']);
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'from' => 1, 'to' => 2, 'total' => 3], $one['meta']);

        $two = $this->history(2);

        $this->assertSame(['First'], array_column($two['items'], 'prompt'));
        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 2, 'from' => 3, 'to' => 3, 'total' => 3], $two['meta']);
    }

    #[Test]
    public function a_page_past_the_end_shows_the_last_page()
    {
        $this->threeImagesPagedInTwos();

        $history = $this->history(9);

        $this->assertSame(['First'], array_column($history['items'], 'prompt'));
        $this->assertSame(2, $history['meta']['current_page']);
    }

    #[Test]
    public function the_page_size_is_one_the_control_panel_offers()
    {
        $this->threeImagesPagedInTwos();

        $this->assertCount(3, $this->history(1, ['per_page' => 3])['items']);

        // Anything the Per Page menu does not offer is the default instead.
        $odd = $this->history(1, ['per_page' => 7]);

        $this->assertCount(2, $odd['items']);
        $this->assertSame(2, $odd['meta']['per_page']);
    }

    #[Test]
    public function the_page_size_chosen_before_is_remembered_as_a_preference()
    {
        $this->threeImagesPagedInTwos();

        // What the Per Page menu saves through Statamic.$preferences.
        $user = $this->superUser();
        $user->setPreference(SavedImages::PER_PAGE_PREFERENCE, 3)->save();
        $this->actingAs($user);

        $this->assertSame(3, $this->history()['meta']['per_page']);

        $this->get(cp_route('darkroom.index'))->assertInertia(fn ($page) => $page
            ->where('history.meta.per_page', 3)
            ->has('history.items', 3)
        );
    }

    /**
     * Three saved images, a minute apart, with the Control Panel paging
     * listings in twos and offering threes.
     */
    protected function threeImagesPagedInTwos(): void
    {
        config()->set('statamic.cp.pagination_size', 2);
        config()->set('statamic.cp.pagination_size_options', [2, 3]);

        foreach (['first', 'second', 'third'] as $name) {
            $this->generateAndSave(['prompt' => ucfirst($name)], ['filename' => $name]);
            $this->travel(1)->minutes();
        }
    }

    #[Test]
    public function history_can_be_searched_by_prompt_filename_alt_text_and_instruction()
    {
        $instruction = $this->postJson(cp_route('darkroom.instructions.store'), ['title' => 'House style', 'body' => 'Line drawings only.'])->json('saved');

        $this->generateAndSave(['prompt' => 'A lighthouse at dusk', 'instruction' => $instruction['id']], ['filename' => 'coast', 'alt' => 'Waves on rocks']);
        $this->generateAndSave(['prompt' => 'A red bicycle'], ['filename' => 'bike', 'alt' => 'A bicycle by a wall']);

        $search = fn (string $term) => $this->getJson(cp_route('darkroom.history.index', ['search' => $term]))->assertOk()->json();

        // Every word has to match, in any order and any case.
        $this->assertSame(['A lighthouse at dusk'], array_column($search('DUSK lighthouse')['items'], 'prompt'));
        $this->assertSame(['A red bicycle'], array_column($search('bike')['items'], 'prompt'));
        $this->assertSame(['A lighthouse at dusk'], array_column($search('rocks')['items'], 'prompt'));
        $this->assertSame(['A lighthouse at dusk'], array_column($search('house style')['items'], 'prompt'));
        $this->assertSame([], $search('lighthouse bicycle')['items']);

        // The count of matches, and of everything, so the tab keeps its total.
        $result = $search('bicycle');

        $this->assertSame(1, $result['total']);
        $this->assertSame(2, $result['all']);
        $this->assertSame(2, $search('  ')['total']);
    }

    #[Test]
    public function the_page_is_handed_the_first_page_of_history()
    {
        $this->generateAndSave(['prompt' => 'A lighthouse at dusk'], ['filename' => 'lighthouse']);

        $this->get(cp_route('darkroom.index'))->assertInertia(fn ($page) => $page
            ->where('history.total', 1)
            ->where('history.items.0.prompt', 'A lighthouse at dusk')
            ->where('batches', [])
        );
    }

    #[Test]
    public function assets_darkroom_did_not_make_are_left_out()
    {
        $this->generateAndSave([], ['filename' => 'made-here']);

        Asset::make()->container('assets')->path('blog/uploaded-by-hand.jpg')->save();

        $this->assertSame(['blog/made-here.jpg'], array_column($this->history()['items'], 'path'));
    }

    #[Test]
    public function deleting_the_asset_removes_it_from_history()
    {
        $id = $this->generateAndSave([], ['filename' => 'short-lived']);

        $this->assertSame(1, $this->history()['total']);

        Asset::find($id)->delete();

        $this->assertSame(0, $this->history()['total']);
    }

    #[Test]
    public function editing_the_asset_later_keeps_its_history()
    {
        $id = $this->generateAndSave(['prompt' => 'A lighthouse at dusk'], ['filename' => 'lighthouse']);

        // What the Control Panel does when someone edits the alt text.
        Asset::find($id)->set('alt', 'Edited afterwards')->save();

        $item = $this->history()['items'][0];

        $this->assertSame('Edited afterwards', $item['alt']);
        $this->assertSame('A lighthouse at dusk', $item['prompt']);
    }

    #[Test]
    public function history_only_shows_containers_the_user_may_view()
    {
        $this->generateAndSave([], ['filename' => 'visible']);

        $this->actingAs($this->userWith(['use darkroom']));

        $this->assertSame(0, $this->history()['total']);

        $this->actingAs($this->userWith(['use darkroom', 'view assets assets'], 'viewer'));

        $this->assertSame(1, $this->history()['total']);
    }

    #[Test]
    public function a_discarded_image_never_reaches_history()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $created = $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload())->json();
        $batch = $this->getJson($created['urls']['show'])->json();

        $this->deleteJson($batch['items'][0]['urls']['destroy'])->assertOk();

        $this->assertSame(0, $this->history()['total']);
    }
}
