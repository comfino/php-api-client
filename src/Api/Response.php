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

namespace Comfino\Api;

use Comfino\Api\Exception\AccessDenied;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\Conflict;
use Comfino\Api\Exception\Forbidden;
use Comfino\Api\Exception\MethodNotAllowed;
use Comfino\Api\Exception\NotFound;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\Exception\TooManyRequests;
use Comfino\Api\Retry\ErrorClassifier;
use Comfino\Api\Serializer\Factory as SerializerFactory;
use Comfino\Auth\ExceptionSanitizer;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Abstract base class for ComfinoPay API responses.
 */
abstract class Response
{
    /** @var Request ComfinoPay API client request object associated with this response */
    protected Request $request;
    /** @var ResponseInterface|null PSR-7 compatible HTTP response object */
    protected ?ResponseInterface $response;
    /** @var SerializerInterface Serializer/deserializer object for requests and responses body */
    protected SerializerInterface $serializer;
    /** @var Throwable|null Exception object in case of validation or communication error */
    protected ?Throwable $exception;
    /** @var string[] Extracted HTTP response headers */
    protected array $headers = [];

    /**
     * Largest response body this client will read into memory, in bytes.
     *
     * `getContents()` on its own reads whatever the far side sends, so a hostile, spoofed, or simply wedged host could answer
     * a widget-key lookup with a multi-gigabyte body and exhaust the shop's PHP memory limit - a checkout outage costing the
     * attacker one request. Every endpoint here answers in kilobytes, so 8 MB is orders of magnitude of headroom while still
     * bounding the damage. Override in a subclass whose endpoint genuinely returns more; nothing here does.
     */
    public const MAX_RESPONSE_BODY_BYTES = 8 * 1024 * 1024;

    /** Read granularity, so a body near the cap is not assembled one byte at a time. */
    private const READ_CHUNK_BYTES = 65536;

