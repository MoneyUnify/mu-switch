<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised when no configured provider can take a payment; the message says why.
 */
class NoEligibleProviderException extends Exception {}
