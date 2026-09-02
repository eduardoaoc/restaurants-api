<?php

namespace App\Exceptions\Public;

use RuntimeException;

/**
 * Raised whenever a public token cannot be resolved to a servable table:
 * unknown token, inactive/blocked table, or inactive restaurant. The
 * distinct causes are intentionally never surfaced to the client — see
 * bootstrap/app.php for the neutral 404 PUBLIC_TABLE_NOT_FOUND rendering.
 */
class PublicTableNotFoundException extends RuntimeException {}
