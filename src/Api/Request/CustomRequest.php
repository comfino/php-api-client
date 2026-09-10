<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Request
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Request;

use Comfino\Api\Request;

/**
 * Free-form request for API endpoints not covered by dedicated {@see Request} subclass.
 *
 * Use this to reach a new or undocumented endpoint (or one this library hasn't caught up with yet) without writing a
 * dedicated Request class. Pair it with {@see \Comfino\Api\Client::sendCustomRequest()}, which reuses the same
 * authentication, track ID, and error-mapping infrastructure as the built-in methods.
 */
class CustomRequest extends Request
{
    /** @var array<string, mixed>|null Request body to serialize as JSON */
    private readonly ?array $body;

    /**
     * @param string $method HTTP method (e.g. 'GET', 'POST', 'PUT')
     * @param string $endpointPath API endpoint path, relative to the versioned API base URL (e.g. 'orders/123/notes')
     * @param array<string, mixed>|null $body Request body to serialize as JSON (omit for methods without a body)
     * @param array<string, string>|null $headers Additional HTTP request headers
     * @param array<string, string|int|float|null> $queryParams Query string parameters
     */
    public function __construct(
        string $method,
        string $endpointPath,
        ?array $body = null,
        ?array $headers = null,
        array $queryParams = []
    ) {
        $this->body = $body;

        $this->setRequestMethod($method);
        $this->setApiEndpointPath($endpointPath);

        if ($headers !== null) {
            $this->setRequestHeaders($headers);
        }

        if ($queryParams !== []) {
            $this->setRequestParams($queryParams);
        }
    }

    /** @inheritDoc */
    protected function prepareRequestBody(): ?array
    {
        return $this->body;
    }
}
