<?php

namespace D3Creative\Darkroom\Tests;

use D3Creative\Darkroom\ServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('assets');

        // No test may reach Google. Anything not faked fails loudly.
        Http::preventStrayRequests();
        Sleep::fake();

        // Set after boot rather than in getEnvironmentSetUp(): the addon's
        // config is merged in one level deep, so setting a nested key before
        // the merge would replace that whole branch of the defaults.
        config()->set('statamic-darkroom.api_key', 'test-key');
        config()->set('statamic-darkroom.prompts.path', $this->scratch('prompts.yaml'));
        config()->set('statamic-darkroom.instructions.path', $this->scratch('instructions.yaml'));
    }

    protected function tearDown(): void
    {
        app('files')->deleteDirectory($this->scratch());

        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('d', 32)));
        $app['config']->set('statamic.editions.pro', true);

        // Statamic's preset warming listener reads this when it subscribes, so
        // it has to be off before the app boots. Warming presets would only
        // slow the tests down.
        $app['config']->set('statamic.assets.image_manipulation.generate_presets_on_upload', false);

        $app['config']->set('filesystems.disks.assets', [
            'driver' => 'local',
            'root' => storage_path('app/assets'),
            'url' => '/assets',
        ]);
    }

    protected function scratch(string $file = ''): string
    {
        return rtrim(storage_path('framework/testing/darkroom/'.$file), '/');
    }

    protected function superUser()
    {
        return tap(User::make()->id('super')->email('super@example.com')->makeSuper())->save();
    }

    /**
     * A non-super user holding exactly these permissions.
     */
    protected function userWith(array $permissions, string $id = 'editor')
    {
        $this->setTestRoles([$id.'-role' => array_merge(['access cp'], $permissions)]);

        return tap(User::make()->id($id)->email($id.'@example.com')->assignRole($id.'-role'))->save();
    }

    protected function container(string $handle = 'assets')
    {
        return tap(AssetContainer::make($handle)->disk('assets')->title('Assets'))->save();
    }

    protected function jpeg(int $width = 32, int $height = 18): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));

        ob_start();
        imagejpeg($image, null, 85);

        return ob_get_clean();
    }

    protected function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/__fixtures__/api/'.$name.'.json'), true);
    }

    /**
     * A real Interactions response, captured from Google, carrying this image.
     */
    /**
     * A real Interactions response. Given an id, it is the response to a
     * stored turn, which is the only kind that carries one.
     */
    protected function interactionsResponse(?string $binary = null, ?string $id = null): array
    {
        $json = $this->fixture($id === null ? 'interactions-image' : 'interactions-image-stored');

        if ($id !== null) {
            $json['id'] = $id;
        }

        if ($binary !== null) {
            $json['steps'][1]['content'][0]['data'] = base64_encode($binary);
        }

        return $json;
    }

    /**
     * A real generateContent response, captured from Google, carrying this image.
     */
    protected function generateContentResponse(?string $binary = null): array
    {
        $json = $this->fixture('generate-content-image');

        if ($binary !== null) {
            $json['candidates'][0]['content']['parts'][0]['inlineData']['data'] = base64_encode($binary);
        }

        return $json;
    }

    /**
     * The form a page would post to generate images, with anything overridden.
     */
    protected function generatePayload(array $overrides = []): array
    {
        return array_merge([
            'prompt' => 'A red bicycle leaning against a white wall',
            'model' => 'gemini-3-pro-image',
            'quality' => '2K',
            'aspect_ratio' => '16:9',
            'file_type' => 'jpg',
            'container' => 'assets',
            'folder' => 'blog',
        ], $overrides);
    }
}
