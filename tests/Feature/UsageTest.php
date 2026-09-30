<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Tests\TestCase;
use D3Creative\Darkroom\Usage\UsageLog;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class UsageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->container();
        $this->actingAs($this->superUser());
    }

    protected function generate(array $overrides = []): array
    {
        $created = $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload($overrides))->assertCreated()->json();

        return $this->getJson($created['urls']['show'])->json();
    }

    protected function months(): array
    {
        return $this->getJson(cp_route('darkroom.usage.index'))->assertOk()->json('months');
    }

    #[Test]
    public function every_generated_image_is_logged_with_its_price()
    {
        $this->travelTo('2026-09-30 12:00:00');

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->generate(['prompt' => 'A lighthouse at dusk', 'quality' => '4K', 'aspect_ratio' => '4:3']);

        $entries = $this->getJson(cp_route('darkroom.usage.show', '2026-09'))->assertOk()->json('entries');

        $this->assertCount(1, $entries);
        $this->assertSame('gemini-3-pro-image', $entries[0]['model']);
        $this->assertSame('Nano Banana Pro', $entries[0]['model_label']);
        $this->assertSame('4K', $entries[0]['quality']);
        $this->assertSame('4:3', $entries[0]['aspect_ratio']);
        $this->assertSame(0.24, $entries[0]['price']);
        $this->assertSame('A lighthouse at dusk', $entries[0]['prompt']);
        $this->assertSame('super', $entries[0]['user']);
        $this->assertSame('cp', $entries[0]['source']);
        $this->assertSame(now()->getTimestamp(), $entries[0]['at']);
    }

    #[Test]
    public function a_batch_is_charged_per_image_and_failures_are_not_charged()
    {
        $this->travelTo('2026-09-30 12:00:00');

        Http::fake(['*' => Http::sequence()
            ->push($this->interactionsResponse())
            ->push($this->fixture('interactions-error-400'), 400)
            ->push($this->interactionsResponse()),
        ]);

        $this->generate(['batch_size' => 3]);

        $month = $this->months()[0];

        $this->assertSame('2026-09', $month['month']);
        $this->assertSame('September 2026', $month['label']);
        $this->assertSame(2, $month['images']);
        $this->assertSame(0.268, $month['total']);
    }

    #[Test]
    public function an_image_is_charged_for_even_if_it_is_then_discarded()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $batch = $this->generate();

        $this->deleteJson($batch['items'][0]['urls']['destroy'])->assertOk();
        $this->deleteJson($batch['urls']['destroy'])->assertNoContent();

        $this->assertSame(1, $this->months()[0]['images']);
    }

    #[Test]
    public function months_are_totalled_separately_and_broken_down_by_model()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->travelTo('2026-08-15 12:00:00');
        $this->generate(['model' => 'gemini-3.1-flash-lite-image', 'quality' => '1K', 'batch_size' => 2]);

        $this->travelTo('2026-09-10 12:00:00');
        $this->generate(['quality' => '2K']);
        $this->travel(5)->minutes();
        $this->generate(['quality' => '4K']);
        $this->travel(5)->minutes();
        $this->generate(['model' => 'gemini-3.1-flash-image', 'quality' => '1K']);

        $months = $this->months();

        $this->assertSame(['2026-09', '2026-08'], array_column($months, 'month'));

        $this->assertSame(3, $months[0]['images']);
        $this->assertSame(0.4412, $months[0]['total']);
        $this->assertFalse($months[0]['unpriced']);
        $this->assertSame([
            ['label' => 'Nano Banana Pro', 'images' => 2, 'total' => 0.374],
            ['label' => 'Nano Banana 2', 'images' => 1, 'total' => 0.0672],
        ], $months[0]['models']);

        $this->assertSame(2, $months[1]['images']);
        $this->assertSame(0.0672, $months[1]['total']);
    }

    #[Test]
    public function a_months_entries_come_newest_first()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->travelTo('2026-09-10 12:00:00');
        $this->generate(['prompt' => 'Earlier']);
        $this->travel(1)->hours();
        $this->generate(['prompt' => 'Later']);

        $entries = $this->getJson(cp_route('darkroom.usage.show', '2026-09'))->json('entries');

        $this->assertSame(['Later', 'Earlier'], array_column($entries, 'prompt'));
    }

    #[Test]
    public function a_model_with_no_price_is_counted_and_flagged()
    {
        $this->travelTo('2026-09-30 12:00:00');

        config()->set('statamic-darkroom.models.gemini-3-pro-image.qualities', ['2K' => null]);

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->generate();

        $month = $this->months()[0];

        $this->assertSame(1, $month['images']);
        $this->assertSame(0.0, (float) $month['total']);
        $this->assertTrue($month['unpriced']);
    }

    #[Test]
    public function long_prompts_are_shortened_in_the_log()
    {
        $this->travelTo('2026-09-30 12:00:00');

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->generate(['prompt' => str_repeat('word ', 200)]);

        $entry = app(UsageLog::class)->entries('2026-09')[0];

        $this->assertLessThanOrEqual(143, mb_strlen($entry['prompt']));
    }

    #[Test]
    public function pruning_temporary_images_never_touches_the_usage_log()
    {
        $this->travelTo('2026-09-01 12:00:00');

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->generate();

        $this->travel(3)->days();

        $this->assertSame(1, app(BatchStore::class)->prune());
        $this->assertSame(1, $this->months()[0]['images']);
    }

    #[Test]
    public function the_page_is_handed_the_monthly_totals()
    {
        $this->travelTo('2026-09-30 12:00:00');

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->generate();

        $this->get(cp_route('darkroom.index'))->assertInertia(fn ($page) => $page
            ->has('usage', 1)
            ->where('usage.0.month', '2026-09')
            ->where('usage.0.images', 1)
        );
    }

    #[Test]
    public function a_month_that_is_not_a_month_is_a_404_and_an_empty_month_is_empty()
    {
        $this->getJson(cp_route('darkroom.index').'/usage/2026-13')->assertNotFound();
        $this->getJson(cp_route('darkroom.index').'/usage/..%2F..%2Fenv')->assertNotFound();

        $this->getJson(cp_route('darkroom.usage.show', '2026-01'))->assertOk()->assertJson(['entries' => []]);
    }

    #[Test]
    public function nothing_is_logged_when_there_is_no_usage()
    {
        $this->assertSame([], $this->months());
    }
}
