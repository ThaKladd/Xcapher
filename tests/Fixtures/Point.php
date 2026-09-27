<?php

declare(strict_types=1);

namespace Xcapher\Tests\Fixtures;

final class Point
{
    public int $x = 1;

    public int $y = 2;

    /** @phpstan-ignore property.onlyWritten */
    private string $secret = 'hidden';
}
