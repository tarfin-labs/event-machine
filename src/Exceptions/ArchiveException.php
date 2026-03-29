<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

class ArchiveException extends RuntimeException
{
    public static function emptyEventCollection(): self
    {
        return new self('Cannot archive empty event collection');
    }

    public static function compressionFailed(): self
    {
        return new self('Failed to compress events data');
    }

    public static function decompressionFailed(): self
    {
        return new self('Failed to decompress archived events data');
    }

    public static function invalidCompressionLevel(): self
    {
        return new self('Compression level must be between 0 and 9');
    }

    public static function decompressDataFailed(): self
    {
        return new self('Failed to decompress data');
    }

    public static function invalidData(): self
    {
        return new self('Data is neither compressed nor valid JSON');
    }
}
