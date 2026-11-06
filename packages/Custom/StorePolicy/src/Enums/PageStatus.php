<?php

namespace Custom\StorePolicy\Enums;

enum PageStatus: string
{
    case NotGenerated = 'not_generated';
    case UpToDate = 'up_to_date';
    case OutOfDate = 'out_of_date';
    case Edited = 'edited';
    case OutOfDateEdited = 'out_of_date_edited';

    /**
     * Regenerating this page would discard manual edits.
     */
    public function needsConfirmation(): bool
    {
        return $this === self::Edited || $this === self::OutOfDateEdited;
    }

    public function isOutOfDate(): bool
    {
        return $this === self::OutOfDate || $this === self::OutOfDateEdited;
    }
}
