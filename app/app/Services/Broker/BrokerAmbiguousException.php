<?php

namespace App\Services\Broker;

use RuntimeException;

/**
 * Timeout / unknown HTTP outcome after a place attempt. Must not be retried as a new place.
 */
class BrokerAmbiguousException extends RuntimeException {}
