<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Api\GenerateContentClient;
use D3Creative\Darkroom\Api\ImageGenerator;
use D3Creative\Darkroom\Api\ImageRequest;
use D3Creative\Darkroom\Api\InteractionsClient;
use D3Creative\Darkroom\Exceptions\ApiRequestFailed;
use D3Creative\Darkroom\Exceptions\GenerationBlocked;
use D3Creative\Darkroom\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class GenerateContentClientTest extends TestCase
{
    protected function client(): GenerateContentClient
    {
        return new GenerateContentClient(config('statamic-darkroom'));
    }

    protected function request(array $overrides = []): ImageRequest
    {
        return new ImageRequest(...array_merge([
            'prompt' => 'A red bicycle',
            'model' => 'gemini-3-pro-image',
            'quality' => '2K',
            'aspectRatio' => '4:3',
        ], $overrides));
    }

    #[Test]
    public function the_configured_api_decides_which_client_is_used()
    {
        $this->assertInstanceOf(InteractionsClient::class, app(ImageGenerator::class));

        config()->set('statamic-darkroom.api', 'generate_content');

        $this->assertInstanceOf(GenerateContentClient::class, app(ImageGenerator::class));
    }

    #[Test]
    public function it_returns_the_image_from_a_real_response()
    {
        $jpeg = $this->jpeg(40, 30);
        Http::fake(['*' => Http::response($this->generateContentResponse($jpeg))]);

        $result = $this->client()->generate($this->request());

        $this->assertSame($jpeg, $result->binary);
        $this->assertSame('image/jpeg', $result->mimeType);
        $this->assertSame('gemini-3-pro-image', $result->model);
    }

    #[Test]
    public function it_posts_to_the_models_own_url_with_the_image_config_shape()
    {
        Http::fake(['*' => Http::response($this->generateContentResponse())]);

        $this->client()->generate($this->request());

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image:generateContent'
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && $request['contents'] === [['parts' => [['text' => 'A red bicycle']]]]
                && $request['generationConfig'] === [
                    'responseModalities' => ['IMAGE'],
                    'imageConfig' => ['imageSize' => '2K', 'aspectRatio' => '4:3'],
                ];
        });
    }

    #[Test]
    public function auto_sends_no_aspect_ratio_at_all()
    {
        $payload = $this->client()->payload($this->request(['aspectRatio' => null]));

        $this->assertSame(['imageSize' => '2K'], $payload['generationConfig']['imageConfig']);
    }

    #[Test]
    public function a_native_system_instruction_goes_in_the_system_instruction_parts()
    {
        $payload = $this->client()->payload($this->request(['systemInstruction' => 'Line drawings only.']));

        $this->assertSame(['parts' => [['text' => 'Line drawings only.']]], $payload['systemInstruction']);
        $this->assertSame('A red bicycle', $payload['contents'][0]['parts'][0]['text']);
    }

    #[Test]
    public function a_prepended_system_instruction_sends_no_system_field()
    {
        $payload = $this->client()->payload($this->request([
            'systemInstruction' => 'Line drawings only.',
            'prependInstruction' => true,
        ]));

        $this->assertArrayNotHasKey('systemInstruction', $payload);
        $this->assertSame("Line drawings only.\n\nA red bicycle", $payload['contents'][0]['parts'][0]['text']);
    }

    #[Test]
    public function parts_flagged_as_thoughts_are_ignored()
    {
        $final = $this->jpeg(40, 30);
        $json = $this->generateContentResponse($final);

        $draft = ['thought' => true, 'inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($this->jpeg(8, 8))]];
        $json['candidates'][0]['content']['parts'] = [$draft, $json['candidates'][0]['content']['parts'][0], $draft];

        Http::fake(['*' => Http::response($json)]);

        $this->assertSame($final, $this->client()->generate($this->request())->binary);
    }

    // The two blocked shapes below follow Google's API reference. Neither was
    // captured from a live call, unlike the image and error fixtures.

    #[Test]
    public function a_blocked_prompt_is_reported_as_blocked()
    {
        Http::fake(['*' => Http::response(['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT']])]);

        try {
            $this->client()->generate($this->request());
            $this->fail('Expected GenerationBlocked.');
        } catch (GenerationBlocked $e) {
            $this->assertSame('PROHIBITED_CONTENT', $e->reason);
            $this->assertStringContainsString('prohibited content', $e->userMessage());
        }
    }

    #[Test]
    public function a_blocked_image_is_reported_as_blocked()
    {
        Http::fake(['*' => Http::response(['candidates' => [[
            'finishReason' => 'IMAGE_SAFETY',
            'finishMessage' => 'The image violated the safety policy.',
        ]]])]);

        try {
            $this->client()->generate($this->request());
            $this->fail('Expected GenerationBlocked.');
        } catch (GenerationBlocked $e) {
            $this->assertSame('IMAGE_SAFETY', $e->reason);
            $this->assertStringContainsString('violated the safety policy', $e->getMessage());
        }
    }

    #[Test]
    public function the_error_code_is_read_from_the_status_field()
    {
        // The real body Google returned for the rejected responseFormat shape.
        Http::fake(['*' => Http::response($this->fixture('generate-content-error-400'), 400)]);

        try {
            $this->client()->generate($this->request());
            $this->fail('Expected ApiRequestFailed.');
        } catch (ApiRequestFailed $e) {
            $this->assertSame('invalid_argument', $e->errorCode());
            $this->assertFalse($e->retryable());
        }

        Http::assertSentCount(1);
    }
}
