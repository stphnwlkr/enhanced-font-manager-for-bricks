<?php
/**
 * Plugin Name: Enhanced Font Manager for Bricks
 * Description: A font library, specimen preview and CSS inspector inside Bricks Builder.
 * Version: 0.3.0
 * Plugin URI: https://github.com/stphnwlkr/enhanced-font-manager-for-bricks
 * Update URI: https://github.com/stphnwlkr/enhanced-font-manager-for-bricks
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 */

namespace BricksVariableFonts;

use Bricks\Abilities\Fonts;
use Bricks\Custom_Fonts;

defined( 'ABSPATH' ) || exit;

const NAME = 'Enhanced Font Manager for Bricks';
const SLUG = 'bricks-variable-fonts';
const VERSION = '0.3.0';
const FORMATS = [ 'woff2', 'woff', 'ttf', 'otf', 'eot' ];
const META = '_bvf_family_settings';
const ROLES = [ 'heading' => 'h1,h2,h3,h4,h5,h6', 'text' => 'body' ];
const LOCAL_FONTS = [
	'sans' => [ 'Arial', 'Liberation Sans' ],
	'serif' => [ 'Times New Roman', 'Liberation Serif' ],
	'mono' => [ 'Courier New', 'Liberation Mono' ],
];

require __DIR__ . '/library.php';
require __DIR__ . '/font-css.php';
require __DIR__ . '/google.php';
require __DIR__ . '/conversion.php';
require __DIR__ . '/file-deletion.php';
require __DIR__ . '/updates.php';

function readable(): bool {
	return is_callable( [ Fonts::class, 'read_permission' ] )
		&& is_callable( [ Custom_Fonts::class, 'refresh_font_face_rules' ] )
		&& defined( 'BRICKS_VERSION' )
		&& version_compare( BRICKS_VERSION, '2.4.3', '>=' )
		&& true === Fonts::read_permission();
}

function register_page(): void {
	if ( ! readable() ) {
		return;
	}
	$hook = add_submenu_page( 'bricks', NAME, 'Enhanced Font Manager', 'edit_posts', SLUG, __NAMESPACE__ . '\\render' );
	add_action( 'load-' . $hook, __NAMESPACE__ . '\\enqueue' );
}
add_action( 'admin_menu', __NAMESPACE__ . '\\register_page', 30 );

function builder(): bool {
	return function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main();
}

function enqueue(): void {
	wp_enqueue_style( SLUG, plugins_url( 'assets/admin.css', __FILE__ ), [], VERSION );
	$dependencies = builder() ? [ 'bricks-builder' ] : [];
	wp_enqueue_script( SLUG, plugins_url( 'assets/admin.js', __FILE__ ), $dependencies, VERSION, true );
	add_filter( 'script_loader_tag', static function ( $tag, $handle ) {
		if ( SLUG !== $handle ) {
			return $tag;
		}
		$processor = new \WP_HTML_Tag_Processor( $tag );
		$processor->next_tag( 'SCRIPT' );
		$processor->set_attribute( 'type', 'module' );
		return $processor->get_updated_html();
	}, 10, 2 );
}

add_action( 'wp_enqueue_scripts', static function () {
	if ( builder() && readable() && wp_script_is( 'bricks-builder', 'enqueued' ) ) {
		enqueue();
		add_action( 'wp_footer', __NAMESPACE__ . '\\render', 5 );
	}
}, 30 );

function render(): void {
	if ( ! readable() ) {
		return;
	}
	$tag = builder() ? 'dialog' : 'div';
	?>
	<<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed tag names. ?> class="bvf" id="bvf-manager" aria-labelledby="bvf-title"
		data-builder="<?php echo builder() ? '1' : '0'; ?>"
		data-upload="<?php echo true === Fonts::upload_permission() ? '1' : '0'; ?>"
		data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'bvf_library' ) ); ?>"
		data-worker="<?php echo esc_url( plugins_url( 'assets/woff2/woff2-worker.js?ver=' . VERSION, __FILE__ ) ); ?>"
		data-max="<?php echo esc_attr( (string) min( Fonts::MAX_FILE_BYTES, wp_max_upload_size() ) ); ?>">
		<div class="bvf-chrome">
			<header class="bvf-header">
				<h1 id="bvf-title"><?php echo esc_html( NAME ); ?></h1>
				<?php if ( builder() ) : ?>
				<form method="dialog"><button type="submit" class="bvf-button">Back to builder</button></form>
				<?php endif; ?>
			</header>
			<nav class="bvf-tabs" aria-label="Font manager">
				<button type="button" data-view="library">Library</button>
				<button type="button" data-view="files">Files</button>
				<button type="button" data-view="upload">Upload</button>
				<button type="button" data-view="google">Google Fonts</button>
				<button type="button" data-view="transfer">Import / Export</button>
				<button type="button" data-view="settings">Settings</button>
			</nav>
			<div class="bvf-status" role="status"></div>
		</div>
		<div class="bvf-content"></div>
	</<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed tag names. ?>>
	<?php
}
