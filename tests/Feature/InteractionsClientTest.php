<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Api\ImageRequest;
use D3Creative\Darkroom\Api\ImageResult;
use D3Creative\Darkroom\Api\InteractionsClient;
use D3Creative\Darkroom\Exceptions\ApiRequestFailed;
use D3Creative\Darkroom\Exceptions\GenerationBlocked;
use D3Creative\Darkroom\Exceptions\MissingApiKey;
use D3Creative\Darkroom\Exceptions\NoImageReturned;
use D3Creative\Darkroom\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;

class InteractionsClientTest extends TestCase
{
    protected function client(array $config = []): InteractionsClient
    {
        return new InteractionsClient(array_replace_recursive(config('statamic-darkroom'), $config));
    }

    protected function request(array $overrides = []): ImageRequest
    {
        return new ImageRequest(...array_merge([
            'prompt' => 'A red bicycle',
            'model' => 'gemini-3-pro-image',
            'quality' => '2K',
            'aspectRatio' => '16:9',
        ], $overrides));
    }

    #[Test]
    public function it_returns_the_image_from_a_real_response()
    {
        $jpeg = $this->jpeg(40, 30);
        Http::fake(['*' => Http::response($this->interactionsResponse($jpeg))]);

        $result = $this->client()->generate($this->request());

        $this->assertSame($jpeg, $result->binary);
        $this->assertSame('image/jpeg', $result->mimeType);
        $this->assertSame('gemini-3-pro-image', $result->model);
        $this->assertSame('jpg', $result->extension());
    }

