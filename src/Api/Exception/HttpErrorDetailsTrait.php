<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Exception;

/**
 * Implements {@see \Comfino\Api\HttpErrorDetailsInterface} for the HTTP error exceptions.
 */
trait HttpErrorDetailsTrait
{
    private bool $nonNegotiatedBody = false;
    private bool $idempotentFailure = false;

    public function hasNonNegotiatedBody(): bool
    {
        return $this->nonNegotiatedBody;
    }

    public function isIdempotentFailure(): bool
    {
        return $this->idempotentFailure;
    }

    /**
     * Records how the error response was read. Called by the response parser that builds the exception.
     *
     * @return $this
     */
    public function setErrorDetails(bool $nonNegotiatedBody, bool $idempotentFailure): static
    {
        $this->nonNegotiatedBody = $nonNegotiatedBody;
        $this->idempotentFailure = $idempotentFailure;

        return $this;
    }
}
