<?php
declare(strict_types=1);

namespace ParkNGo\Services\Api;

/** Strict JSON envelope codec for future REST adapters; performs no I/O. */
final class Json
{
    public static function encode(array|\stdClass $object): string
    {
        return json_encode((object) $object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 64);
    }

    public static function decode(string $body, int $maximumBytes = 262144): \stdClass
    {
        if ($maximumBytes < 1 || strlen($body) > $maximumBytes) throw new \LengthException('JSON payload exceeds the configured limit.');
        $object = json_decode($body, false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (!$object instanceof \stdClass) throw new \UnexpectedValueException('A JSON object envelope is required.');
        return $object;
    }
}
