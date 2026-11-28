<?php

namespace App\Exceptions;

use RuntimeException;

/** A read-only broker request was explicitly rejected with HTTP 401/403. */
final class BrokerReadAuthenticationException extends RuntimeException {}
