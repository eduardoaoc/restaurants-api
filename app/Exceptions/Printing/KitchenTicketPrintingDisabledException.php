<?php

namespace App\Exceptions\Printing;

use RuntimeException;

/**
 * Raised when a manual kitchen ticket print is attempted for a restaurant
 * whose RestaurantSettings::kitchen_ticket_printing_enabled is false.
 * Preview (GET) is never gated by this — only the POST /print side effect.
 * Rendered as 409 KITCHEN_TICKET_PRINTING_DISABLED — see bootstrap/app.php.
 */
class KitchenTicketPrintingDisabledException extends RuntimeException {}
