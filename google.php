<?php

namespace BricksVariableFonts;

use Bricks\Abilities\Fonts;

defined( 'ABSPATH' ) || exit;

function google_fonts_disabled(): bool {
	return \Bricks\Helpers::google_fonts_disabled();
}

function google_catalog() {
	$cache = get_transient( 'bvf_google_catalog_v1' );
	if ( is_array( $cache ) && $cache ) {
		return $cache;
	}
	$reply = wp_safe_remote_get( 'https://fonts.google.com/metadata/fonts', [ 'timeout' => 15, 'limit_response_size' => 7 * MB_IN_BYTES ] );
	if ( is_wp_error( $reply ) || wp_remote_retrieve_response_code( $reply ) !== 200 ) {
		return failure( 'Google Fonts is temporarily unavailable.' );
	}
	$body = wp_remote_retrieve_body( $reply );
	$json = json_decode( str_starts_with( $body, ")]}'" ) ? substr( $body, 4 ) : $body, true );
	$fonts = [];
	foreach ( (array) ( $json['familyMetadataList'] ?? [] ) as $font ) {
		$name = $font['family'] ?? '';
		if ( ! $name ) {
			continue;
		}
		$axes = array_column( (array) ( $font['axes'] ?? [] ), null, 'tag' );
		$fonts[ $name ] = [
			'name' => $name,
			'category' => strtolower( $font['category'] ?? '' ),
			'cuts' => array_map( 'strval', array_keys( (array) ( $font['fonts'] ?? [] ) ) ),
			'subsets' => array_values( array_diff( (array) ( $font['subsets'] ?? [] ), [ 'menu' ] ) ),
			'weight' => isset( $axes['wght'] ) ? [ $axes['wght']['min'], $axes['wght']['max'] ] : [],
			'popularity' => (int) ( $font['popularity'] ?? PHP_INT_MAX ),
			'date' => $font['dateAdded'] ?? '',
		];
	}
	if ( ! $fonts ) {
		return failure( 'Google Fonts returned an empty catalogue.' );
	}
	set_transient( 'bvf_google_catalog_v1', $fonts, WEEK_IN_SECONDS );
	return $fonts;
}

function google_spec( array $font, array $cuts, bool $variable ): string {
	$tuples = [];
	foreach ( $cuts as $cut ) {
		if ( ! preg_match( '/^(\d+)(i)?$/D', $cut, $match ) ) {
			continue;
		}
		$style = isset( $match[2] ) ? '1' : '0';
		$tuples[] = $style . ',' . ( $variable && $font['weight'] ? implode( '..', $font['weight'] ) : $match[1] );
	}
	$tuples = array_unique( $tuples );
	sort( $tuples, SORT_NATURAL );
	if ( ! array_filter( $tuples, static fn( $v ) => str_starts_with( $v, '1,' ) ) ) {
		return ':wght@' . implode( ';', array_map( static fn( $v ) => substr( $v, 2 ), $tuples ) );
	}
	return ':ital,wght@' . implode( ';', $tuples );
}

function google_save( array $input ) {
	if ( google_fonts_disabled() ) {
		return failure( 'Google Fonts is disabled in Bricks settings.' );
	}
	if ( true !== Fonts::upload_permission() ) {
		return failure( 'You do not have permission to download font files.' );
	}
	$catalog = google_catalog();
	if ( is_wp_error( $catalog ) ) {
		return $catalog;
	}
	$name = $input['name'] ?? '';
	$font = is_string( $name ) ? ( $catalog[ $name ] ?? null ) : null;
	if ( ! $font ) {
		return failure( 'Choose a font from the Google Fonts catalogue.' );
	}

	$cuts = array_values( array_intersect( $font['cuts'], array_filter( (array) ( $input['cuts'] ?? [] ), 'is_string' ) ) );
	$subsets = array_values( array_intersect( $font['subsets'], array_filter( (array) ( $input['subsets'] ?? [] ), 'is_string' ) ) );
	if ( ! $cuts || ! $subsets ) {
		return failure( 'Select at least one style and language subset.' );
	}
	$spec = google_spec( $font, $cuts, ! empty( $input['variable'] ) );
	$reply = wp_safe_remote_get( 'https://fonts.googleapis.com/css2?family=' . rawurlencode( $name ) . $spec . '&display=swap', [
		'timeout' => 16, 'limit_response_size' => MB_IN_BYTES, 'redirection' => 0,
		'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
	] );
	if ( is_wp_error( $reply ) || wp_remote_retrieve_response_code( $reply ) !== 200 ) {
		return failure( 'Google could not provide those styles.' );
	}
	preg_match_all( '~(?:/\*\s*([\w-]+)\s*\*/\s*)?@font-face\s*\{([^}]*)\}~', wp_remote_retrieve_body( $reply ), $blocks, PREG_SET_ORDER );
	$wanted = array_values( array_filter( $blocks, static fn( $block ) => in_array( $block[1], $subsets, true ) ) );
	$unnamed = array_filter( $blocks, static fn( $block ) => $block[1] === '' );
	if ( ! $wanted && count( $unnamed ) === substr_count( $spec, ';' ) + 1 ) {
		$wanted = array_values( $unnamed );
	} elseif ( array_diff( $subsets, array_column( $wanted, 1 ) ) ) {
		return failure( 'Google does not provide all selected language subsets as named files.' );
	}
	if ( ! $wanted || count( $wanted ) > 48 ) {
		return failure( 'Choose a smaller selection of styles and subsets.' );
	}

	$added = [];
	$downloads = [];
	$map = [];
	$id = absint( $input['id'] ?? 0 );
	try {
		foreach ( $wanted as $index => [ , $subset, $rule ] ) {
			if ( ! preg_match( '~url\((https://fonts\.gstatic\.com/[A-Za-z0-9/_.-]+\.woff2)\)~', $rule, $url )
				|| ! preg_match( '/font-weight:\s*([\d.]+)(?:\s+([\d.]+))?\s*;/', $rule, $range ) ) {
				return failure( 'Google returned an unsupported font rule.' );
			}
			if ( ! isset( $downloads[ $url[1] ] ) ) {
				$file = wp_safe_remote_get( $url[1], [ 'timeout' => 18, 'limit_response_size' => Fonts::MAX_FILE_BYTES + 1, 'redirection' => 0 ] );
				if ( is_wp_error( $file ) || wp_remote_retrieve_response_code( $file ) !== 200 ) {
					return failure( 'A Google font file could not be downloaded.' );
				}
				$upload = Fonts::upload_font_file( [
					'filename' => sanitize_title( $name ) . '-' . sanitize_key( $subset ?: 'all' ) . '-' . ( $index + 1 ) . '.woff2',
					'content' => base64_encode( wp_remote_retrieve_body( $file ) ),
				] );
				if ( is_wp_error( $upload ) ) {
					return $upload;
				}
				$downloads[ $url[1] ] = $upload['attachmentId'];
				$added[] = $upload['attachmentId'];
			}
			preg_match( '/unicode-range:\s*([^;]+);/', $rule, $unicode );
			$row = [
				'min' => $range[1], 'max' => $range[2] ?? $range[1],
				'style' => preg_match( '/font-style:\s*italic;/', $rule ) ? 'italic' : 'normal',
				'unicode' => $unicode[1] ?? '',
			];
			$added_face = add_face( $map, $row, [ 'woff2' => $downloads[ $url[1] ] ], $id );
			if ( is_wp_error( $added_face ) ) {
				return $added_face;
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
