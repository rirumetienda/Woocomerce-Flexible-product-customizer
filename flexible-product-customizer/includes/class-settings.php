<?php
/**
 * Plugin-wide settings and language selection.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Settings {
	const OPTION = 'fpcw_settings';
	const PAGE   = 'fpcw-settings';

	/** @return void */
	public static function register_language_filter() {
		add_filter( 'plugin_locale', array( __CLASS__, 'filter_plugin_locale' ), 10, 2 );
	}

	/** @param string $locale Current locale. @param string $domain Text domain. @return string */
	public static function filter_plugin_locale( $locale, $domain ) {
		if ( 'flexible-product-customizer' !== $domain ) {
			return $locale;
		}
		return self::language();
	}

	/** @return string */
	public static function language() {
		$settings = get_option( self::OPTION, array() );
		if ( is_array( $settings ) && in_array( isset( $settings['language'] ) ? $settings['language'] : '', array( 'es_ES', 'en_US' ), true ) ) {
			return $settings['language'];
		}
		$site_locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return 0 === strpos( strtolower( $site_locale ), 'es_' ) ? 'es_ES' : 'en_US';
	}

	/** @return void */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ), 60 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'all_admin_notices', array( $this, 'render_template_tabs' ), 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'upload_mimes', array( $this, 'allow_font_mimes' ) );
	}

	/** @return void */
	public function add_page() {
		add_submenu_page(
			null,
			__( 'Customizer settings', 'flexible-product-customizer' ),
			__( 'Customizer settings', 'flexible-product-customizer' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/** @return void */
	public function register_settings() {
		register_setting(
			'fpcw_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array( 'language' => self::language(), 'fonts' => array() ),
			)
		);
		add_settings_section(
			'fpcw_general_settings',
			__( 'General', 'flexible-product-customizer' ),
			array( $this, 'render_general_description' ),
			self::PAGE
		);
		add_settings_field(
			'fpcw_language',
			__( 'Plugin language', 'flexible-product-customizer' ),
			array( $this, 'render_language_field' ),
			self::PAGE,
			'fpcw_general_settings'
		);
		add_settings_field(
			'fpcw_fonts',
			__( 'Font library', 'flexible-product-customizer' ),
			array( $this, 'render_fonts_field' ),
			self::PAGE,
			'fpcw_general_settings'
		);
	}

	/** @return void */
	public function enqueue_assets() {
		$screen = get_current_screen();
		if ( $screen && ( Template_Manager::POST_TYPE === $screen->post_type || false !== strpos( $screen->id, self::PAGE ) ) ) {
			wp_enqueue_style( 'fpcw-admin', FPCW_URL . 'assets/css/admin.css', array(), FPCW_VERSION );
		}
		if ( ! $screen || false === strpos( $screen->id, self::PAGE ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'fpcw-admin-fonts', FPCW_URL . 'assets/js/admin-fonts.js', array(), FPCW_VERSION, true );
		wp_localize_script(
			'fpcw-admin-fonts',
			'FPCW_FONT_ADMIN',
			array(
				'fonts' => self::custom_fonts(),
				'i18n'  => array(
					'chooseFonts' => __( 'Select font files', 'flexible-product-customizer' ),
					'useFonts'    => __( 'Add selected fonts', 'flexible-product-customizer' ),
					'remove'      => __( 'Remove', 'flexible-product-customizer' ),
					'unsupported' => __( 'Use WOFF2, WOFF, TTF, or OTF font files.', 'flexible-product-customizer' ),
				),
			)
		);
	}

	/** @param mixed $value Submitted settings. @return array */
	public function sanitize( $value ) {
		$language = is_array( $value ) && isset( $value['language'] ) ? sanitize_text_field( $value['language'] ) : '';
		$raw_fonts = is_array( $value ) && isset( $value['fonts'] ) ? $value['fonts'] : array();
		if ( is_string( $raw_fonts ) ) {
			$raw_fonts = json_decode( wp_unslash( $raw_fonts ), true );
		}
		$fonts = array();
		foreach ( array_slice( is_array( $raw_fonts ) ? $raw_fonts : array(), 0, 30 ) as $font ) {
			$attachment_id = isset( $font['id'] ) ? absint( $font['id'] ) : 0;
			$family        = isset( $font['family'] ) ? self::sanitize_font_family( $font['family'] ) : '';
			$file          = $attachment_id ? get_attached_file( $attachment_id ) : '';
			$extension     = $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';
			if ( ! $attachment_id || ! $family || ! in_array( $extension, array( 'woff2', 'woff', 'ttf', 'otf' ), true ) ) {
				continue;
			}
			$fonts[ $family ] = array( 'id' => $attachment_id, 'family' => $family );
		}
		return array(
			'language' => in_array( $language, array( 'es_ES', 'en_US' ), true ) ? $language : self::language(),
			'fonts'    => array_values( $fonts ),
		);
	}

	/** @param array $mimes Allowed upload MIME types. @return array */
	public function allow_font_mimes( $mimes ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$mimes['woff2'] = 'font/woff2';
			$mimes['woff']  = 'font/woff';
			$mimes['ttf']   = 'font/ttf';
			$mimes['otf']   = 'font/otf';
		}
		return $mimes;
	}

	/** @return array<int,array<string,mixed>> */
	public static function custom_fonts() {
		$settings = get_option( self::OPTION, array() );
		$stored   = is_array( $settings ) && isset( $settings['fonts'] ) && is_array( $settings['fonts'] ) ? $settings['fonts'] : array();
		$fonts    = array();
		foreach ( $stored as $font ) {
			$id     = isset( $font['id'] ) ? absint( $font['id'] ) : 0;
			$family = isset( $font['family'] ) ? self::sanitize_font_family( $font['family'] ) : '';
			$url    = $id ? wp_get_attachment_url( $id ) : '';
			$file   = $id ? get_attached_file( $id ) : '';
			$format = $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';
			if ( $id && $family && $url && in_array( $format, array( 'woff2', 'woff', 'ttf', 'otf' ), true ) ) {
				$fonts[] = array( 'id' => $id, 'family' => $family, 'file' => wp_basename( $file ), 'url' => esc_url_raw( $url ), 'format' => $format, 'custom' => true );
			}
		}
		return $fonts;
	}

	/** @return array<int,array<string,mixed>> */
	public static function font_library() {
		$system = array_map(
			static function ( $family ) {
				return array( 'id' => 0, 'family' => $family, 'url' => '', 'format' => '', 'custom' => false );
			},
			array( 'Arial', 'Georgia', 'Trebuchet MS', 'Verdana', 'Courier New' )
		);
		$by_family = array();
		foreach ( array_merge( $system, self::custom_fonts() ) as $font ) {
			$by_family[ $font['family'] ] = $font;
		}
		return array_values( $by_family );
	}

	/** @param mixed $family Font family. @return string */
	private static function sanitize_font_family( $family ) {
		$family = sanitize_text_field( $family );
		$family = preg_replace( '/[^\p{L}\p{N} _-]/u', '', $family );
		return trim( (string) $family );
	}

	/** @return void */
	public function render_general_description() {
		echo '<p>' . esc_html__( 'Shared options for the template editor, product editor, cart, orders, and integrations.', 'flexible-product-customizer' ) . '</p>';
	}

	/** @return void */
	public function render_language_field() {
		$language = self::language();
		?>
		<select name="<?php echo esc_attr( self::OPTION ); ?>[language]" id="fpcw-language">
			<option value="es_ES" <?php selected( $language, 'es_ES' ); ?>>Español</option>
			<option value="en_US" <?php selected( $language, 'en_US' ); ?>>English</option>
		</select>
		<p class="description"><?php esc_html_e( 'Controls all plugin interfaces and customer-facing messages.', 'flexible-product-customizer' ); ?></p>
		<?php
	}

	/** @return void */
	public function render_fonts_field() {
		?>
		<div id="fpcw-font-library" class="fpcw-font-library"></div>
		<input type="hidden" id="fpcw-font-library-value" name="<?php echo esc_attr( self::OPTION ); ?>[fonts]" value="<?php echo esc_attr( wp_json_encode( self::custom_fonts(), JSON_UNESCAPED_SLASHES ) ); ?>" />
		<button type="button" class="button" id="fpcw-add-fonts"><?php esc_html_e( 'Upload fonts', 'flexible-product-customizer' ); ?></button>
		<p class="description"><?php esc_html_e( 'Upload WOFF2, WOFF, TTF, or OTF files. These fonts become selectable pills in every template and are preselected in new templates.', 'flexible-product-customizer' ); ?></p>
		<?php
	}

	/** @return void */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Customizer settings', 'flexible-product-customizer' ); ?></h1>
			<?php $this->render_tabs( 'settings' ); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'fpcw_settings_group' );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/** @return void */
	public function render_template_tabs() {
		$screen = get_current_screen();
		if ( ! $screen || Template_Manager::POST_TYPE !== $screen->post_type ) {
			return;
		}
		$this->render_tabs( 'templates' );
	}

	/** @param string $active Active tab. @return void */
	private function render_tabs( $active ) {
		$tabs = array(
			'templates' => array(
				'label' => __( 'Templates', 'flexible-product-customizer' ),
				'url'   => admin_url( 'edit.php?post_type=' . Template_Manager::POST_TYPE ),
			),
			'settings'  => array(
				'label' => __( 'Settings', 'flexible-product-customizer' ),
				'url'   => admin_url( 'admin.php?page=' . self::PAGE ),
			),
		);
		echo '<nav class="nav-tab-wrapper fpcw-module-tabs" aria-label="' . esc_attr__( 'Customizer navigation', 'flexible-product-customizer' ) . '">';
		foreach ( $tabs as $key => $tab ) {
			$class = 'nav-tab' . ( $active === $key ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $tab['url'] ) . '">' . esc_html( $tab['label'] ) . '</a>';
		}
		echo '</nav>';
	}
}
