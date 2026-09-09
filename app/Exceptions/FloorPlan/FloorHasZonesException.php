<?php

namespace App\Exceptions\FloorPlan;

use RuntimeException;

/**
 * Raised when deleting a Floor that still has zones attached. Zones must be
 * removed (or moved) explicitly first — a Floor delete never cascades.
 * Rendered as 409 FLOOR_HAS_ZONES — see bootstrap/app.php.
 */
class FloorHasZonesException extends RuntimeException {}
