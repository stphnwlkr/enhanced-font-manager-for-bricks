<?php

namespace BricksVariableFonts;

use Bricks\Abilities\Fonts;
use Bricks\Custom_Font_Store;
use Bricks\Custom_Fonts;

defined( 'ABSPATH' ) || exit;

function failure( string $message ): \WP_Error {
	return new \WP_Error( 'bvf_error', $message );
}

function data(): array {
	$items = get_posts( [
		'post_type' => BRICKS_DB_CUSTOM_FONTS,
		'post_status' => [ 'publish', 'trash' ],
		'posts_per_page' => -1,
		'orderby' => 'title',
		'order' => 'ASC',
	] );
	$families = [];
	$files = [];
	foreach ( $items as $post ) {
		$id = $post->ID;
		$map = (array) get_post_meta( $id, BRICKS_DB_CUSTOM_FONT_FACES, true );
		$rows = [];
		foreach ( $map as $variant => $values ) {
			if ( ! is_array( $values ) || ! preg_match( '/^(\d+(?:\.\d+)?)(normal|italic|oblique)?$/D', (string) $variant, $parts ) ) {
				continue;
			}
			foreach ( isset( $values[0] ) ? $values : [ $values ] as $subset ) {
				$sources = array_intersect_key( (array) $subset, array_flip( FORMATS ) );
				$weight = explode( ' ', $subset['font-weight-range'] ?? $parts[1] );
				$rows[] = [
					'min' => $weight[0],
					'max' => $weight[1] ?? $weight[0],
					'style' => $parts[2] ?? 'normal',
					'unicode' => $subset['unicode-range'] ?? '',
					'sources' => (object) $sources,
				];
				foreach ( $sources as $source ) {
					if ( ! isset( $files[ $source ] ) ) {
						$files[ $source ] = file_info( $source );
					}
					if ( $files[ $source ] ) {
						$files[ $source ]['families'][ $id ] = get_the_title( $id );
					}
				}
			}
		}
		foreach ( (array) get_post_meta( $id, '_bricks_google_font_files', true ) as $source ) {
			if ( is_string( $source ) && ! isset( $files[ $source ] ) ) {
				$files[ $source ] = file_info( $source );
			}
		}
		$css = $post->post_status === 'publish' ? (string) Custom_Fonts::generate_font_face_rules( $id ) : '';
		$settings = settings_for( $id );
		$families[] = [
			'id' => $id,
			'name' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'status' => $post->post_status,
			'revision' => revision( $post ),
			'faces' => $rows,
			'settings' => $settings,
			'variableName' => variable_name( $post, $settings ),
			'css' => $css,
			'served' => $post->post_status === 'publish' ? transform_faces( $css, $settings ) . family_extra_css( $post, $settings ) : '',
		];
	}
	$media = get_posts( [
		'post_type' => 'attachment',
		'post_status' => [ 'inherit', 'private' ],
		'post_mime_type' => array_values( array_unique( array_merge( ...array_values( Fonts::ALLOWED_MIMES ) ) ) ),
		'posts_per_page' => -1,
		'fields' => 'ids',
		'no_found_rows' => true,
	] );
	foreach ( $media as $id ) {
		if ( ! isset( $files[ $id ] ) ) {
			$files[ $id ] = file_info( $id );
		}
	}
	return [
		'families' => $families,
		'files' => (object) array_filter( $files ),
		'acss' => class_exists( '\Automatic_CSS\API' ),
		'blockGoogle' => (bool) get_option( 'bvf_block_google', false ),
		'manageSettings' => current_user_can( 'manage_options' ),
		'googleDisabled' => google_fonts_disabled(),
		'css' => implode( "\n", array_column( $families, 'css' ) ),
		'served' => implode( "\n", array_map(
			static fn( $family ) => transform_faces( $family['css'], $family['settings'] ),
			$families
		) ) . output_css( active_families() ),
	];
}

function file_info( $source ): ?array {
	if ( is_numeric( $source ) && get_post_type( (int) $source ) !== 'attachment' ) {
		return null;
	}
	$path = method_exists( Custom_Fonts::class, 'get_font_file_path' )
		? Custom_Fonts::get_font_file_path( $source ) : ( is_numeric( $source ) ? get_attached_file( (int) $source ) : '' );
	$url = method_exists( Custom_Fonts::class, 'get_font_file_url' )
		? Custom_Fonts::get_font_file_url( $source ) : ( is_numeric( $source ) ? wp_get_attachment_url( (int) $source ) : '' );
	$format = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );
	if ( ! $path || ! $url || ! in_array( $format, FORMATS, true ) ) {
		return null;
	}
	return [
		'id' => $source, 'name' => basename( $path ), 'url' => $url, 'format' => $format,
		'bytes' => is_file( $path ) ? (int) filesize( $path ) : 0, 'families' => [],
	];
}

