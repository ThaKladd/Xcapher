<?php

declare(strict_types=1);

namespace Xcapher\Tests\Fixtures;

final class BrokenText implements \Stringable
{
    public function __toString(): string
    {
        throw new \LogicException('Broken on purpose.');
    }
}
