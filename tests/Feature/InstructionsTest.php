<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Instructions\InstructionStore;
use D3Creative\Darkroom\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class InstructionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->container();
        $this->actingAs($this->superUser());
    }

    protected function save(array $overrides = []): array
    {
        return $this->postJson(cp_route('darkroom.instructions.store'), array_merge([
            'title' => 'House style',
            'body' => 'Black and white line drawings only.',
        ], $overrides))->assertCreated()->json('saved');
    }

    #[Test]
    public function an_instruction_is_saved_with_a_title_and_body()
    {
        $saved = $this->save();

        $this->assertNotEmpty($saved['id']);
        $this->assertSame('House style', $saved['title']);
        $this->assertSame('Black and white line drawings only.', $saved['body']);
        $this->assertFalse($saved['default']);
        $this->assertFileExists($this->scratch('instructions.yaml'));
    }

    #[Test]
    public function template_syntax_in_an_instruction_is_kept_exactly_as_typed()
    {
        $body = "Use {{ brand_colour }} throughout.\nNever evaluate {{ config:app:key }}.";

        $id = $this->save(['body' => $body])['id'];

        $this->assertSame($body, app(InstructionStore::class)->find($id)['body']);
    }

    #[Test]
    public function only_one_instruction_can_be_the_default()
    {
        $first = $this->save(['title' => 'First', 'default' => true]);
        $second = $this->save(['title' => 'Second', 'default' => true]);

        $store = app(InstructionStore::class);

        $this->assertFalse($store->find($first['id'])['default']);
        $this->assertTrue($store->find($second['id'])['default']);
        $this->assertSame($second['id'], $store->default()['id']);

        // Saving a non-default one leaves the current default alone.
        $this->save(['title' => 'Third']);

        $this->assertSame($second['id'], $store->default()['id']);
    }

    #[Test]
    public function an_instruction_can_be_updated_and_deleted()
    {
        $saved = $this->save();

        $this->patchJson(cp_route('darkroom.instructions.update', $saved['id']), ['title' => 'Renamed', 'body' => 'New body.'])
            ->assertOk()
            ->assertJsonPath('saved.title', 'Renamed');

        $this->deleteJson(cp_route('darkroom.instructions.destroy', $saved['id']))
            ->assertOk()
            ->assertJsonPath('instructions', []);

        $this->deleteJson(cp_route('darkroom.instructions.destroy', $saved['id']))->assertNotFound();
    }

    #[Test]
    public function an_instruction_needs_a_title_and_a_body()
    {
        $this->postJson(cp_route('darkroom.instructions.store'), ['title' => '', 'body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'body']);
    }

    #[Test]
    public function the_chosen_instruction_is_sent_natively_to_models_that_honour_it()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $instruction = $this->save();

        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload(['instruction' => $instruction['id']]))
            ->assertCreated()
            ->assertJsonPath('instructionTitle', 'House style');

        Http::assertSent(fn (Request $request) => $request['system_instruction'] === 'Black and white line drawings only.'
            && $request['input'] === 'A red bicycle leaning against a white wall');
    }

    #[Test]
    public function it_is_placed_ahead_of_the_prompt_for_a_model_that_ignores_the_native_field()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $instruction = $this->save();

        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload([
            'model' => 'gemini-3.1-flash-lite-image',
            'quality' => '1K',
            'instruction' => $instruction['id'],
        ]))->assertCreated();

        Http::assertSent(fn (Request $request) => ! isset($request['system_instruction'])
            && $request['input'] === "Black and white line drawings only.\n\nA red bicycle leaning against a white wall");
    }

    #[Test]
    public function every_image_in_a_batch_gets_the_instruction()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $instruction = $this->save();

        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload([
            'instruction' => $instruction['id'],
            'batch_size' => 3,
        ]))->assertCreated();

        Http::assertSentCount(3);

        foreach (Http::recorded() as [$request]) {
            $this->assertSame('Black and white line drawings only.', $request['system_instruction']);
        }
    }

    #[Test]
    public function choosing_none_sends_no_instruction()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->save(['default' => true]);

        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload(['instruction' => null]))->assertCreated();

        Http::assertSent(fn (Request $request) => ! isset($request['system_instruction'])
            && $request['input'] === 'A red bicycle leaning against a white wall');
    }

    #[Test]
    public function an_instruction_that_no_longer_exists_is_refused()
    {
        Http::fake();

        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload(['instruction' => 'deleted-id']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('instruction');

        Http::assertNothingSent();
    }

    #[Test]
    public function the_batch_keeps_the_text_that_was_actually_sent()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $instruction = $this->save();

        $id = $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload(['instruction' => $instruction['id']]))->json('id');

        // Edited afterwards. The record of what produced the image must not move.
        $this->patchJson(cp_route('darkroom.instructions.update', $instruction['id']), ['title' => 'House style', 'body' => 'Full colour.']);

        $batch = app(BatchStore::class)->find($id);

        $this->assertSame('Black and white line drawings only.', $batch['instruction_text']);
        $this->assertSame($instruction['id'], $batch['instruction_id']);

        // And the text itself is not handed to the page.
        $this->getJson(cp_route('darkroom.batches.show', $id))->assertJsonMissingPath('instruction_text');
    }
}
