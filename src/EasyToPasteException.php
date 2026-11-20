<?php

declare(strict_types=1);

namespace EasyToPaste;

class EasyToPasteException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $code_name = 'error')
    {
        parent::__construct($message);
    }
}
