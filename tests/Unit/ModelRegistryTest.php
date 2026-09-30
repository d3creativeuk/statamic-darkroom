<?php

namespace D3Creative\Darkroom\Tests\Unit;

use D3Creative\Darkroom\Models\ModelRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ModelRegistryTest extends TestCase
{
    protected function registry(array $overrides = []): ModelRegistry
    {
        return new ModelRegistry(array_merge(require __DIR__.'/../../config/statamic-darkroom.php', $overrides));
    }

    #[Test]
    public function every_shipped_model_offers_the_three_required_aspect_ratios()
    {
        $registry = $this->registry();

        $this->assertNotEmpty($registry->ids());

        foreach ($registry->ids() as $id) {
            foreach (['4:3', '1:1', '16:9'] as $ratio) {
                $this->assertContains($ratio, $registry->aspectRatios($id), "{$id} is missing {$ratio}");
            }
        }
    }

    #[Test]
    public function the_aspect_ratio_menu_is_auto_then_the_ten_documented_ratios_in_order()
    {
        $this->assertSame(
            ['auto', '1:1', '3:4', '4:3', '2:3', '3:2', '9:16', '16:9', '5:4', '4:5', '21:9'],
            $this->registry()->aspectRatios('gemini-3-pro-image'),
        );
    }

    #[Test]
    public function nano_banana_pro_is_the_default_and_offers_three_qualities()
    {
        $registry = $this->registry();

        $this->assertSame('gemini-3-pro-image', $registry->defaultId());
        $this->assertSame(['1K', '2K', '4K'], $registry->qualities('gemini-3-pro-image'));
        $this->assertSame(['512', '1K', '2K', '4K'], $registry->qualities('gemini-3.1-flash-image'));
        $this->assertSame(['1K'], $registry->qualities('gemini-3.1-flash-lite-image'));
    }

    #[Test]
    public function each_model_has_a_description()
    {
        $registry = $this->registry();

        foreach ($registry->ids() as $id) {
            $this->assertNotEmpty($registry->description($id));
        }

        $this->assertNull($registry->description('unknown'));
    }

    #[Test]
    public function the_smallest_quality_is_sent_as_512_and_shown_as_half_a_k()
    {
        $registry = $this->registry();

        $this->assertSame('0.5K', ModelRegistry::qualityLabel('512'));
        $this->assertSame('2K', ModelRegistry::qualityLabel('2K'));
        $this->assertNull(ModelRegistry::qualityLabel(null));

        // As Google returned them. Not quite half of 1K for every shape.
        $this->assertSame([688, 384], $registry->dimensions('gemini-3.1-flash-image', '16:9', '512'));
        $this->assertSame([512, 512], $registry->dimensions('gemini-3.1-flash-image', '1:1', '512'));
        $this->assertSame([592, 448], $registry->dimensions('gemini-3.1-flash-image', '4:3', '512'));
        $this->assertSame([784, 336], $registry->dimensions('gemini-3.1-flash-image', '21:9', '512'));
    }

    #[Test]
    public function half_a_k_is_only_offered_where_it_is_cheaper()
    {
        $registry = $this->registry();

        // Measured from real responses: Pro and Lite bill 0.5K as a 1K image
        // and are no faster at it, so they do not offer it.
        $this->assertNull($registry->price('gemini-3-pro-image', '512'));
        $this->assertNull($registry->price('gemini-3.1-flash-lite-image', '512'));
        $this->assertSame(0.045, $registry->price('gemini-3.1-flash-image', '512'));
        $this->assertLessThan($registry->price('gemini-3.1-flash-image', '1K'), $registry->price('gemini-3.1-flash-image', '512'));
    }

    #[Test]
    public function qualities_rank_from_smallest_to_largest()
    {
        $this->assertSame([0, 1, 2, 3], array_map([ModelRegistry::class, 'rank'], ['512', '1K', '2K', '4K']));
        $this->assertNull(ModelRegistry::rank('8K'));
        $this->assertNull(ModelRegistry::rank(null));
    }

    #[Test]
    public function a_default_model_that_is_not_configured_falls_back_to_the_first_one()
    {
        $this->assertSame('gemini-3-pro-image', $this->registry(['default_model' => 'retired-model'])->defaultId());
    }

    #[Test]
    public function prices_come_from_the_quality_table()
    {
        $registry = $this->registry();

        $this->assertSame(0.134, $registry->price('gemini-3-pro-image', '2K'));
        $this->assertSame(0.24, $registry->price('gemini-3-pro-image', '4K'));
        $this->assertSame(0.0336, $registry->price('gemini-3.1-flash-lite-image', '1K'));
        $this->assertNull($registry->price('gemini-3.1-flash-lite-image', '4K'));
    }

    #[Test]
    public function pixel_dimensions_scale_from_the_1k_table()
    {
        $registry = $this->registry();

        // These match what Google actually returned for each size.
        $this->assertSame([1376, 768], $registry->dimensions('gemini-3-pro-image', '16:9', '1K'));
        $this->assertSame([2752, 1536], $registry->dimensions('gemini-3-pro-image', '16:9', '2K'));
        $this->assertSame([5504, 3072], $registry->dimensions('gemini-3-pro-image', '16:9', '4K'));
        $this->assertSame([2400, 1792], $registry->dimensions('gemini-3-pro-image', '4:3', '2K'));
        $this->assertSame([2048, 2048], $registry->dimensions('gemini-3-pro-image', '1:1', '2K'));

        $this->assertNull($registry->dimensions('gemini-3-pro-image', 'auto', '2K'));
        // Every model shares the table, which is what Google has returned.
        $this->assertSame([2752, 1536], $registry->dimensions('gemini-3.1-flash-image', '16:9', '2K'));
        $this->assertSame([1200, 896], $registry->dimensions('gemini-3.1-flash-lite-image', '4:3', '1K'));

        $this->assertNull($this->registry(['models' => ['bare' => ['label' => 'No table']]])->dimensions('bare', '16:9', '2K'));

        // With only a 1K table, the other sizes are scaled from it.
        $scaled = $this->registry(['models' => ['scaled' => ['dimensions' => ['1K' => ['16:9' => [1376, 768]]]]]]);

        $this->assertSame([688, 384], $scaled->dimensions('scaled', '16:9', '512'));
        $this->assertSame([5504, 3072], $scaled->dimensions('scaled', '16:9', '4K'));
    }

    #[Test]
    public function the_instruction_mode_is_per_model_unless_overridden()
    {
        $registry = $this->registry();

        $this->assertSame('native', $registry->instructionMode('gemini-3-pro-image'));
        $this->assertSame('native', $registry->instructionMode('gemini-3.1-flash-image'));
        $this->assertSame('prepend', $registry->instructionMode('gemini-3.1-flash-lite-image'));

        $forced = $this->registry(['system_instruction_mode' => 'prepend']);

        $this->assertSame('prepend', $forced->instructionMode('gemini-3-pro-image'));
    }

    #[Test]
    public function it_builds_a_request_that_follows_the_models_rules()
    {
        $registry = $this->registry();

        $pro = $registry->request('gemini-3-pro-image', 'A bicycle', '2K', 'auto', 'Line drawings only.');

        $this->assertNull($pro->aspectRatio, 'Auto must not send an aspect ratio.');
        $this->assertSame('Line drawings only.', $pro->nativeInstruction());

        $lite = $registry->request('gemini-3.1-flash-lite-image', 'A bicycle', '1K', '4:3', 'Line drawings only.');

        $this->assertSame('4:3', $lite->aspectRatio);
        $this->assertNull($lite->nativeInstruction());
        $this->assertSame("Line drawings only.\n\nA bicycle", $lite->input());
    }
}
