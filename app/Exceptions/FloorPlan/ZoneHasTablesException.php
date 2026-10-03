<?php

namespace App\Exceptions\FloorPlan;

use RuntimeException;

/**
 * Raised when deleting a Zone that still has tables assigned to it. Tables
 * must be reassigned to another zone (or unassigned) explicitly first — a
 * Zone delete never cascades or nulls out its tables' zone_id implicitly.
 * Rendered as 409 ZONE_HAS_TABLES — see bootstrap/app.php.
 */
class ZoneHasTablesException extends RuntimeException {}
