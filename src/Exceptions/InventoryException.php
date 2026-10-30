<?php

namespace MonkeySoft\SitesMonkey\Exceptions;

use RuntimeException;

/**
 * The inventory could not be collected. Messages describe the problem only: they never contain file contents.
 */
class InventoryException extends RuntimeException {}