    /**
     * Returns response HTTP headers as an associative array ['headerName' => 'headerValue'].
     *
     * @return string[] Response HTTP headers
     */
    final public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Checks if the specified response HTTP header exists (case-insensitive).
     *
     * @param string $headerName Header name to check
     *
     * @return bool True if the header exists, false otherwise
     */
    final public function hasHeader(string $headerName): bool
    {
        if (isset($this->headers[$headerName])) {
            return true;
        }

        foreach ($this->headers as $responseHeaderName => $headerValue) {
            if (strcasecmp($responseHeaderName, $headerName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns a specified response HTTP header (case-insensitive) or default value if it does not exist.
     *
     * @param string $headerName Header name to retrieve
     * @param string|null $defaultValue Default value to return if header is not found
     *
     * @return string|null Header value or default value if not found
     */
    final public function getHeader(string $headerName, ?string $defaultValue = null): ?string
    {
        if (isset($this->headers[$headerName])) {
            return $this->headers[$headerName];
        }

        foreach ($this->headers as $responseHeaderName => $headerValue) {
            if (strcasecmp($responseHeaderName, $headerName) === 0) {
                return $headerValue;
            }
        }

        return $defaultValue;
    }

    /**
     * Extracts API response data from input PSR-7 compatible HTTP response object.
     *
     * @return Response ComfinoPay API client response object
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     */
    final protected function initFromPsrResponse(): self
    {
        // Handle null response (network errors, connection failures, etc.).
        if ($this->response === null) {
            return $this;
        }

        /* Both bodies here are exception and diagnostic material only - the raw request body already went on the wire,
           and the raw response body is what gets deserialized below. Everything attached to an exception uses the
           redacted copies: an order request carries the shopper's name, e-mail, phone, tax id and address, and host
           plugins log a caught exception whole. */
        $requestBody = ($this->request->getSanitizedRequestBody() ?? '');

        $responseBody = $this->readBoundedResponseBody();
        $sanitizedResponseBody = ExceptionSanitizer::sanitizeExceptionBody($responseBody);

        $this->headers = [];

        foreach ($this->response->getHeaders() as $headerName => $headerValues) {
            $this->headers[$headerName] = end($headerValues);
        }

        if ($this->exception !== null) {
            // Exception already thrown - return without errors processing and exceptions throwing.
            return $this;
        }

        /* The body is parsed only by the serializer this client negotiated - never by one chosen from the response's own Content-Type.
           Letting the header pick the parser handed the far side the choice of deserializer: a client that sent `Accept: application/json`
           would still msgpack-decode a body labeled `application/msgpack`, and msgpack_unpack() instantiates arbitrary classes and runs their
           __wakeup()/__destruct(). A response whose type is not the one we asked for is left as a raw string for processResponseBody() to reject. */
        $contentType = $this->response->hasHeader('Content-Type') ? $this->response->getHeaderLine('Content-Type') : '';
        $negotiatedType = SerializerFactory::normalizeContentType($this->serializer->getContentType());
        $negotiatedBody = $contentType !== '' && SerializerFactory::normalizeContentType($contentType) === $negotiatedType;

        $reasonPhrase = $this->response->getReasonPhrase();
        $statusCode = $this->response->getStatusCode();

        /* The status decides the exception class, so it is read before the body. A 502 or 503 from a proxy whose HTML error page is
           labeled `application/json` is an outage, not a data-validation fault: decoding first turned it into a ResponseValidationError
           that the retry policy and the queue classifier treat as final. On an error status the body only enriches the message, and a
           body that cannot be decoded is dropped, not escalated. A success status keeps the strict rule - a 2xx with an undecodable body
           is a contract violation. */
        $errorStatus = $statusCode >= 400;

        if ($negotiatedBody) {
            try {
                $deserializedResponseBody = $this->deserializeResponseBody($responseBody, $this->serializer);
            } catch (ResponseValidationError $e) {
                if (!$errorStatus) {
                    $e->setUrl($this->request->getRequestUri());
                    $e->setRequestBody($requestBody);
                    $e->setResponseBody($sanitizedResponseBody);

                    throw $e;
                }

                $deserializedResponseBody = null;
                $negotiatedBody = false;
            }
        } else {
            $deserializedResponseBody = $responseBody;
        }

        // An empty body has no format to disagree with the negotiated one.
        $nonNegotiatedBody = !$negotiatedBody && $responseBody !== '';
        $idempotentFailure = in_array($statusCode, $this->request->getIdempotentFailureStatusCodes(), true);

        // 5xx status codes (guard re-checked intentionally to avoid exception loops when called after early return)
        if ($this->exception === null && $statusCode >= 500) { // @phpstan-ignore identical.alwaysTrue
            throw (new ServiceUnavailable(
                "ComfinoPay API service is unavailable: $reasonPhrase [$statusCode]",
                $statusCode,
                null,
                $this->request->getRequestUri(),
                $requestBody,
                $sanitizedResponseBody
            ))->setErrorDetails($nonNegotiatedBody, $idempotentFailure);
        }

        // 4xx status codes (guard re-checked intentionally to avoid exception loops when called after early return)
        if ($this->exception === null && $statusCode >= 400) { // @phpstan-ignore identical.alwaysTrue
            $errorBody = $deserializedResponseBody;

            throw (match ($statusCode) {
                400 => new RequestValidationError(
                    $this->getErrorMessage(
                        $statusCode,
                        $errorBody,
                        "Invalid request data: $reasonPhrase [$statusCode]"
                    ),
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody,
                    $sanitizedResponseBody,
                    $errorBody,
                    $this->response
                ),
                401 => new AuthorizationError(
                    $this->getErrorMessage(
                        $statusCode,
                        $errorBody,
                        "Invalid credentials: $reasonPhrase [$statusCode]"
                    ),
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody
                ),
                403 => new Forbidden(
                    $this->getErrorMessage(
                        $statusCode,
                        $errorBody,
                        "Access denied: $reasonPhrase [$statusCode]"
                    ),
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody,
                    $sanitizedResponseBody
                ),
                404 => new NotFound(
                    $this->getErrorMessage(
                        $statusCode,
                        $errorBody,
                        "Entity not found: $reasonPhrase [$statusCode]"
                    ),
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody,
                    $sanitizedResponseBody
                ),
                405 => new MethodNotAllowed(
                    $this->getErrorMessage(
                        $statusCode,
                        $errorBody,
                        "Method not allowed: $reasonPhrase [$statusCode]"
                    ),
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody,
                    $sanitizedResponseBody
                ),
                409 => new Conflict(
                    $this->getErrorMessage(
                        $statusCode,
                        $errorBody,
                        "Entity already exists: $reasonPhrase [$statusCode]"
                    ),
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody,
                    $sanitizedResponseBody
                ),
                429 => new TooManyRequests(
                    "Too many requests: $reasonPhrase [$statusCode]",
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody,
                    $sanitizedResponseBody,
                    $errorBody,
                    $this->response,
                    $this->parseRetryAfterSeconds()
                ),
                default => new RequestValidationError(
                    "Invalid request data: $reasonPhrase [$statusCode]",
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody,
                    $sanitizedResponseBody,
                    $errorBody,
                    $this->response
                )
            })->setErrorDetails($nonNegotiatedBody, $idempotentFailure);
        }

        if (($errorMessage = $this->getErrorMessage($statusCode, $deserializedResponseBody)) !== null) {
            throw new RequestValidationError(
                $errorMessage,
                $statusCode,
                null,
                $this->request->getRequestUri(),
                $requestBody,
                $sanitizedResponseBody,
                $deserializedResponseBody,
                $this->response
            );
        }

        try {
            $this->processResponseBody($deserializedResponseBody);
        } catch (ResponseValidationError $e) {
            $e->setUrl($this->request->getRequestUri());
            $e->setRequestBody($requestBody);
            $e->setResponseBody($sanitizedResponseBody);

            throw $e;
        }

        return $this;
    }

    /**
     * Fills response object properties with data from the deserialized API response array.
     *
     * @param array<string, mixed>|string|bool|int|float|null $deserializedResponseBody
     *
     * @throws ResponseValidationError
     */
    abstract protected function processResponseBody(array|string|bool|int|float|null $deserializedResponseBody): void;

    /**
     * Seconds the API asked for in `Retry-After` (delta-seconds or HTTP date), or null when absent or unparseable. Not
     * clamped: the value is reported as sent, and whoever waits on it applies their own ceiling.
     */
    private function parseRetryAfterSeconds(): ?int
    {
        $header = $this->response?->getHeaderLine('Retry-After') ?? '';

        if ($header === '') {
            return null;
        }

        $milliseconds = (new ErrorClassifier(PHP_INT_MAX))->parseRetryAfter($header);

        return $milliseconds !== null ? (int) ceil($milliseconds / 1000) : null;
    }

    /**
     * Reads the response body, refusing to buffer more than {@see MAX_RESPONSE_BODY_BYTES}.
     *
     * A declared `getSize()` over the cap is refused without reading a byte. Otherwise the body is pulled in chunks and
     * the read stops one byte past the cap, so an undeclared or chunked oversized body costs the cap plus one - never
     * the whole stream. An empty read breaks the loop as well as `eof()`, since a stream that never reports EOF would
     * otherwise spin forever.
     *
     * @throws ResponseValidationError When the body exceeds the cap
     */
    private function readBoundedResponseBody(): string
    {
        $stream = $this->response?->getBody();

        if ($stream === null) {
            return '';
        }

        if ($stream->isSeekable()) {
            /* Guarded rather than unconditional: a non-seekable stream - some transports hand one back - threw out of
               here on rewind(), turning an unbuffered response into a fatal rather than a parse. */
            $stream->rewind();
        }

        $limit = static::MAX_RESPONSE_BODY_BYTES;
        $declaredSize = $stream->getSize();

        if ($declaredSize !== null && $declaredSize > $limit) {
            throw $this->oversizedBodyError($declaredSize, $limit);
        }

        $body = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(min(self::READ_CHUNK_BYTES, $limit + 1 - strlen($body)));

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;

            if (strlen($body) > $limit) {
                throw $this->oversizedBodyError(null, $limit);
            }
        }

        return $body;
    }

    /**
     * The error for a body that would not fit, carrying no part of the body itself - it is either unbounded or already
     * known to be untrustworthy.
     *
     * @param int|null $declaredSize Size the response declared, or null when the body simply kept coming
     * @param int $limit Cap that was exceeded, in bytes
     */
    private function oversizedBodyError(?int $declaredSize, int $limit): ResponseValidationError
    {
        return new ResponseValidationError(
            sprintf(
                'ComfinoPay API response body exceeds the %d byte limit (%s).',
                $limit,
                $declaredSize !== null ? "declared $declaredSize bytes" : 'no declared length'
            ),
            0,
            null,
            $this->request->getRequestUri() ?? '',
            $this->request->getSanitizedRequestBody() ?? ''
        );
    }

    /**
     * Deserializes API response body using the specified serializer.
     *
     * @return array<string, mixed>|string|bool|int|float|null
     *
     * @throws ResponseValidationError
     */
    private function deserializeResponseBody(
        string $responseBody,
        SerializerInterface $serializer
    ): array|string|bool|int|float|null {
        return !empty($responseBody) ? $serializer->unserialize($responseBody) : null;
    }

    /**
     * Retrieves an error message based on the provided status code and deserialized response body.
     *
     * @param int $statusCode The HTTP status code received from the API response
     * @param array<string, mixed>|string|bool|int|float|null $deserializedResponseBody The decoded response body from the API response JSON string
     * @param string|null $defaultMessage The default error message to return if no specific errors are found in the response body
     *
     * @return string|null The constructed error message derived from the response body, or the default message if no specific errors are identified
     */
    private function getErrorMessage(
        int $statusCode,
        array|string|bool|int|float|null $deserializedResponseBody,
        ?string $defaultMessage = null
    ): ?string {
        if (!is_array($deserializedResponseBody)) {
            return $defaultMessage;
        }

        $errorMessages = [];

        if (isset($deserializedResponseBody['errors']) && is_array($deserializedResponseBody['errors'])) {
            foreach ($deserializedResponseBody['errors'] as $errorFieldName => $errorMessage) {
                $errorMessages[] = "$errorFieldName: " . self::stringifyErrorValue($errorMessage);
            }
        } elseif (isset($deserializedResponseBody['message'])) {
            $errorMessages = [self::stringifyErrorValue($deserializedResponseBody['message'])];
        } elseif ($statusCode >= 400) {
            foreach ($deserializedResponseBody as $errorFieldName => $errorMessage) {
                $errorMessages[] = "$errorFieldName: " . self::stringifyErrorValue($errorMessage);
            }
        }

        return count($errorMessages) ? implode("\n", $errorMessages) : $defaultMessage;
    }

    /**
     * Renders one value out of an error body as a string, whatever shape the API sent it in.
     *
     * The far side decides this shape, so the reader has to survive all of it. `errors` is documented as a field name to
     * message, but a per-field *list* of messages (`{"cart": ["too big", "also bad"]}`) is the other common REST
     * spelling, and a numeric or boolean value costs nothing to allow. Reading these through closures typed `string`
     * turned a clean 400 into an uncaught `TypeError` - not a `ResponseValidationError`, so nothing in the library or a
     * plugin's error handling caught it - and interpolating an array raised "Array to string conversion" and put the
     * literal text `Array` in the merchant's error message. A response body cannot be allowed to choose which exception
     * class comes out of a validation error.
     *
     * @param mixed $value One value from an error response body
     *
     * @return string The value as text; nested lists are flattened, anything unrenderable becomes its JSON form
     */
    private static function stringifyErrorValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return var_export($value, true);
        }

        if (is_array($value)) {
            return implode(', ', array_map(static fn (mixed $item): string => self::stringifyErrorValue($item), $value));
        }

        /* An object or a resource: nothing sensible to interpolate, so fall back to a shape the reader can still act on
           rather than rising inside error handling. */
        return json_encode($value) ?: get_debug_type($value);
    }
}
