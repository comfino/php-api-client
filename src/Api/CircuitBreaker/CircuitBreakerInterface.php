<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\CircuitBreaker
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\CircuitBreaker;

/**
 * Fast-fail gate in front of a failing API host.
 *
 * When ComfinoPay is unreachable, every call still pays the full timeout times the attempt count before failing. On a
 * single-shop plugin that is one shopper waiting; on a connector fronting hundreds of merchants, it is every worker on
 * the node parked on a dead socket, and a ComfinoPay outage becomes a connector outage. An open breaker turns that wait
 * into an immediate {@see \Comfino\Api\Exception\ServiceUnavailable}.
 *
 * Two rules matter more than the thresholds:
 *
 *  - **Key by host as well as tenant.** One merchant's wrong API key produces 401s, and a breaker opened by those
 *    would block healthy tenants sharing the host. Only transport failures and 5xx feed the breaker; 4xx never do.
 *  - **Key by tenant as well as host.** One suspended ComfinoPay account must not read as a platform outage.
 */
interface CircuitBreakerInterface
{
    /**
     * Whether calls for this key must fail immediately instead of reaching the transport.
     *
     * @param string $key Breaker key, conventionally "tenantKey|host" - see {@see CircuitBreakerKey}
     */
    public function isOpen(string $key): bool;

    /**
     * Records a successful call, closing a half-open breaker.
     *
     * @param string $key Breaker key
     */
    public function recordSuccess(string $key): void;

    /**
     * Records a call that failed in a way that indicates the far side is unhealthy.
     *
     * @param string $key Breaker key
     */
    public function recordFailure(string $key): void;
}
