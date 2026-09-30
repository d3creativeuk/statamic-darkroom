<?php

namespace D3Creative\Darkroom\Tests\Unit;

use D3Creative\Darkroom\Api\ImageRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ImageRequestTest extends TestCase
{
    #[Test]
    public function a_native_instruction_travels_separately_from_the_prompt()
    {
        $request = new ImageRequest('A bicycle', 'model', systemInstruction: '  Line drawings only.  ');

        $this->assertSame('A bicycle', $request->input());
        $this->assertSame('Line drawings only.', $request->nativeInstruction());
    }

    #[Test]
    public function a_prepended_instruction_goes_ahead_of_the_prompt_and_nowhere_else()
    {
        $request = new ImageRequest('A bicycle', 'model', systemInstruction: 'Line drawings only.', prependInstruction: true);

        $this->assertSame("Line drawings only.\n\nA bicycle", $request->input());
        $this->assertNull($request->nativeInstruction());
    }

    #[Test]
    public function a_blank_instruction_is_treated_as_none()
    {
        foreach ([null, '', "  \n "] as $blank) {
            $native = new ImageRequest('A bicycle', 'model', systemInstruction: $blank);
            $prepended = new ImageRequest('A bicycle', 'model', systemInstruction: $blank, prependInstruction: true);

            $this->assertNull($native->nativeInstruction());
            $this->assertSame('A bicycle', $prepended->input());
        }
    }
}
