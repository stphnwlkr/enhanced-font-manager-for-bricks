<?php

namespace BricksVariableFonts;

use Bricks\Custom_Fonts;

defined( 'ABSPATH' ) || exit;

function clean_settings( array $value ): array {
	$var = is_string( $value['variable'] ?? null ) ? trim( $value['variable'] ) : '';
	$stack = [];
	if ( is_string( $value['fallback'] ?? null ) ) {
		foreach ( array_slice( explode( ',', $value['fallback'] ), 0, 8 ) as $part ) {
			$part = trim( $part, " \t\n\r\0\x0B\"'" );
			if ( preg_match( '/^[\p{L}\p{N} _-]{1,80}$/uD', $part ) ) {
				$stack[] = str_contains( $part, ' ' ) ? css_string( $part ) : $part;
			}
		}
	}
	$metrics = [];
	$input = (array) ( $value['metrics'] ?? [] );
	if ( is_string( $input['local'] ?? null ) && isset( LOCAL_FONTS[ $input['local'] ] ) ) {
		$metrics['local'] = $input['local'];
		foreach ( [ 'size' => [ 25, 400 ], 'ascent' => [ 1, 400 ], 'descent' => [ 0, 400 ], 'gap' => [ 0, 400 ] ] as $key => [ $min, $max ] ) {
			$number = is_numeric( $input[ $key ] ?? null ) ? (float) $input[ $key ] : -1;
			if ( $number < $min || $number > $max ) {
				$metrics = [];
				break;
			}
			$metrics[ $key ] = round( $number, 3 );
		}
	}
	return [
		'enabled' => (bool) ( $value['enabled'] ?? true ),
		'preload' => ! empty( $value['preload'] ),
		'display' => in_array( $value['display'] ?? '', [ 'swap', 'optional', 'fallback', 'block', 'auto' ], true ) ? $value['display'] : 'swap',
		'variable' => preg_match( '/^--[a-z][a-z0-9_-]{0,75}$/D', $var ) && ! in_array( $var, [ '--heading-font-family', '--text-font-family' ], true ) ? $var : '',
		'fallback' => implode( ', ', $stack ) ?: 'system-ui, sans-serif',
		'roles' => array_values( array_intersect( array_keys( ROLES ), array_filter( (array) ( $value['roles'] ?? [] ), 'is_string' ) ) ),
		'metrics' => $metrics,
	];
}

function settings_for( int $id ): array {
	return clean_settings( (array) get_post_meta( $id, META, true ) );
}

function css_string( string $value ): string {
	return '"' . str_replace( [ '\\', '"', '<', '>' ], [ '\\\\', '\\"', '\\3C ', '\\3E ' ], preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) ) . '"';
}

function variable_name( \WP_Post $post, array $settings ): string {
	$slug = sanitize_title( $post->post_title );
	return $settings['variable'] ?: '--font-' . ( preg_match( '/^[a-z0-9-]+$/D', $slug ) ? $slug : $post->ID );
}

function family_stack( \WP_Post $post, array $settings ): string {
	$family = html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' );
	return css_string( $family ) . ( $settings['metrics'] ? ', ' . css_string( "$family fallback" ) : '' ) . ', ' . $settings['fallback'];
}

function transform_faces( string $base, array $settings ): string {
	if ( ! $settings['enabled'] ) {
		return '';
	}
	return str_replace( ';font-display:swap;src:', ';font-display:' . $settings['display'] . ';src:', $base );
}

function family_extra_css( \WP_Post $post, array $settings ): string {
	if ( ! $settings['enabled'] ) {
		return '';
	}
	$family = html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' );
	$metrics = $settings['metrics'];
	$css = '';
	if ( $metrics ) {
		$locals = array_map( static fn( $name ) => 'local(' . css_string( $name ) . ')', LOCAL_FONTS[ $metrics['local'] ] );
		$css = '@font-face{font-family:' . css_string( "$family fallback" ) . ';src:' . implode( ',', $locals )
			. ";size-adjust:{$metrics['size']}%;ascent-override:{$metrics['ascent']}%;descent-override:{$metrics['descent']}%;line-gap-override:{$metrics['gap']}%;}";
	}
	return $css . ':root{' . variable_name( $post, $settings ) . ':' . family_stack( $post, $settings ) . ';}';
}

function active_families(): array {
	$catalog = Custom_Fonts::get_custom_fonts();
	$rows = [];
	foreach ( $catalog as $key => $font ) {
		$id = (int) substr( $key, strlen( 'custom_font_' ) );
		$post = get_post( $id );
		if ( ! $post || ! $font['fontFaces'] ) {
			continue;
		}
		$rows[ $key ] = [ $post, $font, settings_for( $id ) ];
	}
	return $rows;
}

function role_stacks( array $rows ): array {
	$roles = array_fill_keys( array_keys( ROLES ), '' );
	foreach ( $rows as [ $post, $font, $settings ] ) {
		if ( $settings['enabled'] ) {
			foreach ( $settings['roles'] as $role ) {
				$roles[ $role ] = family_stack( $post, $settings );
			}
		}
	}
	return $roles;
}

