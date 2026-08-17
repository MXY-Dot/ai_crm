<?php

namespace App\Support\Ai\Exceptions;

use RuntimeException;

/**
 * Thrown when a provider responds HTTP 429 — distinct from a real failure
 * (dead key, timeout, 5xx) so LlmClient::complete() can skip it around
 * HealthMonitor::recordFailure(). A 429 means the provider is working fine and
 * telling us to slow down, not that it's down; counting it toward the circuit
 * breaker would open the breaker because of our own traffic, not an outage.
 */
class ProviderRateLimitedException extends RuntimeException
{
}
