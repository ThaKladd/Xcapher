<?php

declare(strict_types=1);

namespace Xcapher\Exception;

/**
 * Marker interface implemented by every exception Xcapher throws.
 *
 * Catch this to handle any failure from the library in one place.
 */
interface XcapherException extends \Throwable {}