function output_css( array $rows ): string {
	$css = '';
	foreach ( $rows as [ $post, $font, $settings ] ) {
		$css .= family_extra_css( $post, $settings );
	}
	if ( class_exists( '\Automatic_CSS\API' ) ) {
		return $css;
	}
	foreach ( role_stacks( $rows ) as $role => $stack ) {
		if ( $stack !== '' ) {
			$css .= ":root{--{$role}-font-family:{$stack};}" . ROLES[ $role ] . "{font-family:var(--{$role}-font-family);}";
		}
	}
	return $css;
}

// Modify only the CSS read by Bricks' enqueue callback. The native option stays byte-for-byte intact.
add_action( 'init', static function () {
	if ( ! defined( 'BRICKS_DB_CUSTOM_FONT_FACE_RULES' ) ) {
		return;
	}
	add_filter( 'option_' . BRICKS_DB_CUSTOM_FONT_FACE_RULES, static function ( $css ) {
		if ( ! doing_action( 'wp_enqueue_scripts' ) && ! doing_action( 'admin_enqueue_scripts' ) ) {
			return $css;
		}
		foreach ( active_families() as [ $post, $font, $settings ] ) {
			$base = $font['fontFaces'];
			if ( ! $settings['enabled'] || 'swap' !== $settings['display'] ) {
				$css = str_replace( $base, transform_faces( $base, $settings ), $css );
			}
		}
		return $css;
	} );
} );

add_action( 'wp_enqueue_scripts', static function () {
	if ( ! is_callable( [ Custom_Fonts::class, 'get_custom_fonts' ] ) ) {
		return;
	}
	$css = output_css( active_families() );
	if ( $css ) {
		wp_register_style( 'bvf-tokens', false, [], VERSION );
		wp_enqueue_style( 'bvf-tokens' );
		wp_add_inline_style( 'bvf-tokens', $css );
	}
}, 40 );

add_action( 'wp_head', static function () {
	if ( ! is_callable( [ Custom_Fonts::class, 'get_custom_fonts' ] ) || ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) ) {
		return;
	}
	$urls = [];
	foreach ( active_families() as [ $post, $font, $settings ] ) {
		if ( ! $settings['enabled'] || ! $settings['preload'] ) {
			continue;
		}
		preg_match_all( '/@font-face\{([^}]+)\}/', $font['fontFaces'], $matches );
		$ranked = [];
		foreach ( $matches[1] as $rule ) {
			if ( ! preg_match( '/src:url\(([^)]+)\) format\("([^"]+)"\)/', $rule, $src ) ) {
				continue;
			}
			preg_match( '/font-weight:([\d.]+)(?: ([\d.]+))?/', $rule, $weight );
			$min = (float) ( $weight[1] ?? 400 );
			$max = (float) ( $weight[2] ?? $min );
			$cost = max( $min - 400, 400 - $max, 0 ) + ( preg_match( '/font-style:(?:italic|oblique)/', $rule ) ? 1000 : 0 );
			$ranked[] = [ $cost, $src[1], $src[2] ];
		}
		usort( $ranked, static fn( $a, $b ) => $a[0] <=> $b[0] );
		if ( $ranked && count( $urls ) < 4 ) {
			$urls[ $ranked[0][1] ] = $ranked[0][2];
		}
	}
	foreach ( $urls as $url => $format ) {
		$type = [ 'truetype' => 'ttf', 'opentype' => 'otf' ][ $format ] ?? $format;
		printf( '<link rel="preload" as="font" href="%s" type="font/%s" crossorigin>' . "\n", esc_url( $url ), esc_attr( $type ) );
	}
}, 2 );

function sync_acss(): void {
	if ( ! class_exists( '\Automatic_CSS\API' ) ) {
		return;
	}
	try {
		$prior = (array) get_option( 'bvf_acss_roles', [] );
		$changes = [];
		foreach ( role_stacks( active_families() ) as $role => $value ) {
			$key = "$role-font-family";
			$old = $prior[ $role ] ?? '';
			if ( $old === $value ) {
				continue;
			}
			if ( $value !== '' || \Automatic_CSS\API::get_setting( $key ) === $old ) {
				$changes[ $key ] = $value;
			}
			$prior[ $role ] = $value;
		}
		if ( $changes ) {
			\Automatic_CSS\API::update_settings( $changes );
			update_option( 'bvf_acss_roles', $prior, false );
		}
	} catch ( \Throwable $exception ) {
		// A failed ACSS rebuild must not undo an already committed Bricks family.
	}
}

function blocking_google(): bool {
	return ! is_admin() && ( ! function_exists( 'bricks_is_builder' ) || ! bricks_is_builder() )
		&& (bool) get_option( 'bvf_block_google', false );
}

function google_fonts_host( $url ): bool {
	return in_array( strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) ), [
		'fonts.googleapis.com', 'fonts.gstatic.com',
	], true );
}

add_filter( 'bricks/assets/load_webfonts', static fn( $load ) => $load && ! blocking_google() );
add_filter( 'style_loader_tag', static function ( $tag, $handle, $href ) {
	return blocking_google() && google_fonts_host( $href ) ? '' : $tag;
}, 10, 3 );
add_filter( 'wp_resource_hints', static function ( $hints ) {
	if ( ! blocking_google() ) {
		return $hints;
	}
	return array_values( array_filter( $hints, static fn( $hint ) => ! google_fonts_host( is_array( $hint ) ? ( $hint['href'] ?? '' ) : $hint ) ) );
} );
