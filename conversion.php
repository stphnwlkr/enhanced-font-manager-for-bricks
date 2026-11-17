<?php

namespace BricksVariableFonts;

use Bricks\Abilities\Fonts;
use Bricks\Custom_Font_Store;
use Bricks\Custom_Fonts;

defined( 'ABSPATH' ) || exit;

const CONVERSION_META = '_bvf_woff2_conversion';
const CONVERTIBLE = [ 'woff', 'ttf', 'otf' ];

function convert_saved_file( array $input, array $files ) {
	$source = absint( $input['source'] ?? 0 );
	if ( ! $source || true !== Fonts::upload_permission() || ! current_user_can( 'edit_post', $source ) ) {
		return failure( 'You do not have permission to convert this font file.' );
	}

	$path = get_attached_file( $source );
	$format = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );
	if ( get_post_type( $source ) !== 'attachment' || get_post_status( $source ) === 'trash'
		|| ! in_array( $format, CONVERTIBLE, true ) || ! $path || ! is_readable( $path ) ) {
		return failure( 'The original WOFF, TTF or OTF attachment is unavailable.' );
	}
	$size = filesize( $path );
	$hash = $size && $size <= Fonts::MAX_FILE_BYTES ? hash_file( 'sha256', $path ) : false;
	if ( ! $hash ) {
		return failure( 'The original font is unreadable or exceeds the conversion limit.' );
	}
	if ( isset( $input['sha256'] ) && ( ! is_string( $input['sha256'] ) || ! hash_equals( $hash, $input['sha256'] ) ) ) {
		return failure( 'The downloaded font no longer matches the library file. Refresh the library and try again.' );
	}

	$file = $files['face_0'] ?? [];
	if ( ! is_string( $file['name'] ?? null ) || strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'woff2' ) {
		return failure( 'The browser did not provide a WOFF2 conversion.' );
	}
	$upload = upload_file( $file );
	if ( is_wp_error( $upload ) ) {
		return $upload;
	}
	$candidate = $upload['attachmentId'];
	$touched = [ $source ];

	$result = font_transaction( static function () use ( $source, $path, $hash, $format, $candidate, &$touched ) {
		wp_cache_delete( $source, 'post_meta' );
		if ( get_post_type( $source ) !== 'attachment' || get_post_status( $source ) === 'trash'
			|| get_attached_file( $source ) !== $path || ! is_readable( $path ) || hash_file( 'sha256', $path ) !== $hash ) {
			return failure( 'The source file changed during conversion. Select it again and retry.' );
		}

		$stored = (array) get_post_meta( $source, CONVERSION_META, true );
		$target = (int) ( $stored['id'] ?? 0 );
		$info = $target && ( $stored['sha256'] ?? '' ) === $hash && get_post_status( $target ) !== 'trash'
			? file_info( $target ) : null;
		$replacement = $info && $info['format'] === 'woff2' && $info['bytes'] > 0 ? $target : $candidate;

		$ids = get_posts( [
			'post_type' => BRICKS_DB_CUSTOM_FONTS, 'post_status' => [ 'publish', 'trash' ],
			'posts_per_page' => -1, 'fields' => 'ids', 'cache_results' => false,
		] );
		$changes = [];
		$used = false;
		foreach ( $ids as $id ) {
			wp_cache_delete( $id, 'post_meta' );
			$old = get_post_meta( $id, BRICKS_DB_CUSTOM_FONT_FACES, true );
			if ( ! is_array( $old ) ) {
				continue;
			}

			$faces = $old;
			foreach ( $faces as &$face ) {
				if ( ! is_array( $face ) ) {
					continue;
				}
				if ( isset( $face[0] ) && is_array( $face[0] ) ) {
					foreach ( $face as &$subset ) {
						if ( is_array( $subset ) ) {
							replace_font_source( $subset, $format, $source, $replacement, $used );
						}
					}
					unset( $subset );
				} else {
					replace_font_source( $face, $format, $source, $replacement, $used );
				}
			}
			unset( $face );

			if ( $faces !== $old ) {
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return failure( 'You need permission to edit every family using this file.' );
				}
				$changes[ $id ] = $faces;
			}
		}

		if ( ! $changes && $used ) {
			return [ 'status' => 'skipped', 'message' => 'The faces using this file already have a WOFF2 source.' ];
		}
		if ( ! $changes && $replacement !== $candidate ) {
			return [ 'status' => 'skipped', 'message' => 'A WOFF2 copy already exists.' ];
		}

		foreach ( $changes as $id => $faces ) {
			$touched[] = $id;
			if ( ! update_post_meta( $id, BRICKS_DB_CUSTOM_FONT_FACES, wp_slash( $faces ) ) ) {
				return failure( 'The font references could not be updated.' );
			}
			if ( class_exists( Custom_Font_Store::class ) && Custom_Font_Store::is_active() ) {
				Custom_Font_Store::touch( $id );
			}
		}
		if ( $replacement === $candidate && ! update_post_meta( $source, CONVERSION_META, [ 'id' => $replacement, 'sha256' => $hash ] ) ) {
			return failure( 'The converted file could not be recorded.' );
		}
		if ( $changes ) {
			Custom_Fonts::refresh_font_face_rules();
		}
		return [ 'status' => 'converted', 'id' => $replacement, 'families' => count( $changes ) ];
	} );

	if ( is_wp_error( $result ) ) {
		foreach ( $touched as $id ) {
			wp_cache_delete( $id, 'post_meta' );
		}
	}
	if ( is_wp_error( $result ) || ( $result['id'] ?? 0 ) !== $candidate ) {
		wp_delete_attachment( $candidate, true );
	}
	return $result;
}

function replace_font_source( array &$subset, string $format, int $source, int $replacement, bool &$used ): void {
	if ( (string) ( $subset[ $format ] ?? '' ) !== (string) $source ) {
		return;
	}
	$used = true;
	if ( ! empty( $subset['woff2'] ) ) {
		return;
	}
	unset( $subset[ $format ] );
	$subset['woff2'] = $replacement;
}
