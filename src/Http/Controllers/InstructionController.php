<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\Instructions\InstructionStore;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;

class InstructionController extends CpController
{
    public function store(Request $request, InstructionStore $instructions)
    {
        $saved = $instructions->save($this->validated($request));

        return response()->json(['saved' => $saved, 'instructions' => $instructions->all()], 201);
    }

    public function update(Request $request, string $id, InstructionStore $instructions)
    {
        abort_unless($instructions->find($id), 404);

        $saved = $instructions->save(['id' => $id] + $this->validated($request));

        return response()->json(['saved' => $saved, 'instructions' => $instructions->all()]);
    }

    public function destroy(string $id, InstructionStore $instructions)
    {
        abort_unless($instructions->delete($id), 404);

        return response()->json(['instructions' => $instructions->all()]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:'.(int) config('statamic-darkroom.instructions.max_length', 8000)],
            'default' => ['nullable', 'boolean'],
        ]);
    }
}
