<?php

namespace BricksVariableFonts;

defined( 'ABSPATH' ) || exit;

const UPDATE_REPOSITORY = 'https://github.com/stphnwlkr/enhanced-font-manager-for-bricks';

function release_metadata(): ?array {
	$response = wp_safe_remote_get( UPDATE_REPOSITORY . '/releases/latest/download/update.json', [
		'timeout' => 5,
		'limit_response_size' => 32768,
		'headers' => [ 'Accept' => 'application/json' ],
	] );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$release = json_decode( wp_remote_retrieve_body( $response ), true );
	$version = $release['version'] ?? null;
	if ( ! is_string( $version ) || ! preg_match( '/^\d+\.\d+\.\d+$/D', $version )
		|| ( $release['slug'] ?? null ) !== SLUG ) {
		return null;
	}

	$release_url = UPDATE_REPOSITORY . '/releases/tag/v' . $version;
	$package = UPDATE_REPOSITORY . '/releases/download/v' . $version . '/' . SLUG . '.zip';
	if ( ( $release['download_url'] ?? null ) !== $package ) {
		return null;
	}
	foreach ( [ 'requires', 'tested', 'requires_php' ] as $key ) {
		if ( ! is_string( $release[ $key ] ?? null ) || ! preg_match( '/^\d+(?:\.\d+){1,2}$/D', $release[ $key ] ) ) {
			return null;
		}
	}

	return [
		'slug' => SLUG,
		'version' => $version,
		'url' => $release_url,
		'package' => $package,
		'requires' => $release['requires'],
		'tested' => $release['tested'],
		'requires_php' => $release['requires_php'],
		'changelog' => is_string( $release['sections']['changelog'] ?? null ) ? wp_kses_post( $release['sections']['changelog'] ) : '',
	];
}

add_filter( 'update_plugins_github.com', static function ( $update, array $headers, string $plugin_file ) {
	if ( $plugin_file !== plugin_basename( __DIR__ . '/bricks-variable-fonts.php' )
		|| ( $headers['UpdateURI'] ?? '' ) !== UPDATE_REPOSITORY ) {
		return $update;
	}
	$release = release_metadata();
	if ( null === $release ) {
		return $update;
	}
	unset( $release['changelog'] );
	return $release;
}, 10, 3 );

add_filter( 'plugins_api', static function ( $result, string $action, $args ) {
	if ( false !== $result || 'plugin_information' !== $action || ( $args->slug ?? '' ) !== SLUG ) {
		return $result;
	}
	$release = release_metadata();
	if ( null === $release ) {
		return $result;
	}
	return (object) [
		'name' => NAME,
		'slug' => SLUG,
		'version' => $release['version'],
		'homepage' => UPDATE_REPOSITORY,
		'download_link' => $release['package'],
		'requires' => $release['requires'],
		'tested' => $release['tested'],
		'requires_php' => $release['requires_php'],
		'sections' => [
			'description' => 'Manage local and variable fonts, preview type, and inspect the exact CSS Bricks generates.',
			'changelog' => $release['changelog'],
		],
	];
}, 10, 3 );
