<?php declare(strict_types=1);

namespace TextMeFlow\Exception;

/**
 * An error returned by (or while reaching) the TextMeFlow API.
 */
final class TextMeFlowApiException extends \RuntimeException
{
    public function __construct(
        private readonly int $httpStatus,
        private readonly string $errorCode,
        private readonly ?int $limit = null,
    ) {
        parent::__construct(sprintf('TextMeFlow API error %d: %s', $httpStatus, $errorCode));
    }

    /** HTTP status, or 0 when the API could not be reached. */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** Device limit that was hit, when the API reports one. */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /** Whether trying again later can succeed (network, rate limit, server error). */
    public function isTransient(): bool
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }

    public function isDeviceLimit(): bool
    {
        return $this->errorCode === 'device_limit_reached';
    }
}
