<?php

declare(strict_types=1);

namespace Xcapher\Tests\Fixtures;

final readonly class Text implements \Stringable
{
    public function __construct(
        private string $text,
    ) {}

    public function __toString(): string
    {
        return $this->text;
    }
}
