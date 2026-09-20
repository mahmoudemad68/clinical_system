<?php

declare(strict_types=1);

/*
 * Pest is the primary PHP test runner for Core.
 *
 * Existing PHPUnit *Test.php classes remain as the in-place suite. New
 * coverage uses describe/it/expect in this directory.
 */

require_once __DIR__.'/Support/patientHttpHelpers.php';
require_once __DIR__.'/Support/doctorHttpHelpers.php';
require_once __DIR__.'/Support/pharmacyHttpHelpers.php';
require_once __DIR__.'/Support/verificationHttpHelpers.php';
require_once __DIR__.'/Support/verificationMediaFixtures.php';
require_once __DIR__.'/Support/adminVerificationHttpHelpers.php';
