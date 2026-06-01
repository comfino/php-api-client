<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Serializer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Serializer;

use Comfino\Api\Serializer\Factory;
use Comfino\Api\Serializer\Json;
use Comfino\Api\SerializerInterface;
use PHPUnit\Framework\TestCase;

final class SerializerFactoryTest extends TestCase
{
    private Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Factory();
    }

    public function testDefaultFactorySupportsJson(): void
    {
        $this->assertTrue($this->factory->supports('application/json'));
    }

    public function testCreateFromContentTypeReturnsJsonSerializer(): void
    {
        $this->assertInstanceOf(Json::class, $this->factory->createFromContentType('application/json'));
    }

    public function testCreateFromContentTypeStripsCharsetParameter(): void
    {
        $this->assertInstanceOf(Json::class, $this->factory->createFromContentType('application/json; charset=utf-8'));
    }

    public function testCreateFromContentTypeIsCaseInsensitive(): void
    {
        $this->assertInstanceOf(Json::class, $this->factory->createFromContentType('Application/JSON'));
    }

    public function testUnsupportedContentTypeFallsBackToDefault(): void
    {
        $default = new Json();
        $serializer = (new Factory($default))->createFromContentType('text/plain');

        $this->assertSame($default, $serializer);
    }

    public function testSupportsReturnsFalseForUnknownContentType(): void
    {
        $this->assertFalse($this->factory->supports('text/xml'));
    }

    public function testRegisterAddsCustomSerializer(): void
    {
        $custom = new class extends Json {
            public function getContentType(): string
            {
                return 'application/x-custom';
            }
        };

        $this->factory->register($custom);

        $this->assertTrue($this->factory->supports('application/x-custom'));
        $this->assertSame($custom, $this->factory->createFromContentType('application/x-custom'));
    }

    public function testDefaultSerializerIsJsonWhenNoneProvided(): void
    {
        $this->assertInstanceOf(Json::class, (new Factory())->createFromContentType('text/unknown'));
    }

    public function testCustomDefaultIsReturnedOnNoMatch(): void
    {
        $customDefault = $this->createMock(SerializerInterface::class);
        $customDefault->method('getContentType')->willReturn('application/x-mock');

        $this->assertSame($customDefault, (new Factory($customDefault))->createFromContentType('text/plain'));
    }
}
