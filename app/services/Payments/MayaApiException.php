<?php
declare(strict_types=1);
namespace ParkNGo\Services\Payments;

final class MayaApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus, string $body = '')
    {
        parent::__construct($message, $httpStatus);
        $this->bodyExcerpt = substr(preg_replace('/\s+/', ' ', $body) ?? '', 0, 1000);
    }

    public readonly string $bodyExcerpt;
}