function revision( \WP_Post $post ): string {
	return hash( 'sha256', serialize( [ $post->post_title, $post->post_status, get_post_meta( $post->ID, BRICKS_DB_CUSTOM_FONT_FACES, true ), get_post_meta( $post->ID, META, true ) ] ) );
}

function dispatch(): void {
	if ( ! readable() || ! check_ajax_referer( 'bvf_library', 'nonce', false ) ) {
		wp_send_json_error( 'Access to the font manager has expired.', 403 );
	}
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decode JSON first. Each operation validates the fields it consumes.
	$payload = isset( $_POST['payload'] ) && is_string( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
	$input = (array) json_decode( $payload, true );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Uploads are verified and the filename sanitized in upload_file().
	$uploads = $_FILES;
	$op = $input['op'] ?? '';
	if ( $op === 'read' ) {
		$library = data();
		if ( ! empty( $input['native'] ) ) {
			$library['nativeFonts'] = native_font_catalog();
		}
		wp_send_json_success( $library );
	}
	if ( $op === 'settings' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Site settings require administrator access.', 403 );
		}
		update_option( 'bvf_block_google', ! empty( $input['blockGoogle'] ), false );
		wp_send_json_success( true );
	}
	if ( $op === 'upload-files' ) {
		send( upload_loose_files( $uploads ) );
	}
	if ( $op === 'convert-file' ) {
		send( convert_saved_file( $input, $uploads ) );
	}
	if ( $op === 'google-list' ) {
		$disabled = google_fonts_disabled();
		send( $disabled ? [ 'disabled' => true ] : google_catalog() );
	}
	if ( $op === 'save' || $op === 'google-save' ) {
		send( $op === 'save' ? save_form( $input, $uploads ) : google_save( $input ) );
	}
	if ( $op === 'delete-files' ) {
		send( delete_font_files( $input ) );
	}
	if ( in_array( $op, [ 'trash', 'restore' ], true ) ) {
		send( change_status( $op, $input ) );
	}
	wp_send_json_error( 'Unknown font action.', 400 );
}
add_action( 'wp_ajax_bvf_library', __NAMESPACE__ . '\\dispatch' );

// Match the same editor records and ownership baseline Bricks sends on its initial load.
function native_font_catalog(): ?array {
	if ( ! is_callable( [ Custom_Font_Store::class, 'read' ] )
		|| ! is_callable( [ \Bricks\Builder_Save_Coordinator::class, 'builder_ownership' ] ) ) {
		return null;
	}
	$authority = Custom_Font_Store::read();
	if ( is_wp_error( $authority ) ) {
		return null;
	}
	$items = Custom_Font_Store::with_css( $authority['values']['active'] );
	$ownership = \Bricks\Builder_Save_Coordinator::builder_ownership( [ 'customFonts' => $items ] );
	return [ 'items' => (object) $items, 'ownership' => $ownership['customFonts'] ?? null ];
}

function send( $result ): void {
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message(), 400 );
	}
	wp_send_json_success( $result );
}

function upload_file( $file ) {
	if ( true !== Fonts::upload_permission() || ! is_array( $file ) || ( $file['error'] ?? -1 ) !== UPLOAD_ERR_OK
		|| ! is_string( $file['name'] ?? null ) || ! is_string( $file['tmp_name'] ?? null )
		|| ! is_int( $file['size'] ?? null ) || $file['size'] < 1 || $file['size'] > Fonts::MAX_FILE_BYTES
		|| ! is_uploaded_file( $file['tmp_name'] ) ) {
		return failure( 'The font file exceeds the upload limit or could not be uploaded.' );
	}
	$bytes = file_get_contents( $file['tmp_name'] );
	return $bytes ? Fonts::upload_font_file( [
		'filename' => sanitize_file_name( $file['name'] ),
		'content' => base64_encode( $bytes ),
	] ) : failure( 'Could not read the font file.' );
}

function upload_loose_files( array $files ) {
	if ( ! $files || count( $files ) > 20 ) {
		return failure( 'Choose up to 20 font files.' );
	}
	$added = [];
	foreach ( $files as $file ) {
		$entry = upload_file( $file );
		if ( is_wp_error( $entry ) ) {
			foreach ( $added as $attachment ) {
				wp_delete_attachment( $attachment, true );
			}
			return $entry;
		}
		$added[] = $entry['attachmentId'];
	}
	return $added;
}

