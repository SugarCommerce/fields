<?php
/**
 * Files Field Type
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Contracts\AdminOnly;

/**
 * A named list of files.
 *
 * The shape every product, download and resource ends up needing: a row per
 * file, each with a name to show and a file to serve. It is a table repeater
 * with its two columns already decided, because every consumer that wanted
 * this wrote the same two.
 *
 * The file column is `file_url` rather than `file`: the URL stays visible and
 * editable beside the media button, so a file hosted somewhere else can be
 * pasted in. A picker that only ever returns an attachment id cannot express
 * that, and "the file is on S3" is the common case this exists for.
 *
 * The columns are defaults rather than fixtures. A caller who passes `fields`
 * gets theirs — a third column for a download limit, say — and still gets the
 * table layout and the wording.
 */
final class FilesType extends RepeaterType implements AdminOnly {

	/**
	 * The type's id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'files';
	}

	/**
	 * Two columns, a table, and wording about files rather than rows.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array_merge(
			parent::defaults(),
			[
				'layout'      => 'table',
				'add_label'   => __( 'Add file', 'sugarcommerce' ),
				'empty_label' => __( 'No files yet.', 'sugarcommerce' ),
				'fields'      => [
					'name' => [
						'type'         => 'text',
						'label'        => __( 'Name', 'sugarcommerce' ),
						'placeholder'  => __( 'What the customer sees', 'sugarcommerce' ),
						'column_width' => '40%',
					],
					'file' => [
						'type'        => 'file_url',
						'label'       => __( 'File', 'sugarcommerce' ),
						'placeholder' => __( 'Choose or paste a URL', 'sugarcommerce' ),
					],
				],
			]
		);
	}
}
