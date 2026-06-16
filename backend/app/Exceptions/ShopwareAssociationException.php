<?php

namespace App\Exceptions;

/**
 * Thrown by ShopwareClient::requestWithRetry() when Shopware returns
 * FRAMEWORK__ASSOCIATION_NOT_FOUND. Preserves the raw response body so
 * callers (e.g. fetchPromotions) can still detect the error code after
 * the underlying Guzzle response body stream has been consumed.
 */
class ShopwareAssociationException extends \RuntimeException
{
    private string $responseBody;
    private ?int $httpStatus;

    public function __construct(string $responseBody, ?int $httpStatus, \Throwable $previous)
    {
        $this->responseBody = $responseBody;
        $this->httpStatus   = $httpStatus;

        parent::__construct($previous->getMessage(), 0, $previous);
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
