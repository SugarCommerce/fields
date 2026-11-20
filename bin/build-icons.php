<?php
/**
 * Bring an upstream icon release into assets/icons.
 *
 * The kit ships Heroicons as folders of SVG files, one per icon. (The
 * `wordpress` set is not shipped: it is core's own icon library, read from
 * WP 7.1's Icons API by Icons\CoreIcons.) This copies them out of an upstream release,
 * each file put through the same cleaning every icon gets before it is
 * drawn (Icons\Svg::sanitize()) -- so what is committed is exactly what is
 * printed: no comments, no `aria-hidden`, no `data-slot`, no `style`, no
 * whitespace between tags.
 *
 * Usage, from the library's root, with the release unpacked somewhere:
 *
 *   php bin/build-icons.php heroicons /path/to/heroicons-2.2.0
 *
 * Heroicons comes from https://github.com/tailwindlabs/heroicons/releases
 * (`optimized/24/outline` and `optimized/24/solid`). Update
 * Icons\Icons::HEROICONS_VERSION and the README's table when a release
 * changes.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

use SugarCommerce\Security\Svg;

require __DIR__ . '/../vendor/autoload.php';

$fk_set    = $argv[1] ?? '';
$fk_source = rtrim( $argv[2] ?? '', '/' );
$fk_assets = dirname( __DIR__ ) . '/assets/icons';

$fk_plans = [
	'heroicons' => [
		'license' => [ 'LICENSE' ],
		'folders' => [
			'optimized/24/outline' => 'heroicons/outline',
			'optimized/24/solid'   => 'heroicons/solid',
		],
	],
];

if ( ! isset( $fk_plans[ $fk_set ] ) || ! is_dir( $fk_source ) ) {
	fwrite( STDERR, "Usage: php bin/build-icons.php heroicons <unpacked release>\n" );
	exit( 1 );
}

$fk_plan = $fk_plans[ $fk_set ];

foreach ( $fk_plan['folders'] as $fk_from => $fk_to ) {
	$fk_target = $fk_assets . '/' . $fk_to;

	if ( ! is_dir( $fk_target ) ) {
		mkdir( $fk_target, 0755, true );
	}

	// Start clean, so an icon upstream removed is removed here too.
	foreach ( glob( $fk_target . '/*.svg' ) ?: [] as $fk_old ) {
		unlink( $fk_old );
	}

	$fk_written = 0;
	$fk_bytes   = 0;

	foreach ( glob( $fk_source . '/' . $fk_from . '/*.svg' ) ?: [] as $fk_file ) {
		$fk_clean = Svg::sanitize( (string) file_get_contents( $fk_file ) );

		if ( '' === $fk_clean ) {
			fwrite( STDERR, 'skipped, not a clean icon: ' . basename( $fk_file ) . "\n" );
			continue;
		}

		file_put_contents( $fk_target . '/' . strtolower( basename( $fk_file ) ), $fk_clean );

		++$fk_written;
		$fk_bytes += strlen( $fk_clean );
	}

	printf( "%s: %d icons, %s bytes\n", $fk_to, $fk_written, number_format( $fk_bytes ) );
}

// The licence goes beside the icons it covers, as the licence asks.
foreach ( $fk_plan['license'] as $fk_license ) {
	$fk_folder = $fk_assets . '/' . explode( '/', reset( $fk_plan['folders'] ) )[0];

	copy( $fk_source . '/' . $fk_license, $fk_folder . '/' . $fk_license );
	printf( "%s copied to %s\n", $fk_license, $fk_folder );
}
