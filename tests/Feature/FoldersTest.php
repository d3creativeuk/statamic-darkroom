<?php

namespace D3Creative\Darkroom\Tests\Feature;

use D3Creative\Darkroom\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class FoldersTest extends TestCase
{
    #[Test]
    public function it_lists_every_folder_in_natural_order_including_ones_just_created()
    {
        $container = $this->container();
        $this->actingAs($this->superUser());

        Storage::disk('assets')->put('blog/2026/a.jpg', 'x');
        Storage::disk('assets')->put('Heroes/b.jpg', 'x');

        $this->getJson(cp_route('darkroom.folders', 'assets'))
            ->assertOk()
            ->assertExactJson(['folders' => ['blog', 'blog/2026', 'Heroes']]);

        // Created the way core's Create Folder button does it, after the page
        // has already loaded.
        $container->assetFolder('darkroom')->save();

        $this->assertContains('darkroom', $this->getJson(cp_route('darkroom.folders', 'assets'))->json('folders'));
    }

    #[Test]
    public function only_a_container_the_user_can_upload_to_is_listed()
    {
        $this->container('assets');
        $this->container('private');

        $this->actingAs($this->userWith(['use darkroom', 'upload assets assets']));

        $this->getJson(cp_route('darkroom.folders', 'assets'))->assertOk();
        $this->getJson(cp_route('darkroom.folders', 'private'))->assertForbidden();
        $this->getJson(cp_route('darkroom.folders', 'missing'))->assertForbidden();
    }
}
