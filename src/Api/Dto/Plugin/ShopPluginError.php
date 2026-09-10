<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Dto\Plugin
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Dto\Plugin;

use Comfino\Api\SerializerInterface;
use Comfino\Api\Serializer\Json;

/**
 * Represents an error encountered during payment plugin execution on a shop.
 *
 * Structured error logging (message format version 1): version fields, the technical {@see ErrorCategory}, the
 * {@see ErrorSeverity} hint and the {@see OperationContext} are carried as dedicated typed fields rather than
 * being embedded in errorMessage or buried in the environment array, so the API-side classifier never has to
 * parse the message text.
 */
final class ShopPluginError
{
    /**
     * @param string $host Host name of the shop
     * @param string $platform E-commerce platform name
     * @param string $pluginVersion ComfinoPay plugin version
     * @param string $platformVersion E-commerce platform version
     * @param string $phpVersion PHP runtime version
     * @param ErrorCategory $category Technical classification of the error
     * @param ErrorSeverity $severity Severity hint for the classification pipeline
     * @param OperationContext $context What the plugin was doing when the error occurred
     * @param string $errorCode Error code (PHP error level, exception class, or HTTP status)
     * @param string $errorMessage Error message (no prefix, dynamic parts already normalized)
     * @param array<array-key, mixed> $environment Supplementary environment / debug variables
     * @param string|null $apiEndpoint Canonical API endpoint path (host and query stripped)
     * @param string|null $apiRequestUrl Full API request URL
     * @param string|null $apiRequest API request payload
     * @param string|null $apiResponse API response payload
     * @param string|null $stackTrace Stack trace for debugging
     * @param int|null $occurredAt Unix timestamp (seconds) when the error occurred shop-side
     */
    public function __construct(
        public string $host,
        public string $platform,
        public string $pluginVersion,
        public string $platformVersion,
        public string $phpVersion,
        public ErrorCategory $category,
        public ErrorSeverity $severity,
        public OperationContext $context,
        public string $errorCode,
        public string $errorMessage,
        public array $environment = [],
        public ?string $apiEndpoint = null,
        public ?string $apiRequestUrl = null,
        public ?string $apiRequest = null,
        public ?string $apiResponse = null,
        public ?string $stackTrace = null,
        public ?int $occurredAt = null
    ) {
    }

    /**
     * Serializes the DTO to a flat scalar array for storage in the outbound request queue.
     *
     * Nullable string fields are stored as empty strings and enums as their backing value, so the payload is
     * scalar-only. The environment array is serialized using the provided serializer.
     *
     * @return array<string, string>
     */
    public function toQueuePayload(SerializerInterface $serializer): array
    {
        return [
            'host' => $this->host,
            'platform' => $this->platform,
            'pluginVersion' => $this->pluginVersion,
            'platformVersion' => $this->platformVersion,
            'phpVersion' => $this->phpVersion,
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'context' => $this->context->value,
            'errorCode' => $this->errorCode,
            'errorMessage' => $this->errorMessage,
            'environment' => $serializer->serialize($this->environment),
            'apiEndpoint' => $this->apiEndpoint ?? '',
            'apiRequestUrl' => $this->apiRequestUrl ?? '',
            'apiRequest' => $this->apiRequest ?? '',
            'apiResponse' => $this->apiResponse ?? '',
            'stackTrace' => $this->stackTrace ?? '',
            'occurredAt' => $this->occurredAt !== null ? (string) $this->occurredAt : '',
        ];
    }

    /**
     * Reconstructs a ShopPluginError from a queue payload produced by {@see toQueuePayload()}.
     *
     * Empty strings are converted back to null for the nullable fields. Unknown enum values fall back to the safe
     * defaults ({@see ErrorCategory::Other}, {@see ErrorSeverity::Error}, {@see OperationContext::Unknown}) so a
     * stale payload from an older SDK never throws.
     *
     * @param array<string, scalar> $data
     */
    public static function fromQueuePayload(array $data, ?SerializerInterface $serializer = null): self
    {
        $serializer ??= new Json();
        $rawEnv = (string) ($data['environment'] ?? '');
        $environment = $rawEnv !== '' ? $serializer->unserialize($rawEnv) : [];

        return new self(
            (string) ($data['host'] ?? ''),
            (string) ($data['platform'] ?? ''),
            (string) ($data['pluginVersion'] ?? ''),
            (string) ($data['platformVersion'] ?? ''),
            (string) ($data['phpVersion'] ?? ''),
            ErrorCategory::tryFrom((string) ($data['category'] ?? '')) ?? ErrorCategory::Other,
            ErrorSeverity::tryFrom((string) ($data['severity'] ?? '')) ?? ErrorSeverity::Error,
            OperationContext::tryFrom((string) ($data['context'] ?? '')) ?? OperationContext::Unknown,
            (string) ($data['errorCode'] ?? ''),
            (string) ($data['errorMessage'] ?? ''),
            is_array($environment) ? $environment : [],
            ($data['apiEndpoint'] ?? '') !== '' ? (string) $data['apiEndpoint'] : null,
            ($data['apiRequestUrl'] ?? '') !== '' ? (string) $data['apiRequestUrl'] : null,
            ($data['apiRequest'] ?? '') !== '' ? (string) $data['apiRequest'] : null,
            ($data['apiResponse'] ?? '') !== '' ? (string) $data['apiResponse'] : null,
            ($data['stackTrace'] ?? '') !== '' ? (string) $data['stackTrace'] : null,
            ($data['occurredAt'] ?? '') !== '' ? (int) $data['occurredAt'] : null
        );
    }
}