function save_form( array $input, array $files ) {
	$rows = $input['faces'] ?? [];
	if ( ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) > 160 ) {
		return failure( 'Invalid font faces.' );
	}
	$added = [];
	$map = [];
	$id = absint( $input['id'] ?? 0 );
	try {
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return failure( 'Invalid font face.' );
			}
			$sources = is_array( $row['sources'] ?? null ) ? $row['sources'] : [];
			$uploads = [];
			if ( isset( $files[ 'face_' . $index ] ) ) {
				$uploads[] = $files[ 'face_' . $index ];
			}
			foreach ( FORMATS as $format ) {
				if ( isset( $files[ 'face_' . $index . '_' . $format ] ) ) {
					$uploads[] = $files[ 'face_' . $index . '_' . $format ];
				}
			}
			foreach ( $uploads as $file ) {
				$entry = upload_file( $file );
				if ( is_wp_error( $entry ) ) {
					return $entry;
				}
				$added[] = $entry['attachmentId'];
				$sources[ $entry['format'] ] = $entry['attachmentId'];
			}
			$variant = add_face( $map, $row, $sources, $id );
			if ( is_wp_error( $variant ) ) {
				return $variant;
			}
		}
		$result = persist_family( $input, $map );
		if ( ! is_wp_error( $result ) ) {
			$added = [];
		}
		return $result;
	} finally {
		foreach ( $added as $attachment ) {
			wp_delete_attachment( $attachment, true );
		}
	}
}

function add_face( array &$map, array $row, array $sources, int $id = 0 ) {
	$min = $row['min'] ?? '';
	$max = $row['max'] ?? '';
	$style = $row['style'] ?? '';
	$unicode = $row['unicode'] ?? '';
	if ( ! is_scalar( $min ) || ! is_scalar( $max ) || ! is_string( $unicode )
		|| ! in_array( $style, [ 'normal', 'italic', 'oblique' ], true ) || ! $sources ) {
		return failure( 'Each face needs a font file, weight and style.' );
	}
	$range = Custom_Fonts::sanitize_font_weight_range( "$min $max" );
	if ( ! $range ) {
		return failure( 'Use weights between 1 and 1000 in ascending order.' );
	}
	[ $min, $max ] = explode( ' ', $range );
	$canon = (float) $min <= 400 && (float) $max >= 400 ? '400' : $min;
	$key = $canon . ( $style === 'normal' ? '' : $style );
	$subset = [];
	foreach ( $sources as $format => $source ) {
		if ( ! in_array( $format, FORMATS, true ) || ( ! is_int( $source ) && ! is_string( $source ) ) || ( ! is_numeric( $source ) && ! str_starts_with( $source, 'bricks/fonts/google/' . $id . '/' ) ) ) {
			return failure( 'Invalid font file reference.' );
		}
		if ( is_numeric( $source ) && ! in_array( get_post_status( (int) $source ), [ 'private', 'inherit', 'publish' ], true ) ) {
			return failure( 'This file is no longer available.' );
		}
		$subset[ $format ] = $source;
	}
	if ( $min !== $max ) {
		$subset['font-weight-range'] = $range;
	}
	if ( $unicode !== '' ) {
		$subset['unicode-range'] = Custom_Fonts::sanitize_unicode_range( $unicode );
		if ( ! $subset['unicode-range'] ) {
			return failure( 'Check the Unicode range. For example, U+0000-00FF.' );
		}
	}
	$map[ $key ][] = $subset;
	return true;
}