    #[Test]
    public function it_sends_the_key_as_a_header_and_asks_google_not_to_keep_the_interaction()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->client()->generate($this->request());

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/interactions'
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && ! str_contains($request->url(), 'test-key')
                && $request['store'] === false
                && $request['model'] === 'gemini-3-pro-image'
                && $request['input'] === 'A red bicycle'
                && $request['response_format'] === ['type' => 'image', 'image_size' => '2K', 'aspect_ratio' => '16:9'];
        });
    }

    #[Test]
    public function a_revision_round_is_kept_and_can_carry_a_conversation_on()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse(null, 'v1_ChdUdXJuVHdv'))]);

        $result = $this->client()->generate($this->request([
            'prompt' => 'Edit your last image. Make only these changes: 1. Undo that.',
            'continues' => 'v1_ChdUdXJuT25l',
            'store' => true,
        ]));

        $this->assertSame('v1_ChdUdXJuVHdv', $result->interaction);

        Http::assertSent(fn (Request $request) => $request['store'] === true
            && $request['previous_interaction_id'] === 'v1_ChdUdXJuT25l'
            // The earlier images are already on Google's side: only the words go.
            && $request['input'] === 'Edit your last image. Make only these changes: 1. Undo that.');
    }

    #[Test]
    public function a_turn_that_was_not_kept_has_no_interaction_and_carries_nothing_on()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $this->assertNull($this->client()->generate($this->request())->interaction);

        Http::assertSent(fn (Request $request) => ! isset($request['previous_interaction_id']));
    }

    #[Test]
    public function every_documented_aspect_ratio_and_quality_is_sent_exactly_as_written()
    {
        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        foreach (['1:1', '3:4', '4:3', '2:3', '3:2', '9:16', '16:9', '5:4', '4:5', '21:9'] as $ratio) {
            $this->assertSame($ratio, $this->client()->payload($this->request(['aspectRatio' => $ratio]))['response_format']['aspect_ratio']);
        }

        // Google rejects "2k": the K has to be a capital.
        foreach (['1K', '2K', '4K'] as $quality) {
            $this->assertSame($quality, $this->client()->payload($this->request(['quality' => $quality]))['response_format']['image_size']);
        }
    }

    #[Test]
    public function auto_sends_no_aspect_ratio_at_all()
    {
        $payload = $this->client()->payload($this->request(['aspectRatio' => null]));

        $this->assertArrayNotHasKey('aspect_ratio', $payload['response_format']);
    }

    #[Test]
    public function a_native_system_instruction_goes_in_its_own_field()
    {
        $payload = $this->client()->payload($this->request(['systemInstruction' => 'Line drawings only.']));

        $this->assertSame('Line drawings only.', $payload['system_instruction']);
        $this->assertSame('A red bicycle', $payload['input']);
    }

    #[Test]
    public function a_prepended_system_instruction_goes_ahead_of_the_prompt_with_no_system_field()
    {
        $payload = $this->client()->payload($this->request([
            'systemInstruction' => 'Line drawings only.',
            'prependInstruction' => true,
        ]));

        $this->assertArrayNotHasKey('system_instruction', $payload);
        $this->assertSame("Line drawings only.\n\nA red bicycle", $payload['input']);
    }

    #[Test]
    public function no_instruction_sends_no_system_field()
    {
        $this->assertArrayNotHasKey('system_instruction', $this->client()->payload($this->request()));
    }

    #[Test]
    public function draft_images_inside_thought_steps_are_ignored()
    {
        $final = $this->jpeg(40, 30);
        $json = $this->interactionsResponse($final);

        // A thought step carrying a draft, placed both before and after the
        // real output, must never be mistaken for the finished image.
        $draft = ['type' => 'thought', 'content' => [['type' => 'image', 'mime_type' => 'image/jpeg', 'data' => base64_encode($this->jpeg(8, 8))]]];
        $json['steps'] = [$draft, $json['steps'][1], $draft];

        Http::fake(['*' => Http::response($json)]);

        $this->assertSame($final, $this->client()->generate($this->request())->binary);
    }

    #[Test]
    public function a_response_with_no_image_is_reported_as_no_image()
    {
        $json = $this->interactionsResponse();
        $json['steps'][1]['content'] = [['type' => 'text', 'text' => 'I can describe a bicycle instead.']];

        Http::fake(['*' => Http::response($json)]);

        try {
            $this->client()->generate($this->request());
            $this->fail('Expected NoImageReturned.');
        } catch (NoImageReturned $e) {
            $this->assertSame('no_image', $e->errorCode());
            $this->assertStringContainsString('I can describe a bicycle instead.', $e->getMessage());
            $this->assertFalse($e->retryable());
        }
    }

    #[Test]
    public function a_response_that_names_a_safety_reason_is_reported_as_blocked()
    {
        $json = $this->interactionsResponse();
        $json['status'] = 'image_safety';
        $json['steps'] = [$json['steps'][0]];

        Http::fake(['*' => Http::response($json)]);

        try {
            $this->client()->generate($this->request());
            $this->fail('Expected GenerationBlocked.');
        } catch (GenerationBlocked $e) {
            $this->assertSame('blocked', $e->errorCode());
            $this->assertSame('image_safety', $e->reason);
            $this->assertStringContainsString('image safety', $e->userMessage());
            // What is kept for the log must not include megabytes of signature.
            $this->assertSame('image_safety', $e->context()['status']);
        }
    }

    #[Test]
    public function an_overloaded_service_is_retried_until_it_answers()
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['code' => 'service_unavailable', 'message' => 'The model is overloaded.']], 503)
            ->push(['error' => ['code' => 'service_unavailable', 'message' => 'The model is overloaded.']], 503)
            ->push($this->interactionsResponse()),
        ]);

        $result = $this->client()->generate($this->request());

        $this->assertInstanceOf(ImageResult::class, $result);
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    #[Test]
    public function it_gives_up_after_the_configured_number_of_attempts()
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'service_unavailable', 'message' => 'Overloaded.']], 503)]);

        try {
            $this->client()->generate($this->request());
            $this->fail('Expected ApiRequestFailed.');
        } catch (ApiRequestFailed $e) {
            $this->assertSame(503, $e->status);
            $this->assertTrue($e->retryable());
            $this->assertStringContainsString('overloaded', $e->userMessage());
        }

        Http::assertSentCount(3);
    }

    #[Test]
    public function a_rate_limit_waits_for_as_long_as_google_asks()
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down.']], 429, ['Retry-After' => '11'])
            ->push($this->interactionsResponse()),
        ]);

        $this->client()->generate($this->request());

        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(11)->seconds()]);
    }

    #[Test]
    public function a_long_retry_after_is_capped()
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down.']], 429, ['Retry-After' => '600'])
            ->push($this->interactionsResponse()),
        ]);

        $this->client()->generate($this->request());

        Sleep::assertSequence([Sleep::for(30)->seconds()]);
    }

    #[Test]
    public function a_rejected_request_is_not_retried()
    {
        // The real body Google returned when asked for PNG output.
        Http::fake(['*' => Http::response($this->fixture('interactions-error-400'), 400)]);

        try {
            $this->client()->generate($this->request());
            $this->fail('Expected ApiRequestFailed.');
        } catch (ApiRequestFailed $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame('invalid_request', $e->errorCode());
            $this->assertFalse($e->retryable());
            $this->assertStringContainsString("not supported for 'response_format.mime_type'", $e->userMessage());
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    #[Test]
    public function a_rejected_key_is_not_retried_and_says_so_plainly()
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'authentication', 'message' => 'API key not valid.']], 403)]);

        try {
            $this->client()->generate($this->request());
            $this->fail('Expected ApiRequestFailed.');
        } catch (ApiRequestFailed $e) {
            $this->assertFalse($e->retryable());
            $this->assertStringContainsString('GEMINI_API_KEY', $e->userMessage());
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_dropped_connection_is_retried()
    {
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection('Connection reset')
            ->push($this->interactionsResponse()),
        ]);

        $this->assertInstanceOf(ImageResult::class, $this->client()->generate($this->request()));

        // The fake does not record a connection that never completed, so the
        // wait before the second attempt is the evidence that it was retried.
        Sleep::assertSleptTimes(1);
    }

    #[Test]
    public function without_a_key_nothing_is_sent()
    {
        Http::fake();

        try {
            $this->client(['api_key' => ''])->generate($this->request());
            $this->fail('Expected MissingApiKey.');
        } catch (MissingApiKey $e) {
            $this->assertSame('missing_api_key', $e->errorCode());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_batch_sends_one_request_per_image_and_reports_each_separately()
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->interactionsResponse())
            ->push($this->fixture('interactions-error-400'), 400)
            ->push($this->interactionsResponse()),
        ]);

        $events = [];

        $outcomes = $this->client()->generateMany(
            [1 => $this->request(), 2 => $this->request(), 3 => $this->request()],
            function (string $event, $key, $payload) use (&$events) {
                $events[] = $event.':'.$key;
            },
        );

        Http::assertSentCount(3);

        $this->assertInstanceOf(ImageResult::class, $outcomes[1]);
        $this->assertInstanceOf(ApiRequestFailed::class, $outcomes[2]);
        $this->assertInstanceOf(ImageResult::class, $outcomes[3]);

        $this->assertEqualsCanonicalizing(
            ['attempt:1', 'attempt:2', 'attempt:3', 'settled:1', 'settled:2', 'settled:3'],
            $events,
        );
    }

    #[Test]
    public function only_the_images_that_failed_are_sent_again()
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->interactionsResponse())
            ->push(['error' => ['code' => 'service_unavailable', 'message' => 'Overloaded.']], 503)
            ->push($this->interactionsResponse()),
        ]);

        $outcomes = $this->client()->generateMany([1 => $this->request(), 2 => $this->request()]);

        // Two for the first round, one for the retry of the second image.
        Http::assertSentCount(3);

        $this->assertInstanceOf(ImageResult::class, $outcomes[1]);
        $this->assertInstanceOf(ImageResult::class, $outcomes[2]);
    }

    #[Test]
    public function long_strings_are_stripped_from_what_is_kept_for_debugging()
    {
        $redacted = InteractionsClient::redact([
            'status' => 'completed',
            'steps' => [['signature' => str_repeat('a', 5000), 'type' => 'thought']],
        ]);

        $this->assertSame('completed', $redacted['status']);
        $this->assertSame('<5000 characters>', $redacted['steps'][0]['signature']);
    }
}
