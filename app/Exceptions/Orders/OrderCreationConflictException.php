<?php

namespace App\Exceptions\Orders;

use RuntimeException;

/**
 * Raised when the table session that looked active when the request was
 * resolved has been closed concurrently (see the lockForUpdate() recheck in
 * OrderCreationService) by the time the order is actually persisted.
 * Rendered as 409 ORDER_CREATION_CONFLICT — see bootstrap/app.php.
 */
class OrderCreationConflictException extends RuntimeException {}
