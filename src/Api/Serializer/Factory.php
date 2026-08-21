<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Serializer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Serializer;

use Comfino\Api\SerializerInterface;

/**
 * Creates serializer instances by Content-Type, with JSON as the default fallback.
 *
 * Built-in registrations on construction:
 *   - application/json → Json
 *   - application/msgpack → MsgPack (only when ext-msgpack is loaded)
 *
 * Additional serializers can be registered via register().
 */
class Factory
{
    /** @var array<string, SerializerInterface> Content-Type → serializer map */
    private array $serializers = [];

    private readonly SerializerInterface $default;

    /**
     * @param SerializerInterface|null $default Default serializer to use when no match is found
     */
    public function __construct(?SerializerInterface $default = null)
    {
        $this->default = $default ?? new Json();

        $this->register(new Json());

        if (extension_loaded('msgpack')) {
            $this->register(new MsgPack());
        }
    }

    /**
     * Registers a serializer under its own Content-Type key.
     * Re-registering the same Content-Type replaces the previous entry.
     */
    public function register(SerializerInterface $serializer): void
    {
        $this->serializers[$serializer->getContentType()] = $serializer;
    }

    /**
     * Returns a serializer matching the given Content-Type, or the configured default when no match is found.
     *
     * Content-Type parameters (e.g. '; charset=utf-8') are stripped before matching.
     */
    public function createFromContentType(string $contentType): SerializerInterface
    {
        return $this->serializers[$this->normalize($contentType)] ?? $this->default;
    }

    /**
     * Returns true when a serializer is registered for the given Content-Type.
     */
    public function supports(string $contentType): bool
    {
        return isset($this->serializers[$this->normalize($contentType)]);
    }

    private function normalize(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType)[0]));
    }
}
