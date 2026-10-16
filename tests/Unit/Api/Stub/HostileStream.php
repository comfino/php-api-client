<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Stub
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Stub;

use LogicException;
use Psr\Http\Message\StreamInterface;

/**
 * A response body that never ends standing in for a host answering a widget-key lookup with a firehose.
 *
 * Generating the bytes on demand is the whole point: a test that allocated a real oversized string to prove the reader
 * refuses oversized strings would have to allocate the thing the reader exists to avoid allocating. This yields identical
 * filler forever, counts what it handed out, and can be told to declare a size it has no intention of honoring - or to
 * treat any read at all as a failure, which is how "refused without reading a byte" is asserted.
 */
final class HostileStream implements StreamInterface
{
    private int $bytesRead = 0;

    /**
     * @param int|null $declaredSize What `getSize()` reports; null models a chunked response of unknown length
     * @param bool $failOnRead When true, any read is a test failure - the reader was supposed to refuse first
     * @param bool $seekable Whether the stream claims to be seekable
     */
    public function __construct(
        private readonly ?int $declaredSize = null,
        private readonly bool $failOnRead = false,
        private readonly bool $seekable = true
    ) {
    }

    /** Bytes this stream has actually handed out, which is what bounds the reader's memory use. */
    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    public function read(int $length): string
    {
        if ($this->failOnRead) {
            throw new LogicException('The reader was expected to refuse this body without reading it.');
        }

        $this->bytesRead += $length;

        return str_repeat('x', $length);
    }

    public function eof(): bool
    {
        return false;
    }

    public function getSize(): ?int
    {
        return $this->declaredSize;
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function rewind(): void
    {
        if (!$this->seekable) {
            throw new LogicException('Cannot rewind a non-seekable stream.');
        }
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->rewind();
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
    }

    /** @return resource|null */
    public function detach()
    {
        return null;
    }

    public function tell(): int
    {
        return $this->bytesRead;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new LogicException('Not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getContents(): string
    {
        throw new LogicException('getContents() is unbounded - the reader must not call it.');
    }

    /**
     * @param string|null $key
     *
     * @return mixed
     */
    public function getMetadata($key = null)
    {
        return $key === null ? [] : null;
    }
}
