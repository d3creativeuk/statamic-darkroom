<?php

namespace D3Creative\Darkroom\Tests\Feature;

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

    protected function history(int $page = 1): array
    {
        return $this->getJson(cp_route('darkroom.history.index', ['page' => $page]))->assertOk()->json();
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
        $this->assertNull($history['nextPage']);

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
        config()->set('statamic-darkroom.history.per_page', 2);

        foreach (['first', 'second', 'third'] as $name) {
            $this->generateAndSave(['prompt' => ucfirst($name)], ['filename' => $name]);
            $this->travel(1)->minutes();
        }

        $one = $this->history();

        $this->assertSame(['Third', 'Second'], array_column($one['items'], 'prompt'));
        $this->assertSame(3, $one['total']);
        $this->assertSame(2, $one['nextPage']);

        $two = $this->history(2);

        $this->assertSame(['First'], array_column($two['items'], 'prompt'));
        $this->assertNull($two['nextPage']);
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
