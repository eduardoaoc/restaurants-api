<?php

namespace App\Exceptions\Tables;

use RuntimeException;

/**
 * Raised when "call responsible waiter" is attempted on a TableSession
 * with no eligible assigned waiter to call — either assigned_waiter_user_id
 * is null, or the assigned user is suspended. Rendered as 409
 * TABLE_SESSION_HAS_NO_ASSIGNED_WAITER — see bootstrap/app.php.
 */
class TableSessionHasNoAssignedWaiterException extends RuntimeException {}
