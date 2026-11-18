<?php
/**
 * PHPUnit bootstrap.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

/*
 * Before the autoloader, because ABSPATH has to exist by the time Composer
 * runs any files-autoloaded entrypoint that guards on it.
 */
require_once __DIR__ . '/stubs.php';

// The screens' own (metabox, term, profile, quick and bulk edit), after the
// engine's, so where both define a function the engine's is the one in use.
require_once __DIR__ . '/Screens/stubs.php';

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
