<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Tests\TestCase;
use D3Creative\Darkroom\Usage\UsageLog;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class AltTextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->container();
        $this->actingAs($this->superUser());
    }

    /**
     * The shape of a real text answer from the Interactions API, as returned
     * when these tests were written.
     */
    protected function textResponse(string $text, int $in = 1207, int $out = 20, int $thought = 0): array
    {
        return [
            'status' => 'completed',
            'usage' => ['total_input_tokens' => $in, 'total_output_tokens' => $out, 'total_thought_tokens' => $thought],
            'steps' => [
                ['type' => 'thought', 'signature' => 'c2ln'],
                ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => $text]]],
            ],
            'model' => 'gemini-3.5-flash-lite',
        ];
    }

    /**
     * A finished image, with the image model's response registered first so
     * it answers the generation, and the given text answer for everything
     * sent afterwards.
     */
    protected function ready(array $textResponse, int $status = 200): array
    {
        Http::fake(fn (Request $request) => $request['model'] === config('statamic-darkroom.alt_text.model')
            ? Http::response($textResponse, $status)
            : Http::response($this->interactionsResponse()));

        $created = $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload(['prompt' => 'A lighthouse at dusk']))->json();

        return $this->getJson($created['urls']['show'])->json();
    }

    #[Test]
    public function it_suggests_alt_text_from_the_preview()
    {
        $batch = $this->ready($this->textResponse('A greyscale lighthouse keeper statue against a cobalt blue circle.'));

        $this->postJson($batch['items'][0]['urls']['alt'])
            ->assertOk()
            ->assertExactJson(['alt' => 'A greyscale lighthouse keeper statue against a cobalt blue circle.']);

        $preview = app(BatchStore::class)->disk()->get(app(BatchStore::class)->previewPath($batch['id'], 1));

        Http::assertSent(function (Request $request) use ($preview) {
            return $request['model'] === 'gemini-3.5-flash-lite'
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && $request['store'] === false
                && str_contains($request['input'][0]['text'], 'alt text')
                && str_contains($request['input'][0]['text'], 'British English')
                && str_contains($request['input'][0]['text'], 'A lighthouse at dusk')
                && $request['input'][1] === ['type' => 'image', 'mime_type' => 'image/jpeg', 'data' => base64_encode($preview)];
        });
    }

    #[Test]
    public function quotes_and_stray_whitespace_are_trimmed()
    {
        $batch = $this->ready($this->textResponse("\"A red bicycle\nleaning on a wall.\"\n"));

        $this->postJson($batch['items'][0]['urls']['alt'])->assertJson(['alt' => 'A red bicycle leaning on a wall.']);
    }

    #[Test]
    public function the_call_is_logged_as_spend_but_not_counted_as_an_image()
    {
        $this->travelTo('2026-09-30 12:00:00');

        $batch = $this->ready($this->textResponse('A lighthouse keeper.', 1000, 20, 30));

        $this->postJson($batch['items'][0]['urls']['alt'])->assertOk();

        $entry = app(UsageLog::class)->entries('2026-09')[0];

        $this->assertSame('alt_text', $entry['kind']);
        $this->assertSame('Alt text (Gemini 3.5 Flash-Lite)', $entry['model_label']);
        // 1000 input tokens at $0.30 per million, 50 output and thinking at $2.50.
        $this->assertSame(0.000425, $entry['price']);

        $month = app(UsageLog::class)->months()[0];

        $this->assertSame(1, $month['images']);
        $this->assertSame(1, $month['altTexts']);
        $this->assertSame(round(0.134 + 0.000425, 4), $month['total']);
    }

    #[Test]
    public function a_failure_is_explained_and_nothing_is_logged()
    {
        $this->travelTo('2026-09-30 12:00:00');

        $batch = $this->ready(['error' => ['code' => 'invalid_request', 'message' => 'Bad image.']], 400);

        $this->postJson($batch['items'][0]['urls']['alt'])
            ->assertStatus(502)
            ->assertJson(['code' => 'invalid_request']);

        $this->assertSame(0, app(UsageLog::class)->months()[0]['altTexts']);
    }

    #[Test]
    public function an_empty_answer_is_reported_rather_than_filled_in()
    {
        $batch = $this->ready($this->textResponse('  '));

        $this->postJson($batch['items'][0]['urls']['alt'])
            ->assertStatus(502)
            ->assertJson(['code' => 'no_alt_text']);
    }

    #[Test]
    public function an_overloaded_service_gets_one_more_try()
    {
        $batch = $this->ready($this->textResponse('unused'));

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['code' => 'service_unavailable', 'message' => 'Overloaded.']], 503)
            ->push($this->textResponse('A lighthouse keeper.')),
        ]);

        $this->postJson($batch['items'][0]['urls']['alt'])->assertJson(['alt' => 'A lighthouse keeper.']);

        Http::assertSentCount(2);
    }

    #[Test]
    public function only_the_owner_of_a_finished_image_can_ask()
    {
        $batch = $this->ready($this->textResponse('A lighthouse keeper.'));

        $this->actingAs($this->userWith(['use darkroom', 'upload assets assets'], 'other'));

        $this->postJson($batch['items'][0]['urls']['alt'])->assertForbidden();

        $this->actingAs($this->superUser());

        $this->deleteJson($batch['items'][0]['urls']['destroy'])->assertOk();

        $this->postJson($batch['items'][0]['urls']['alt'])
            ->assertStatus(409)
            ->assertJson(['code' => 'not_ready']);
    }

    #[Test]
    public function without_a_key_it_says_so()
    {
        $batch = $this->ready($this->textResponse('unused'));

        config()->set('statamic-darkroom.api_key', null);

        $this->postJson($batch['items'][0]['urls']['alt'])
            ->assertStatus(422)
            ->assertJson(['code' => 'missing_api_key']);
    }
}
