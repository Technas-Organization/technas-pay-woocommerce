<?php

declare(strict_types=1);

namespace TechnasPay\App;

final class AppResponse
{
    public function __construct(public readonly int $status, public readonly mixed $body)
    {
    }

    public function json(): string
    {
        return json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
