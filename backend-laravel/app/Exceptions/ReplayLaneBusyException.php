<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The bounded Python replay worker is healthy but currently occupied.
 *
 * This is queue admission state, never strategy evidence or a technical
 * failure. Callers must release the same immutable job for a later attempt.
 */
class ReplayLaneBusyException extends RuntimeException
{
}
