<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Retry
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Retry;

use InvalidArgumentException;

/**
 * Represents timeout configuration for API requests.
 */
final class TimeoutConfig
{
    /**
     * @param int $connectionTimeout Connection timeout in seconds
     * @param int $transferTimeout Transfer timeout in seconds
     *
     * @throws InvalidArgumentException If the connection timeout is negative or transfer timeout is less than
     *                                   connection timeout
     */
    public function __construct(public readonly int $connectionTimeout, public readonly int $transferTimeout)
    {
        if ($connectionTimeout < 0) {
            throw new InvalidArgumentException('Connection timeout cannot be negative.');
        }

        if ($transferTimeout < 0) {
            throw new InvalidArgumentException('Transfer timeout cannot be negative.');
        }

        if ($transferTimeout < $connectionTimeout) {
            throw new InvalidArgumentException('Transfer timeout must be greater than or equal to connection timeout.');
        }
    }

    /**
     * Creates a timeout configuration from a retry policy and attempt number.
     *
     * @param RetryPolicyInterface $policy The retry policy to use
     * @param int $attemptNumber The attempt number
     *
     * @return self The timeout configuration
     */
    public static function fromRetryPolicy(RetryPolicyInterface $policy, int $attemptNumber): self
    {
        return new self($policy->getConnectionTimeout($attemptNumber), $policy->getTransferTimeout($attemptNumber));
    }

    /**
     * Compares this timeout configuration with another for equality.
     *
     * @param self $other The timeout configuration to compare with
     *
     * @return bool True if the configurations are equal, false otherwise
     */
    public function equals(self $other): bool
    {
        return
            $this->connectionTimeout === $other->connectionTimeout &&
            $this->transferTimeout === $other->transferTimeout;
    }

    public function __toString(): string
    {
        return sprintf('TimeoutConfig(connection=%ds, transfer=%ds)', $this->connectionTimeout, $this->transferTimeout);
    }
}
