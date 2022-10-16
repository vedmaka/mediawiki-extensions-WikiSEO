<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

declare( strict_types=1 );

namespace MediaWiki\Extension\WikiSEO;

use DeferrableUpdate;
use Exception;
use ExtensionDependencyError;
use MediaWiki\MediaWikiServices;
use MWException;
use PageProps;
use Title;

class DeferredDescriptionUpdate implements DeferrableUpdate {

	/**
	 * @var Title The title to work on
	 */
	private $title;

	/**
	 * @var bool Whether to cut of dangling sentences
	 */
	private $clean;

	/**
	 * Do an deferred update to the specified title.
	 * Usually runs when RevisionDataUpdates occurs
	 *
	 * @param Title $title
	 * @param bool $cleanDescription
	 */
	public function __construct( Title $title, bool $cleanDescription = false ) {
		$this->title = $title;
		$this->clean = $cleanDescription;
	}

	/**
	 * We do have to manually set the page properties, as we have no way of getting the parser or outputpage
	 * in an deferred update
	 */
	public function doUpdate(): void {
		try {
			$apiDescription = $this->loadDescriptionFromApi();
		} catch ( Exception $e ) {
			return;
		}

		$apiDescription = trim( $apiDescription ?? '' );

		if ( $apiDescription === '' || $apiDescription === '…' || $apiDescription === "\u2026" ) {
			return;
		}

		if ( method_exists( MediaWikiServices::class, 'getPageProps' ) ) {
			// MW 1.36+
			$propertyDescriptions = MediaWikiServices::getInstance()->getPageProps()
				->getProperties( $this->title, 'description' );
		} else {
			$propertyDescriptions = PageProps::getInstance()->getProperties( $this->title, 'description' );
		}

		$dbl = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$db = $dbl->getConnection( $dbl->getWriterIndex() );

		// Flag indicating if an insert or update should happen
		$shouldInsert = false;
		switch ( true ) {
			case count( $propertyDescriptions ) > 1:
				// There are multiple page props with the name 'description' present
				// This shouldn't happen, but we'll try to clean it here
				$db->delete(
					'page_props',
					[
						'pp_page' => $this->title->getArticleID(),
						'pp_propname' => 'description',
					]
				);
			// Intentional fall-through, as deleting all 'description' props requires inserting a new row
			case empty( $propertyDescriptions ):
				$shouldInsert = true;
				break;

			default:
				break;
		}

		if ( count( $propertyDescriptions ) === 1 ) {
			$prop = array_shift( $propertyDescriptions );
			// Sanity check
			$descriptionEqual = strcmp( $prop ?? '', $apiDescription ) === 0;
			if ( $descriptionEqual ) {
				return;
			}
		}

		if ( $shouldInsert ) {
			$db->insert(
				'page_props',
				[
					'pp_page' => $this->title->getArticleID(),
					'pp_propname' => 'description',
					'pp_value' => $apiDescription,
					'pp_sortkey' => null,
				],
				__METHOD__
			);
		} else {
			$db->update(
				'page_props',
				[
					'pp_value' => $apiDescription,
				],
				[
					'pp_page' => $this->title->getArticleID(),
					'pp_propname' => 'description',
				],
				__METHOD__
			);
		}
	}

	/**
	 * @return string|null
	 * @throws ExtensionDependencyError
	 * @throws MWException
	 */
	private function loadDescriptionFromApi(): ?string {
		$descriptor = new ApiDescription(
			$this->title,
			$this->clean
		);

		return $descriptor->getDescription();
	}
}
