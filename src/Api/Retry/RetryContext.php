<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Retry
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Retry;

use Psr\Http\Message\RequestInterface;

/**
 * Request-level facts the retry loop needs but cannot infer from the operation closure.
 *
 * Two of them decide behavior rather than merely decorating a log line: whether replaying the request is safe, and
 * which tenant the call belongs to. The first keeps a request whose effect cannot be replayed safely from being sent
 * twice; the second is what lets a host attribute a retry to a merchant without patching the library.
 */
final class RetryContext
{
    /**
     * @param bool $idempotent Whether replaying the request is free of side effects
     * @param string|null $tenantKey Stable per-tenant key, passed through to the observer
     * @param RequestInterface|null $request The PSR-7 request being sent, for observability and exception context
     */
    public function __construct(
        public readonly bool $idempotent = true,
        public readonly ?string $tenantKey = null,
        public readonly ?RequestInterface $request = null
    ) {
    }

    /**
     * Returns a copy carrying the given PSR-7 request.
     */
    public function withRequest(RequestInterface $request): self
    {
        return new self($this->idempotent, $this->tenantKey, $request);
    }
}
