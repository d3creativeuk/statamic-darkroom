<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\Assets\Destinations;
use D3Creative\Darkroom\Prompts\PromptStore;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;

class PromptController extends CpController
{
    public function store(Request $request, PromptStore $prompts)
    {
        $saved = $prompts->save($this->validated($request));

        return response()->json(['saved' => $saved, 'prompts' => $prompts->all()], 201);
    }

    public function update(Request $request, string $id, PromptStore $prompts)
    {
        abort_unless($prompts->find($id), 404);

        $saved = $prompts->save(['id' => $id] + $this->validated($request));

        return response()->json(['saved' => $saved, 'prompts' => $prompts->all()]);
    }

    public function destroy(string $id, PromptStore $prompts)
    {
        abort_unless($prompts->delete($id), 404);

        return response()->json(['prompts' => $prompts->all()]);
    }

    /**
     * The settings are stored as given and only checked when the prompt is
     * used. A model can be retired, or a folder removed, long after a prompt
     * was saved, so validating them here would prove nothing.
     *
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'prompt' => ['required', 'string', 'max:'.(int) config('statamic-darkroom.prompts.max_length', 8000)],
            'model' => ['nullable', 'string', 'max:255'],
            'instruction' => ['nullable', 'string', 'max:255'],
            'aspect_ratio' => ['nullable', 'string', 'max:20'],
            'quality' => ['nullable', 'string', 'max:20'],
            'file_type' => ['nullable', 'string', 'max:20'],
            'container' => ['nullable', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:255', Destinations::folderRule()],
        ]);
    }
}
