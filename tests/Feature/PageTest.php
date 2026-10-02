<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Permission;

class PageTest extends TestCase
{
    protected function page()
    {
        return $this->get(cp_route('darkroom.index'));
    }

    #[Test]
    public function a_super_user_gets_the_page_with_everything_it_needs()
    {
        $this->container();
        $this->actingAs($this->superUser());

        $this->page()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('darkroom/Index')
            ->where('configured', true)
            ->where('defaults.model', 'gemini-3-pro-image')
            ->where('defaults.aspectRatio', '16:9')
            ->where('defaults.quality', '2K')
            ->where('defaults.fileType', 'jpg')
            ->where('defaults.container', 'assets')
            ->where('defaults.instruction', null)
            ->where('limits.batchMax', 4)
            ->has('models', 3)
            ->where('models.0.label', 'Nano Banana Pro')
            ->where('models.0.aspectRatios', ['auto', '1:1', '3:4', '4:3', '2:3', '3:2', '9:16', '16:9', '5:4', '4:5', '21:9'])
            ->where('models.0.description', 'State-of-the-art image generation and editing model.')
            ->where('models.0.qualities.0', ['value' => '1K', 'label' => '1K', 'price' => 0.134])
            ->where('models.0.qualities.2', ['value' => '4K', 'label' => '4K', 'price' => 0.24])
            ->where('models.1.qualities.0', ['value' => '512', 'label' => '0.5K', 'price' => 0.045])
            ->where('models.2.qualities', [
                ['value' => '1K', 'label' => '1K', 'price' => 0.0336],
            ])
            ->where('fileTypes.0', ['value' => 'jpg', 'label' => 'JPEG'])
            ->where('containers.0.handle', 'assets')
            ->where('containers.0.browser.id', 'assets')
            ->where('containers.0.browser.can_create_folders', true)
            ->where('containers.0.browser.can_upload', false)
            ->has('containers.0.columns')
            ->where('prompts', [])
            ->where('instructions', [])
            ->where('batches', [])
            ->where('history', [
                'items' => [],
                'total' => 0,
                'all' => 0,
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'from' => 0, 'to' => 0, 'total' => 0],
            ])
            ->where('usage', [])
            ->where('fileTypes', [['value' => 'jpg', 'label' => 'JPEG'], ['value' => 'webp', 'label' => 'WebP'], ['value' => 'png', 'label' => 'PNG']])
            ->has('urls.batches')
            ->has('urls.upscales')
            ->has('urls.history')
            ->has('urls.usage')
        );
    }

    #[Test]
    public function the_api_key_never_reaches_the_browser()
    {
        $this->container();
        $this->actingAs($this->superUser());

        config()->set('statamic-darkroom.api_key', 'AIzaSy-very-secret-key');

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringNotContainsString('AIzaSy-very-secret-key', $html);
    }

    #[Test]
    public function without_a_key_the_page_says_it_is_not_configured()
    {
        $this->actingAs($this->superUser());

        config()->set('statamic-darkroom.api_key', '');

        $this->page()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('configured', false));
    }

    #[Test]
    public function the_default_instruction_is_preselected()
    {
        $this->actingAs($this->superUser());

        $this->postJson(cp_route('darkroom.instructions.store'), ['title' => 'Plain', 'body' => 'Plain.']);
        $default = $this->postJson(cp_route('darkroom.instructions.store'), ['title' => 'House', 'body' => 'House.', 'default' => true])->json('saved.id');

        $this->page()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('defaults.instruction', $default)
            ->has('instructions', 2)
        );
    }

    #[Test]
    public function unfinished_images_are_handed_back_on_reload()
    {
        $this->container();
        $this->actingAs($this->superUser());

        Http::fake(['*' => Http::response($this->interactionsResponse())]);

        $id = $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload(['batch_size' => 2]))->json('id');

        $this->page()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('batches', 1)
            ->where('batches.0.id', $id)
            ->where('batches.0.items.0.status', 'complete')
            ->where('batches.0.items.1.status', 'complete')
            ->has('batches.0.items.0.urls.preview')
        );
    }

    #[Test]
    public function loading_the_page_prunes_expired_images()
    {
        $this->actingAs($this->superUser());

        $old = app(BatchStore::class)->create(['user' => 'super', 'prompt' => 'Old'], 1);

        $this->travel(25)->hours();

        $this->page()->assertOk();

        $this->assertNull(app(BatchStore::class)->find($old['id']));
    }

    #[Test]
    public function only_containers_the_user_may_upload_to_are_offered()
    {
        $this->container('assets');
        $this->container('private');

        $this->actingAs($this->userWith(['use darkroom', 'upload assets assets']));

        $this->page()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('containers', 1)
            ->where('containers.0.handle', 'assets')
            ->where('defaults.container', 'assets')
        );
    }

    #[Test]
    public function a_user_without_the_permission_is_turned_away()
    {
        $this->container();
        $this->actingAs($this->userWith([]));

        Http::fake();

        $this->getJson(cp_route('darkroom.index'))->assertForbidden();
        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload())->assertForbidden();
        $this->postJson(cp_route('darkroom.prompts.store'), ['name' => 'x', 'prompt' => 'x'])->assertForbidden();
        $this->postJson(cp_route('darkroom.instructions.store'), ['title' => 'x', 'body' => 'x'])->assertForbidden();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_user_with_the_permission_is_let_in()
    {
        $this->container();
        $this->actingAs($this->userWith(['use darkroom', 'upload assets assets']));

        $this->page()->assertOk();
    }

    #[Test]
    public function a_guest_is_sent_to_log_in()
    {
        $this->page()->assertRedirect(cp_route('login'));
        $this->postJson(cp_route('darkroom.batches.store'), $this->generatePayload())->assertUnauthorized();
    }

    /**
     * Laravel keys a throttle on the user unless the route names its own
     * bucket. Without one, every Darkroom route would share a single counter
     * and status polling would eat the allowance for generating.
     */
    #[Test]
    public function every_throttled_route_has_its_own_named_bucket()
    {
        $buckets = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'statamic.cp.darkroom.')) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
                    $parts = explode(',', substr($middleware, 9));

                    $this->assertCount(3, $parts, $route->getName().' has a throttle with no named bucket.');

                    $buckets[$route->getName()] = $parts[2];
                }
            }
        }

        $this->assertCount(27, $buckets);
        $this->assertSame(array_values($buckets), array_values(array_unique($buckets)), 'Two routes share a throttle bucket.');
    }

    #[Test]
    public function the_permission_is_registered()
    {
        Permission::boot();

        $this->assertNotNull(Permission::get('use darkroom'));
    }
}
