<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Prompts\PromptStore;
use D3Creative\Darkroom\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PromptsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->superUser());
    }

    protected function prompt(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Editorial collage',
            'prompt' => 'Editorial photo collage in a strict three-colour palette.',
            'model' => 'gemini-3-pro-image',
            'instruction' => null,
            'aspect_ratio' => '4:3',
            'quality' => '2K',
            'file_type' => 'jpg',
            'container' => 'assets',
            'folder' => 'blog',
        ], $overrides);
    }

    #[Test]
    public function a_prompt_is_saved_with_its_settings()
    {
        $response = $this->postJson(cp_route('darkroom.prompts.store'), $this->prompt())->assertCreated();

        $saved = $response->json('saved');

        $this->assertNotEmpty($saved['id']);
        $this->assertSame('Editorial collage', $saved['name']);
        $this->assertSame('4:3', $saved['aspect_ratio']);
        $this->assertSame('blog', $saved['folder']);
        $this->assertCount(1, $response->json('prompts'));

        $this->assertFileExists($this->scratch('prompts.yaml'));
        $this->assertEquals($saved, app(PromptStore::class)->find($saved['id']));
    }

    #[Test]
    public function batch_size_is_never_stored_with_a_prompt()
    {
        $saved = $this->postJson(cp_route('darkroom.prompts.store'), $this->prompt(['batch_size' => 4]))->json('saved');

        $this->assertArrayNotHasKey('batch_size', $saved);
        $this->assertStringNotContainsString('batch_size', file_get_contents($this->scratch('prompts.yaml')));
    }

    #[Test]
    public function template_syntax_in_a_prompt_is_kept_exactly_as_typed()
    {
        $text = "A poster reading {{ title }} with {{ config:app:key }}\nand a second line: 50% off, \"quoted\", it's fine.";

        $id = $this->postJson(cp_route('darkroom.prompts.store'), $this->prompt(['prompt' => $text]))->json('saved.id');

        // Read back through a fresh store, from the file.
        $this->assertSame($text, app(PromptStore::class)->find($id)['prompt']);
    }

    #[Test]
    public function a_prompt_can_be_updated_in_place()
    {
        $id = $this->postJson(cp_route('darkroom.prompts.store'), $this->prompt())->json('saved.id');
        $this->postJson(cp_route('darkroom.prompts.store'), $this->prompt(['name' => 'Another']));

        $response = $this->patchJson(cp_route('darkroom.prompts.update', $id), $this->prompt(['name' => 'Renamed', 'quality' => '4K']))
            ->assertOk();

        $this->assertSame($id, $response->json('saved.id'));
        $this->assertSame(['Renamed', 'Another'], array_column($response->json('prompts'), 'name'));
        $this->assertSame('4K', app(PromptStore::class)->find($id)['quality']);
    }

    #[Test]
    public function a_prompt_can_be_deleted()
    {
        $keep = $this->postJson(cp_route('darkroom.prompts.store'), $this->prompt(['name' => 'Keep']))->json('saved.id');
        $drop = $this->postJson(cp_route('darkroom.prompts.store'), $this->prompt(['name' => 'Drop']))->json('saved.id');

        $response = $this->deleteJson(cp_route('darkroom.prompts.destroy', $drop))->assertOk();

        $this->assertSame([$keep], array_column($response->json('prompts'), 'id'));
        $this->assertNull(app(PromptStore::class)->find($drop));
    }

    #[Test]
    public function deleting_the_last_prompt_leaves_a_valid_empty_file()
    {
        $id = $this->postJson(cp_route('darkroom.prompts.store'), $this->prompt())->json('saved.id');

        $this->deleteJson(cp_route('darkroom.prompts.destroy', $id))->assertOk();

        $this->assertSame([], app(PromptStore::class)->all());
    }

    #[Test]
    public function a_prompt_needs_a_name_and_some_text()
    {
        $this->postJson(cp_route('darkroom.prompts.store'), $this->prompt(['name' => '', 'prompt' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'prompt']);

        $this->assertFileDoesNotExist($this->scratch('prompts.yaml'));
    }

    #[Test]
    public function an_unknown_prompt_is_a_404()
    {
        $this->patchJson(cp_route('darkroom.prompts.update', 'nope'), $this->prompt())->assertNotFound();
        $this->deleteJson(cp_route('darkroom.prompts.destroy', 'nope'))->assertNotFound();
    }
}
