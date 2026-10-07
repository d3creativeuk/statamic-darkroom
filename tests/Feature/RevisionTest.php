<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Jobs\GenerateBatch;
use D3Creative\Darkroom\Revisions\RevisionPrompt;
use D3Creative\Darkroom\Tests\TestCase;
use D3Creative\Darkroom\Usage\UsageLog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

class RevisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->container();
        $this->actingAs($this->superUser());
    }

    /**
     * Generate one image to revise and return its finished batch. Any call
     * that sends an image back answers with a different one.
     */
    protected function draft(array $overrides = []): array
    {
        Http::fake(fn (Request $request) => Http::response($this->interactionsResponse(
            is_array($request['input']) ? $this->jpeg(96, 72) : $this->jpeg(64, 48)
        )));

        $created = $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload(array_merge([
            'prompt' => 'A young woman looking at her phone',
            'aspect_ratio' => '4:3',
        ], $overrides)))->assertCreated()->json();

        return $this->getJson($created['urls']['show'])->json();
    }

    protected function revise(array $payload)
    {
        return $this->postJson(cp_route('darkroom.revisions.store'), array_merge([
            'model' => 'gemini-3-pro-image',
            'quality' => '2K',
        ], $payload));
    }

    protected function notes(): array
    {
        return [
            ['x' => 0.74, 'y' => 0.81, 'text' => 'Remove this door'],
            ['x' => 0.15, 'y' => 0.25, 'text' => 'Add a cityscape here, in the background.'],
        ];
    }

    #[Test]
    public function each_note_is_sent_with_where_it_was_pinned()
    {
        $prompt = RevisionPrompt::build([
            'notes' => $this->notes(),
            'general' => 'make it  warmer overall',
        ], config('statamic-darkroom'));

        $this->assertSame(implode("\n", [
            'Edit this image. Make only these changes:',
            '1. At about 74% from the left and 81% from the top: Remove this door.',
            '2. At about 15% from the left and 25% from the top: Add a cityscape here, in the background.',
            '3. Across the whole image: make it warmer overall.',
            'Keep everything else exactly as it is: the composition, subject, colours, textures and style.',
        ]), $prompt);
    }

    #[Test]
    public function an_unsaved_image_is_revised_by_sending_it_back_with_the_notes()
    {
        $draft = $this->draft(['instruction' => null]);

        $created = $this->revise(['batch' => $draft['id'], 'index' => 1, 'notes' => $this->notes(), 'general' => 'Warmer light'])
            ->assertCreated()
            ->json();

        $this->assertNotSame($draft['id'], $created['id']);
        $this->assertSame('A young woman looking at her phone', $created['prompt']);
        $this->assertSame('4:3', $created['aspectRatio']);
        $this->assertSame('Remove this door', $created['revision']['notes'][0]['text']);
        $this->assertSame('Warmer light', $created['revision']['general']);
        $this->assertNull($created['upscaledFrom']);
        $this->assertStringEndsWith('-revised', $created['items'][0]['filename']);

        $sent = collect(Http::recorded())->last()[0];

        $this->assertStringContainsString('1. At about 74% from the left and 81% from the top: Remove this door.', $sent['input'][0]['text']);
        $this->assertStringContainsString('3. Across the whole image: Warmer light.', $sent['input'][0]['text']);
        $this->assertSame('image/jpeg', $sent['input'][1]['type'] === 'image' ? $sent['input'][1]['mime_type'] : null);
        $this->assertArrayNotHasKey('system_instruction', $sent->data());

        $result = $this->getJson($created['urls']['show'])->json('items.0');
        $this->assertSame('complete', $result['status']);
        $this->assertSame([96, 72], [$result['width'], $result['height']]);

        // The draft it came from is untouched.
        $this->assertSame('complete', $this->getJson($draft['urls']['show'])->json('items.0.status'));
    }

    #[Test]
    public function a_general_note_alone_is_enough_but_nothing_at_all_is_not()
    {
        $draft = $this->draft();

        $this->revise(['batch' => $draft['id'], 'index' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes' => 'Add at least one note']);

        // A pin with nothing written against it says nothing to change.
        $this->revise(['batch' => $draft['id'], 'index' => 1, 'notes' => [['x' => 0.5, 'y' => 0.5, 'text' => '   ']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes.0.text');

        $this->revise(['batch' => $draft['id'], 'index' => 1, 'general' => 'Make it warmer'])->assertCreated();
    }

    #[Test]
    public function notes_must_be_pinned_inside_the_image_and_there_is_a_limit()
    {
        $draft = $this->draft();

        $this->revise(['batch' => $draft['id'], 'index' => 1, 'notes' => [['x' => 1.2, 'y' => 0.5, 'text' => 'Off the edge']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes.0.x');

        $many = array_fill(0, RevisionPrompt::MAX_NOTES + 1, ['x' => 0.5, 'y' => 0.5, 'text' => 'Again']);

        $this->revise(['batch' => $draft['id'], 'index' => 1, 'notes' => $many])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes');
    }

    #[Test]
    public function any_size_the_model_offers_will_do()
    {
        $draft = $this->draft(['quality' => '2K']);

        // Unlike an upscale, a revision may stay the same size or go smaller.
        $this->revise(['batch' => $draft['id'], 'index' => 1, 'notes' => $this->notes(), 'quality' => '1K'])->assertCreated();

        $this->revise(['batch' => $draft['id'], 'index' => 1, 'notes' => $this->notes(), 'model' => 'gemini-3.1-flash-lite-image', 'quality' => '4K'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quality');
    }

    #[Test]
    public function a_revision_is_logged_as_spend()
    {
        $this->travelTo('2026-10-02 12:00:00');

        $draft = $this->draft();
        $this->revise(['batch' => $draft['id'], 'index' => 1, 'notes' => $this->notes()]);

        $entry = app(UsageLog::class)->entries('2026-10')[0];

        $this->assertSame('revise', $entry['kind']);
        // The image plus the one it was revised from, which Google bills as input.
        $this->assertSame(0.1351, $entry['price']);
        $this->assertSame('Revise: A young woman looking at her phone', $entry['prompt']);
    }

    #[Test]
    public function a_saved_revision_remembers_its_notes_and_the_style_it_was_made_in()
    {
        $instruction = $this->postJson(cp_route('darkroom.instructions.store'), ['title' => 'House style', 'body' => 'Line drawings only.'])->json('saved');

        $draft = $this->draft(['instruction' => $instruction['id']]);
        $created = $this->revise(['batch' => $draft['id'], 'index' => 1, 'notes' => $this->notes()])->json();
        $batch = $this->getJson($created['urls']['show'])->json();

        $this->postJson($batch['items'][0]['urls']['save'], [])->assertStatus(202);

        $id = $this->getJson($created['urls']['show'])->json('items.0.asset.id');
        $stamp = Asset::find($id)->get('darkroom');

        $this->assertSame('A young woman looking at her phone', $stamp['prompt']);
        $this->assertSame('House style', $stamp['instruction_title']);
        $this->assertSame('Remove this door', $stamp['revision']['notes'][0]['text']);
        $this->assertStringEndsWith('-revised.jpg', $id);

        $listed = collect($this->getJson(cp_route('darkroom.history.index'))->json('items'))->firstWhere('id', $id);
        $this->assertSame('Remove this door', $listed['revision']['notes'][0]['text']);
        $this->assertSame(cp_route('darkroom.assets.preview', ['asset' => $id]), $listed['preview']);
    }

    #[Test]
    public function a_saved_asset_can_be_revised_from_history()
    {
        Storage::disk('assets')->put('blog/hero.jpg', $this->jpeg(80, 60));
        $asset = AssetContainer::find('assets')->makeAsset('blog/hero.jpg');
        $asset->set('darkroom', ['prompt' => 'A lighthouse at dusk', 'model' => 'gemini-3.1-flash-image', 'quality' => '1K', 'aspect_ratio' => '4:3'])->save();

        Http::fake(['*' => Http::response($this->interactionsResponse($this->jpeg(96, 72)))]);

        $created = $this->revise(['asset' => $asset->id(), 'notes' => $this->notes()])->assertCreated()->json();

        $this->assertSame('A lighthouse at dusk', $created['prompt']);
        $this->assertSame('blog', $created['folder']);
        $this->assertSame(base64_encode(Storage::disk('assets')->get('blog/hero.jpg')), collect(Http::recorded())->last()[0]['input'][1]['data']);
    }

    #[Test]
    public function a_saved_image_has_a_large_preview_for_pinning_notes_on()
    {
        Storage::disk('assets')->put('blog/wide.jpg', $this->jpeg(2400, 1800));
        AssetContainer::find('assets')->makeAsset('blog/wide.jpg')->save();

        config()->set('statamic-darkroom.preview.max_edge', 1200);

        $response = $this->get(cp_route('darkroom.assets.preview', ['asset' => 'assets::blog/wide.jpg']))->assertOk();

        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame([1200, 900], array_slice(getimagesizefromstring($response->getContent()), 0, 2));

        $this->get(cp_route('darkroom.assets.preview', ['asset' => 'assets::blog/missing.jpg']))->assertNotFound();

        $this->actingAs($this->userWith(['use darkroom']));
        $this->get(cp_route('darkroom.assets.preview', ['asset' => 'assets::blog/wide.jpg']))->assertForbidden();
    }

    #[Test]
    public function someone_elses_draft_cannot_be_revised()
    {
        $draft = $this->draft();

        $this->actingAs($this->userWith(['use darkroom', 'view assets assets', 'upload assets assets']));

        $this->revise(['batch' => $draft['id'], 'index' => 1, 'notes' => $this->notes()])->assertForbidden();
    }

    #[Test]
    public function a_revision_with_no_source_fails_without_calling_google()
    {
        $store = app(BatchStore::class);

        $revision = $store->create([
            'kind' => 'revise', 'user' => 'super', 'prompt' => 'Lost', 'model' => 'gemini-3-pro-image',
            'quality' => '2K', 'aspect_ratio' => '4:3', 'file_type' => 'jpg', 'container' => 'assets', 'folder' => '',
            'revision' => ['notes' => $this->notes(), 'general' => null],
        ], 1);

        Http::fake();

        GenerateBatch::dispatchSync($revision['id']);

        $item = $store->item($revision['id'], 1);

        $this->assertSame('failed', $item['status']);
        $this->assertSame('source_missing', $item['error']['code']);
        $this->assertSame('The image to revise is no longer available.', $item['error']['message']);
        Http::assertNothingSent();
    }
}
