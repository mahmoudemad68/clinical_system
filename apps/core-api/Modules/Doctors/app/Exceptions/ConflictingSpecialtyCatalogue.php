<?php

declare(strict_types=1);

namespace Modules\Doctors\Exceptions;

use RuntimeException;

/**
 * Fail-closed upgrade error when specialties are not empty and do not already
 * equal the approved v1.0.0-phase02 catalogue. The installer must not overwrite,
 * delete, activate, or leave unauthorized rows in place.
 */
final class ConflictingSpecialtyCatalogue extends RuntimeException {}
