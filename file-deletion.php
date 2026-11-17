<?php

namespace BricksVariableFonts;

use Bricks\Custom_Font_Store;
use Bricks\Custom_Fonts;

defined( 'ABSPATH' ) || exit;

/**
 * Detach sources and refresh native CSS in one transaction before removing
 * files from disk. An I/O failure can then leave an unused file, never a
 * published face pointing to a file already deleted by this request.
 */
function delete_font_files( array $input ) {
	$selection = $input['files'] ?? null;
	if ( ! is_array( $selection ) || ! $selection || count( $selection ) > 100 ) {
		return failure( 'Select up to 100 font files to delete.' );
	}

	$touched = [];
	$result = font_transaction( static function () use ( $selection, &$touched ) {
		$files = [];
		foreach ( $selection as $entry ) {
			$source = $entry['id'] ?? null;
			$numeric = ( is_string( $source ) || is_int( $source ) ) && preg_match( '/^[1-9][0-9]*$/D', (string) $source );
			$managed = ! $numeric && is_string( $source ) && preg_match( '~^bricks/fonts/google/([1-9][0-9]*)/[a-zA-Z0-9_-]+\.(woff2|woff|ttf)$~D', $source, $match );
			$owner = $managed ? (int) $match[1] : 0;
			if ( ( ! $numeric && ! $managed )
				|| ( $numeric && ! current_user_can( 'delete_post', (int) $source ) )
				|| ( $managed && ! current_user_can( 'edit_post', $owner ) )
				|| ! is_array( $entry['families'] ?? null ) ) {
				return failure( 'A selected font file is unavailable or you do not have permission to delete it.' );
			}
			$info = file_info( $source );
			if ( ! $info ) {
				return failure( 'A selected font file is no longer in the library. Refresh the list and try again.' );
			}
			$path = $numeric ? get_attached_file( (int) $source ) : Custom_Fonts::get_font_file_path( $source );
			$expected = array_map( 'absint', $entry['families'] );
			sort( $expected );
			$files[ $source ] = [
				'name' => $info['name'], 'path' => $path, 'owner' => $owner,
				'expected' => $expected, 'families' => [],
			];
		}

		$ids = get_posts( [
			'post_type' => BRICKS_DB_CUSTOM_FONTS,
			'post_status' => [ 'publish', 'trash', 'draft', 'pending', 'private', 'future' ],
			'posts_per_page' => -1, 'fields' => 'ids', 'cache_results' => false,
		] );
		$changes = [];
		foreach ( $ids as $id ) {
			wp_cache_delete( $id, 'post_meta' );
			$old = get_post_meta( $id, BRICKS_DB_CUSTOM_FONT_FACES, true );
			if ( ! is_array( $old ) ) {
				continue;
			}
			$faces = $old;
			foreach ( $faces as $variant => $face ) {
				if ( ! is_array( $face ) ) {
					continue;
				}
				$is_list = isset( $face[0] ) && is_array( $face[0] );
				$subsets = $is_list ? $face : [ $face ];
				$changed = false;
				foreach ( $subsets as $index => $subset ) {
					if ( ! is_array( $subset ) ) {
						continue;
					}
					$removed = false;
					foreach ( array_intersect_key( $subset, array_flip( FORMATS ) ) as $format => $source ) {
						if ( ( is_string( $source ) || is_int( $source ) ) && isset( $files[ $source ] ) ) {
							$files[ $source ]['families'][ $id ] = $id;
							unset( $subset[ $format ] );
							$removed = true;
							$changed = true;
						}
					}
					if ( $removed && ! array_intersect_key( $subset, array_flip( FORMATS ) ) ) {
						unset( $subsets[ $index ] );
					} else {
						$subsets[ $index ] = $subset;
					}
				}
				if ( ! $changed ) {
					continue;
				}
				if ( ! $subsets ) {
					unset( $faces[ $variant ] );
				} else {
					$faces[ $variant ] = $is_list ? array_values( $subsets ) : reset( $subsets );
				}
			}

			if ( $faces !== $old ) {
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return failure( 'You need permission to edit every Bricks family using the selected files.' );
				}
				$changes[ $id ] = $faces;
			}
		}

		foreach ( $files as $file ) {
			$actual = array_values( $file['families'] );
			sort( $actual );
			if ( $actual !== $file['expected'] ) {
				return failure( 'A font’s family assignments changed or a draft uses it. Refresh the list before deleting it.' );
			}
		}

		foreach ( $changes as $id => $faces ) {
			$touched[] = $id;
			if ( ! update_post_meta( $id, BRICKS_DB_CUSTOM_FONT_FACES, wp_slash( $faces ) ) ) {
				return failure( 'Bricks could not detach the selected font files.' );
			}
			if ( class_exists( Custom_Font_Store::class ) && Custom_Font_Store::is_active() ) {
				Custom_Font_Store::touch( $id );
			}
		}
		if ( $changes ) {
			Custom_Fonts::refresh_font_face_rules();
		}
		return [ 'files' => $files, 'families' => count( $changes ) ];
	} );

	if ( is_wp_error( $result ) ) {
		foreach ( $touched as $id ) {
			wp_cache_delete( $id, 'post_meta' );
		}
		return $result;
	}

	$deleted = [];
	$failed = [];
	foreach ( $result['files'] as $source => $file ) {
		$owner = $file['owner'];
		if ( $owner ) {
			if ( $file['path'] ) {
				wp_delete_file( $file['path'] );
			}
			$removed = $file['path'] && ! is_file( $file['path'] );
			if ( $removed ) {
				$known = (array) get_post_meta( $owner, '_bricks_google_font_files', true );
				update_post_meta( $owner, '_bricks_google_font_files', array_values( array_diff( $known, [ $source ] ) ) );
			}
		} else {
			$removed = (bool) wp_delete_attachment( (int) $source, true ) && ( ! $file['path'] || ! is_file( $file['path'] ) );
		}
		if ( $removed ) {
			$deleted[] = $file['name'];
		} else {
			$failed[] = $file['name'];
		}
	}
	if ( $result['families'] ) {
		sync_acss();
	}
	return [ 'deleted' => $deleted, 'failed' => $failed, 'families' => $result['families'] ];
}
