<?php

namespace Custom\StorePolicy\Exceptions;

use RuntimeException;

class MissingSettingsException extends RuntimeException
{
    /**
     * @param  string[]  $missing  Dotted keys of the empty required settings.
     */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Required policy settings are missing: '.implode(', ', $missing));
    }
}
