<?php

declare(strict_types=1);

namespace Xcapher\Exception;

/**
 * Thrown when a value cannot be safely escaped for a database or other target.
 */
final class EscapeException extends \RuntimeException implements XcapherException {}