function persist_family( array $input, array $map ) {
	$id = absint( $input['id'] ?? 0 );
	$name = is_string( $input['name'] ?? null ) ? trim( sanitize_text_field( $input['name'] ) ) : '';
	$settings = clean_settings( (array) ( $input['settings'] ?? [] ) );
	if ( ! $name || strlen( $name ) > 600 ) {
		return failure( 'Enter a font family name.' );
	}
	$map = Fonts::validate_font_faces( $map, $id );
	if ( is_wp_error( $map ) ) {
		return $map;
	}
	$callback = static function () use ( &$id, $name, $map, $settings, $input ) {
		$post = $id ? get_post( $id ) : null;
		if ( $id && ( ! current_user_can( 'edit_post', $id ) || ! $post || $post->post_type !== BRICKS_DB_CUSTOM_FONTS || $post->post_status !== 'publish'
			|| ! hash_equals( revision( $post ), (string) ( $input['revision'] ?? '' ) ) ) ) {
			return failure( 'The family changed. Reopen it and try again.' );
		}
		$duplicate = get_posts( [
			'post_type' => BRICKS_DB_CUSTOM_FONTS, 'title' => $name, 'posts_per_page' => 1,
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Exclude only the family being edited from this exact-title query.
			'post__not_in' => [ $id ], 'post_status' => [ 'publish', 'trash' ],
			'fields' => 'ids', 'cache_results' => false,
		] );
		if ( $duplicate ) {
			return failure( 'A family already uses that name. Check the library and trash.' );
		}
		$result = wp_insert_post( wp_slash( [
			'ID' => $id,
			'post_type' => BRICKS_DB_CUSTOM_FONTS, 'post_status' => 'publish', 'post_title' => $name,
			'meta_input' => [ BRICKS_DB_CUSTOM_FONT_FACES => $map, META => $settings ],
		] ), true );
		if ( is_wp_error( $result ) || ! $result ) {
			return failure( 'Could not save the font family.' );
		}
		$id = (int) $result;
		if ( class_exists( Custom_Font_Store::class ) && Custom_Font_Store::is_active() ) {
			Custom_Font_Store::touch( $id );
		}
		if ( get_post_meta( $id, BRICKS_DB_CUSTOM_FONT_FACES, true ) !== $map || get_post_meta( $id, META, true ) !== $settings ) {
			return failure( 'Could not verify the saved family.' );
		}
		if ( $settings['roles'] && $settings['enabled'] ) {
			foreach ( get_posts( [
				'post_type' => BRICKS_DB_CUSTOM_FONTS, 'posts_per_page' => -1,
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only other custom font families with enhanced settings can own a role.
				'post__not_in' => [ $id ], 'meta_key' => META, 'fields' => 'ids',
			] ) as $other_id ) {
				$other = settings_for( $other_id );
				$keep = array_values( array_diff( $other['roles'], $settings['roles'] ) );
				if ( $keep !== $other['roles'] ) {
					$other['roles'] = $keep;
					update_post_meta( $other_id, META, wp_slash( $other ) );
					if ( class_exists( Custom_Font_Store::class ) && Custom_Font_Store::is_active() ) {
						Custom_Font_Store::touch( $other_id );
					}
				}
			}
		}
		Custom_Fonts::refresh_font_face_rules();
		return [ 'id' => $id ];
	};

	$result = font_transaction( $callback, $id );
	if ( ! is_wp_error( $result ) ) {
		sync_acss();
	}
	return $result;
}

function font_transaction( callable $callback, int &$id = 0 ) {
	if ( class_exists( Custom_Font_Store::class ) && ( ! defined( 'DB_ENGINE' ) || DB_ENGINE !== 'sqlite' ) ) {
		return Custom_Font_Store::transaction( $callback );
	}
	global $wpdb;
	$error = failure( 'The font changes could not be saved. Try again.' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WordPress has no transaction API for this fallback.
	if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
		return $error;
	}
	$committed = false;
	try {
		$result = $callback();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Commit the font and metadata changes together.
		$committed = $wpdb->query( 'COMMIT' ) !== false;
		return $committed ? $result : $error;
	} catch ( \Throwable $exception ) {
		return $error;
	} finally {
		if ( ! $committed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Revert partial font and metadata changes together.
			$wpdb->query( 'ROLLBACK' );
			if ( $id ) {
				wp_cache_delete( $id, 'posts' );
				wp_cache_delete( $id, 'post_meta' );
				wp_cache_set_posts_last_changed();
			}
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( BRICKS_DB_CUSTOM_FONT_FACE_RULES, 'options' );
		}
	}
}

function change_status( string $op, array $input ) {
	$id = absint( $input['id'] ?? 0 );
	$post = get_post( $id );
	if ( ! $id || ! $post || $post->post_type !== BRICKS_DB_CUSTOM_FONTS || ! current_user_can( 'delete_post', $id ) ) {
		return failure( 'You cannot change that font family.' );
	}
	$result = font_transaction( static function () use ( $op, $id, $post, $input ) {
		if ( ! hash_equals( revision( get_post( $id ) ), (string) ( $input['revision'] ?? '' ) ) ) {
			return failure( 'The family changed. Reload the library.' );
		}
		if ( $op === 'trash' && $post->post_status === 'publish' ) {
			// Keep the record and its files recoverable, even when WordPress trash is disabled.
			$changed = wp_update_post( [ 'ID' => $id, 'post_status' => 'trash' ], true );
		} elseif ( $op === 'restore' && $post->post_status === 'trash' ) {
			$changed = wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ], true );
		} else {
			return failure( 'The family already changed status.' );
		}
		if ( ! $changed || is_wp_error( $changed ) ) {
			return failure( 'The family status could not be saved.' );
		}
		if ( class_exists( Custom_Font_Store::class ) && Custom_Font_Store::is_active() ) {
			Custom_Font_Store::touch( $id );
		}
		Custom_Fonts::refresh_font_face_rules();
		return true;
	}, $id );
	if ( ! is_wp_error( $result ) ) {
		sync_acss();
	}
	return $result;
}
