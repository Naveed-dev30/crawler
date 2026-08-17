<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A fetch that failed for a reason the operator needs to act on — bad or expired
 * credentials, an API error, a malformed response. Deliberately distinct from
 * "the search returned no jobs", which is an empty array and not a failure.
 */
class UpworkFetchException extends RuntimeException {}
