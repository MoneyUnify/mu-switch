<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A transfer action that isn't allowed; the message says why.
 */
class TransferException extends RuntimeException {}
