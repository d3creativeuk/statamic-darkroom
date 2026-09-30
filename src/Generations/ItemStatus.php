<?php

namespace D3Creative\Darkroom\Generations;

/**
 * Where one image of a batch is in its life.
 */
enum ItemStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Complete = 'complete';
    case Failed = 'failed';
    case Saving = 'saving';
    case Saved = 'saved';
    case Discarded = 'discarded';

    /**
     * Still being worked on in the background, so the page should keep polling.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Generating, self::Saving], true);
    }

    /**
     * Waiting on Google. Only one batch per user may be in this state.
     */
    public function isGenerating(): bool
    {
        return in_array($this, [self::Pending, self::Generating], true);
    }

    /**
     * Still worth showing after a page reload: in progress, or a finished
     * image nobody has saved or discarded yet.
     */
    public function isOpen(): bool
    {
        return $this->isActive() || $this === self::Complete;
    }
}
