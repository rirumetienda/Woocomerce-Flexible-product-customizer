<?php
/**
 * Bundled blank product template library.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Template_Library {
	const TEMPLATE_META = '_fpcw_library_template';
	const ASSET_META    = '_fpcw_library_asset';
	const ASSET_DIR     = 'assets/demo/library/';

	/** @return void */
	public static function install() {
		$templates = new Template_Manager();
		$templates->register_post_type();
		$assets = self::install_assets();
		foreach ( self::definitions( $assets ) as $definition ) {
			self::install_template( $templates, $definition );
		}
	}

	/** @return array<string,int> */
	private static function install_assets() {
		$ids = array();
		foreach ( self::asset_manifest() as $key => $file ) {
			$ids[ $key ] = self::install_asset( $key, $file );
		}
		return $ids;
	}

	/** @return array<string,string> */
	private static function asset_manifest() {
		$manifest = array();
		foreach ( self::shirt_colors() as $color ) {
			foreach ( array( 'front', 'back', 'sleeve-left', 'sleeve-right' ) as $surface ) {
				$manifest[ 'tshirt-' . $color['id'] . '-' . $surface ] = 'tshirt-' . $color['id'] . '-' . $surface . '.png';
			}
		}
		foreach ( self::simple_colors( array( 'black', 'white' ) ) as $color ) {
			foreach ( array( 'front', 'back', 'sleeve-left', 'sleeve-right' ) as $surface ) {
				$manifest[ 'hoodie-' . $color['id'] . '-' . $surface ] = 'hoodie-' . $color['id'] . '-' . $surface . '.png';
			}
		}
		foreach ( self::simple_colors( array( 'black', 'white', 'red', 'blue', 'dark-green' ) ) as $color ) {
			foreach ( array( 'front', 'back', 'sleeve-left', 'sleeve-right' ) as $surface ) {
				$manifest[ 'sweatshirt-' . $color['id'] . '-' . $surface ] = 'sweatshirt-' . $color['id'] . '-' . $surface . '.png';
			}
		}
		foreach ( self::cap_variants() as $variant ) {
			foreach ( array( 'front', 'visor' ) as $surface ) {
				$manifest[ 'cap-' . $variant['id'] . '-' . $surface ] = 'cap-' . $variant['id'] . '-' . $surface . '.png';
			}
		}
		foreach ( array(
			'mug-white-wrap', 'mug-magic-wrap', 'mug-accent-black-wrap', 'mug-accent-red-wrap', 'mug-accent-yellow-wrap', 'mug-accent-blue-wrap', 'mug-black-window-wrap',
			'mousepad-square', 'mousepad-round', 'pins-set', 'poster-sulfite-12x18-vertical', 'poster-sulfite-12x18-horizontal', 'poster-sulfite-12x9-vertical', 'poster-sulfite-12x9-horizontal',
			'poster-metal-20x30-vertical', 'poster-metal-20x30-horizontal', 'poster-metal-40x60-vertical', 'poster-metal-40x60-horizontal', 'banner-40x80', 'tumbler-aluminum-wrap',
			'puzzle-200', 'notebook-university-front', 'notebook-university-back', 'notebook-half-letter-front', 'notebook-half-letter-back', 'notebook-five-subject-front', 'notebook-five-subject-back',
			'lanyard', 'glass-clock',
		) as $key ) {
			$manifest[ $key ] = $key . '.png';
		}
		return $manifest;
	}

	/** @return int */
	private static function install_asset( $key, $file ) {
		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::ASSET_META,
				'meta_value'     => $key,
				'no_found_rows'  => true,
			)
		);
		if ( $existing ) {
			return (int) $existing[0];
		}
		$source = FPCW_PATH . self::ASSET_DIR . $file;
		if ( ! is_readable( $source ) ) {
			return 0;
		}
		$contents = file_get_contents( $source );
		if ( false === $contents ) {
			return 0;
		}
		$upload = wp_upload_bits( 'fpcw-' . $file, null, $contents );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		$filetype = wp_check_filetype( $upload['file'], null );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'],
				'post_title'     => 'FPCW blank ' . $key,
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( is_wp_error( $attachment_id ) ) {
			return 0;
		}
		$metadata = array(
			'file'  => function_exists( '_wp_relative_upload_path' ) ? _wp_relative_upload_path( $upload['file'] ) : basename( $upload['file'] ),
			'sizes' => array(),
		);
		$image_size = @getimagesize( $upload['file'] );
		if ( is_array( $image_size ) ) {
			$metadata['width']  = (int) $image_size[0];
			$metadata['height'] = (int) $image_size[1];
		}
		wp_update_attachment_metadata( $attachment_id, $metadata );
		update_post_meta( $attachment_id, self::ASSET_META, $key );
		return (int) $attachment_id;
	}

	/** @return array<int,array<string,mixed>> */
	private static function definitions( array $assets ) {
		$definitions = array();

		$definitions[] = self::apparel_template( 'blank-t-shirts-v1', 'Blank: Camisetas', 'tshirt', self::shirt_colors(), self::shirt_surfaces(), $assets );
		$definitions[] = self::apparel_template( 'blank-hoodies-v1', 'Blank: Hoodies cerrados', 'hoodie', self::simple_colors( array( 'black', 'white' ) ), self::hoodie_surfaces(), $assets );
		$definitions[] = self::apparel_template( 'blank-sweatshirts-v1', 'Blank: Sudaderas', 'sweatshirt', self::simple_colors( array( 'black', 'white', 'red', 'blue', 'dark-green' ) ), self::hoodie_surfaces(), $assets );
		$definitions[] = self::apparel_template( 'blank-mesh-caps-v1', 'Blank: Gorras de malla', 'cap', self::cap_variants(), self::cap_surfaces(), $assets );

		$definitions[] = self::cylindrical_template( 'blank-white-mugs-v1', 'Blank: Tazas blancas', self::single_attribute( 'white', 'Blanco', '#ffffff' ), 'wrap', 'Area sublimable', 1200, 1200, array( 'x' => 250, 'y' => 260, 'width' => 610, 'height' => 690 ), array( 'width' => 2400, 'height' => 900 ), self::projection( array( 'x' => 250, 'y' => 260, 'width' => 610, 'height' => 690 ), 280 ), array( 'white' => 'mug-white-wrap' ), $assets, 3, 3 );
		$definitions[] = self::cylindrical_template( 'blank-magic-mugs-v1', 'Blank: Tazas magicas', self::single_attribute( 'magic', 'Magica', '#111111' ), 'wrap', 'Area sublimable', 1200, 1200, array( 'x' => 250, 'y' => 260, 'width' => 610, 'height' => 690 ), array( 'width' => 2400, 'height' => 900 ), self::projection( array( 'x' => 250, 'y' => 260, 'width' => 610, 'height' => 690 ), 280 ), array( 'magic' => 'mug-magic-wrap' ), $assets, 3, 3 );
		$definitions[] = self::cylindrical_template( 'blank-accent-mugs-v1', 'Blank: Tazas accent', self::simple_colors( array( 'black', 'red', 'yellow', 'blue' ) ), 'wrap', 'Area sublimable', 1200, 1200, array( 'x' => 250, 'y' => 260, 'width' => 610, 'height' => 690 ), array( 'width' => 2400, 'height' => 900 ), self::projection( array( 'x' => 250, 'y' => 260, 'width' => 610, 'height' => 690 ), 280 ), array( 'black' => 'mug-accent-black-wrap', 'red' => 'mug-accent-red-wrap', 'yellow' => 'mug-accent-yellow-wrap', 'blue' => 'mug-accent-blue-wrap' ), $assets, 3, 3 );
		$definitions[] = self::cylindrical_template( 'blank-black-window-mugs-v1', 'Blank: Tazas negras con ventana', self::single_attribute( 'black', 'Negro', '#111111' ), 'window', 'Ventana sublimable', 1200, 1200, array( 'x' => 300, 'y' => 330, 'width' => 500, 'height' => 470 ), array( 'width' => 1800, 'height' => 650 ), self::projection( array( 'x' => 300, 'y' => 330, 'width' => 500, 'height' => 470 ), 180 ), array( 'black' => 'mug-black-window-wrap' ), $assets, 3, 3 );

		$definitions[] = self::flat_template( 'blank-square-mousepad-v1', 'Blank: Mousepad cuadrado', self::default_attribute(), array( self::surface( 'main', 'Superficie cuadrada', 1200, 1200, array( 'x' => 230, 'y' => 230, 'width' => 740, 'height' => 740 ), 'rect', 3, 3 ) ), array( 'default' => array( 'main' => 'mousepad-square' ) ), $assets );
		$definitions[] = self::flat_template( 'blank-round-mousepad-v1', 'Blank: Mousepad redondo', self::default_attribute(), array( self::surface( 'main', 'Superficie redonda', 1200, 1200, array( 'x' => 220, 'y' => 220, 'width' => 760, 'height' => 760 ), 'circle', 3, 3 ) ), array( 'default' => array( 'main' => 'mousepad-round' ) ), $assets );
		$definitions[] = self::flat_template( 'blank-round-pins-set-v1', 'Blank: Broches redondos set de 5', self::default_attribute(), self::pin_surfaces(), array( 'default' => array( 'pin-1' => 'pins-set', 'pin-2' => 'pins-set', 'pin-3' => 'pins-set', 'pin-4' => 'pins-set', 'pin-5' => 'pins-set' ) ), $assets );

		$definitions[] = self::orientation_template( 'blank-sulfite-poster-12x18-v1', 'Blank: Poster papel sulfito 12 x 18 in', 'poster-sulfite-12x18', $assets, '#f7f3ec', 3, 3 );
		$definitions[] = self::orientation_template( 'blank-sulfite-poster-12x9-v1', 'Blank: Poster papel sulfito 12 x 9 in', 'poster-sulfite-12x9', $assets, '#f7f3ec', 3, 3 );
		$definitions[] = self::orientation_template( 'blank-metal-poster-20x30-v1', 'Blank: Poster metalico 20 x 30 cm', 'poster-metal-20x30', $assets, '#cfd5da', 3, 3 );
		$definitions[] = self::orientation_template( 'blank-metal-poster-40x60-v1', 'Blank: Poster metalico 40 x 60 cm', 'poster-metal-40x60', $assets, '#cfd5da', 3, 3 );

		$definitions[] = self::flat_template( 'blank-banner-40x80-v1', 'Blank: Banner 40 x 80 cm', self::default_attribute(), array( self::surface( 'main', 'Superficie rectangular vertical', 900, 1600, array( 'x' => 250, 'y' => 230, 'width' => 400, 'height' => 1040 ), 'rect', 3, 3 ) ), array( 'default' => array( 'main' => 'banner-40x80' ) ), $assets );
		$definitions[] = self::cylindrical_template( 'blank-aluminum-tumbler-v1', 'Blank: Termo de aluminio', self::single_attribute( 'aluminum', 'Aluminio', '#c7cbd0' ), 'wrap', 'Superficie cilindrica', 1200, 1600, array( 'x' => 360, 'y' => 190, 'width' => 480, 'height' => 1180 ), array( 'width' => 3200, 'height' => 1400 ), self::projection( array( 'x' => 360, 'y' => 190, 'width' => 480, 'height' => 1180 ), 360, self::four_views() ), array( 'aluminum' => 'tumbler-aluminum-wrap' ), $assets, 4, 4 );
		$definitions[] = self::flat_template( 'blank-puzzle-200-v1', 'Blank: Rompecabezas 200 piezas 11 x 17 in', self::default_attribute(), array( self::surface( 'main', 'Superficie rectangular', 1400, 1000, array( 'x' => 235, 'y' => 180, 'width' => 930, 'height' => 640 ), 'rect', 3, 3 ) ), array( 'default' => array( 'main' => 'puzzle-200' ) ), $assets );
		$definitions[] = self::notebook_template( $assets );
		$definitions[] = self::flat_template( 'blank-lanyard-v1', 'Blank: Lanyard 1 m x 3 cm', self::default_attribute(), array( self::surface( 'main', 'Tira completa', 1800, 500, array( 'x' => 150, 'y' => 195, 'width' => 1500, 'height' => 110 ), 'rect', 5, 3 ) ), array( 'default' => array( 'main' => 'lanyard' ) ), $assets );
		$definitions[] = self::flat_template( 'blank-round-glass-clock-v1', 'Blank: Reloj de vidrio redondo 30 cm', self::default_attribute(), array( self::surface( 'main', 'Superficie redonda', 1200, 1200, array( 'x' => 260, 'y' => 150, 'width' => 680, 'height' => 680 ), 'circle', 3, 3 ) ), array( 'default' => array( 'main' => 'glass-clock' ) ), $assets );

		return $definitions;
	}

	/** @return array<string,mixed> */
	private static function apparel_template( $slug, $title, $prefix, array $colors, array $surfaces, array $assets ) {
		$assignments = array();
		foreach ( $colors as $color ) {
			foreach ( $surfaces as $surface ) {
				$assignments[ $color['id'] ][ $surface['id'] ] = $prefix . '-' . $color['id'] . '-' . $surface['id'];
			}
		}
		return self::flat_template( $slug, $title, $colors, $surfaces, $assignments, $assets );
	}

	/** @return array<string,mixed> */
	private static function orientation_template( $slug, $title, $asset_prefix, array $assets, $hex, $max_images, $max_texts ) {
		$colors = array(
			self::color( 'vertical', 'Vertical', $hex ),
			self::color( 'horizontal', 'Horizontal', $hex ),
		);
		$surfaces = array(
			self::surface( 'vertical', 'Vertical', 1000, 1400, array( 'x' => 250, 'y' => 250, 'width' => 500, 'height' => 860 ), 'rect', $max_images, $max_texts ),
			self::surface( 'horizontal', 'Horizontal', 1400, 1000, array( 'x' => 250, 'y' => 245, 'width' => 900, 'height' => 510 ), 'rect', $max_images, $max_texts ),
		);
		return self::flat_template(
			$slug,
			$title,
			$colors,
			$surfaces,
			array(
				'vertical'   => array( 'vertical' => $asset_prefix . '-vertical' ),
				'horizontal' => array( 'horizontal' => $asset_prefix . '-horizontal' ),
			),
			$assets
		);
	}

	/** @return array<string,mixed> */
	private static function notebook_template( array $assets ) {
		$colors = array(
			self::color( 'university', 'Cuaderno universitario', '#ffffff' ),
			self::color( 'half-letter', 'Libreta media carta', '#ffffff' ),
			self::color( 'five-subject', 'Cuaderno de 5 materias', '#ffffff' ),
		);
		$surfaces = array(
			self::surface( 'university-front', 'Universitario portada', 1000, 1400, array( 'x' => 260, 'y' => 190, 'width' => 500, 'height' => 920 ), 'rect', 3, 3 ),
			self::surface( 'university-back', 'Universitario contraportada', 1000, 1400, array( 'x' => 260, 'y' => 190, 'width' => 500, 'height' => 920 ), 'rect', 3, 3 ),
			self::surface( 'half-letter-front', 'Media carta portada', 900, 1200, array( 'x' => 245, 'y' => 175, 'width' => 410, 'height' => 760 ), 'rect', 3, 3 ),
			self::surface( 'half-letter-back', 'Media carta contraportada', 900, 1200, array( 'x' => 245, 'y' => 175, 'width' => 410, 'height' => 760 ), 'rect', 3, 3 ),
			self::surface( 'five-subject-front', '5 materias portada', 1100, 1500, array( 'x' => 285, 'y' => 205, 'width' => 530, 'height' => 980 ), 'rect', 3, 3 ),
			self::surface( 'five-subject-back', '5 materias contraportada', 1100, 1500, array( 'x' => 285, 'y' => 205, 'width' => 530, 'height' => 980 ), 'rect', 3, 3 ),
		);
		return self::flat_template(
			'blank-notebooks-v1',
			'Blank: Cuadernos y libretas',
			$colors,
			$surfaces,
			array(
				'university'   => array( 'university-front' => 'notebook-university-front', 'university-back' => 'notebook-university-back' ),
				'half-letter'  => array( 'half-letter-front' => 'notebook-half-letter-front', 'half-letter-back' => 'notebook-half-letter-back' ),
				'five-subject' => array( 'five-subject-front' => 'notebook-five-subject-front', 'five-subject-back' => 'notebook-five-subject-back' ),
			),
			$assets
		);
	}

	/** @return array<string,mixed> */
	private static function flat_template( $slug, $title, array $colors, array $surfaces, array $asset_assignments, array $assets ) {
		foreach ( $colors as &$color ) {
			$surface_assignments = array();
			foreach ( $surfaces as $surface ) {
				$asset_key = isset( $asset_assignments[ $color['id'] ][ $surface['id'] ] ) ? $asset_assignments[ $color['id'] ][ $surface['id'] ] : '';
				$surface_assignments[ $surface['id'] ] = array(
					'enabled'  => '' !== $asset_key,
					'image_id' => $asset_key && isset( $assets[ $asset_key ] ) ? (int) $assets[ $asset_key ] : 0,
				);
			}
			$color['surfaces'] = $surface_assignments;
		}
		unset( $color );
		return self::definition( $slug, $title, 'flat', $colors, $surfaces );
	}

	/** @return array<string,mixed> */
	private static function cylindrical_template( $slug, $title, array $colors, $surface_id, $surface_label, $width, $height, array $workspace, array $print_area, array $projection, array $asset_assignments, array $assets, $max_images, $max_texts ) {
		$surfaces = array(
			self::surface( $surface_id, $surface_label, $width, $height, $workspace, 'rect', $max_images, $max_texts, $print_area, $projection ),
		);
		foreach ( $colors as &$color ) {
			$asset_key = isset( $asset_assignments[ $color['id'] ] ) ? $asset_assignments[ $color['id'] ] : '';
			$color['surfaces'] = array(
				$surface_id => array(
					'enabled'  => true,
					'image_id' => $asset_key && isset( $assets[ $asset_key ] ) ? (int) $assets[ $asset_key ] : 0,
				),
			);
		}
		unset( $color );
		return self::definition( $slug, $title, 'cylindrical', $colors, $surfaces );
	}

	/** @return array<string,mixed> */
	private static function definition( $slug, $title, $product_type, array $colors, array $surfaces ) {
		return array(
			'slug'   => $slug,
			'title'  => $title,
			'config' => array(
				'schema_version' => 6,
				'product_type'   => $product_type,
				'fonts'          => wp_list_pluck( Settings::font_library(), 'family' ),
				'colors'         => $colors,
				'surfaces'       => $surfaces,
			),
		);
	}

	/** @return array<string,mixed> */
	private static function surface( $id, $label, $width, $height, array $workspace, $shape = 'rect', $max_images = 2, $max_texts = 3, array $print_area = null, array $projection = null ) {
		return array(
			'id'                   => $id,
			'label'                => $label,
			'width'                => $width,
			'height'               => $height,
			'shape'                => $shape,
			'workspace'            => $workspace,
			'print_area'           => $print_area ? $print_area : array( 'width' => $width, 'height' => $height ),
			'base_image_transform' => array( 'x' => 0, 'y' => 0, 'width' => $width, 'height' => $height ),
			'projection'           => $projection ? $projection : self::projection( $workspace, 180 ),
			'allow_images'         => true,
			'allow_text'           => true,
			'max_images'           => $max_images,
			'max_texts'            => $max_texts,
		);
	}

	/** @return array<string,mixed> */
	private static function projection( array $frame, $wrap_angle = 180, array $views = null ) {
		return array(
			'wrap_angle'     => $wrap_angle,
			'top_scale'      => 100,
			'bottom_scale'   => 100,
			'shading'        => 55,
			'frame'          => $frame,
			'preview_views'  => $views ? $views : self::three_views(),
			'mask_image_id'  => 0,
			'overlay_image_id' => 0,
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function three_views() {
		return array(
			array( 'id' => 'front', 'label' => 'Frente', 'rotation' => 0, 'enabled' => true ),
			array( 'id' => 'left', 'label' => 'Lado izquierdo', 'rotation' => -60, 'enabled' => true ),
			array( 'id' => 'right', 'label' => 'Lado derecho', 'rotation' => 60, 'enabled' => true ),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function four_views() {
		return array(
			array( 'id' => 'front', 'label' => 'Frente', 'rotation' => 0, 'enabled' => true ),
			array( 'id' => 'right', 'label' => 'Derecha', 'rotation' => 90, 'enabled' => true ),
			array( 'id' => 'back', 'label' => 'Atras', 'rotation' => 180, 'enabled' => true ),
			array( 'id' => 'left', 'label' => 'Izquierda', 'rotation' => -90, 'enabled' => true ),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function shirt_surfaces() {
		return array(
			self::surface( 'front', 'Frente', 1200, 1200, array( 'x' => 390, 'y' => 250, 'width' => 420, 'height' => 520 ), 'rect', 2, 3 ),
			self::surface( 'back', 'Atras', 1200, 1200, array( 'x' => 390, 'y' => 245, 'width' => 420, 'height' => 540 ), 'rect', 2, 3 ),
			self::surface( 'sleeve-left', 'Parte de arriba manga izquierda', 1200, 1200, array( 'x' => 365, 'y' => 415, 'width' => 470, 'height' => 230 ), 'rect', 1, 2 ),
			self::surface( 'sleeve-right', 'Parte de arriba manga derecha', 1200, 1200, array( 'x' => 365, 'y' => 415, 'width' => 470, 'height' => 230 ), 'rect', 1, 2 ),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function hoodie_surfaces() {
		return array(
			self::surface( 'front', 'Frente', 1200, 1200, array( 'x' => 355, 'y' => 305, 'width' => 490, 'height' => 360 ), 'rect', 2, 3 ),
			self::surface( 'back', 'Atras', 1200, 1200, array( 'x' => 350, 'y' => 250, 'width' => 500, 'height' => 540 ), 'rect', 2, 3 ),
			self::surface( 'sleeve-left', 'Manga izquierda vertical', 1200, 1200, array( 'x' => 420, 'y' => 170, 'width' => 360, 'height' => 800 ), 'rect', 2, 3 ),
			self::surface( 'sleeve-right', 'Manga derecha vertical', 1200, 1200, array( 'x' => 420, 'y' => 170, 'width' => 360, 'height' => 800 ), 'rect', 2, 3 ),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function cap_surfaces() {
		return array(
			self::surface( 'front', 'Frente', 1200, 1200, array( 'x' => 385, 'y' => 390, 'width' => 430, 'height' => 220 ), 'rect', 2, 2 ),
			self::surface( 'visor', 'Solapa', 1200, 1200, array( 'x' => 320, 'y' => 665, 'width' => 560, 'height' => 150 ), 'rect', 1, 2 ),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function pin_surfaces() {
		$positions = array(
			'pin-1' => array( 240, 250 ),
			'pin-2' => array( 490, 225 ),
			'pin-3' => array( 740, 250 ),
			'pin-4' => array( 365, 600 ),
			'pin-5' => array( 615, 600 ),
		);
		$surfaces = array();
		foreach ( $positions as $id => $xy ) {
			$surfaces[] = self::surface( $id, strtoupper( str_replace( '-', ' ', $id ) ), 1200, 1200, array( 'x' => $xy[0], 'y' => $xy[1], 'width' => 220, 'height' => 220 ), 'circle', 5, 5 );
		}
		return $surfaces;
	}

	/** @return array<int,array<string,string>> */
	private static function default_attribute() {
		return self::single_attribute( 'default', 'Predeterminado', '#ffffff' );
	}

	/** @return array<int,array<string,string>> */
	private static function single_attribute( $id, $label, $hex ) {
		return array( self::color( $id, $label, $hex ) );
	}

	/** @return array<int,array<string,string>> */
	private static function simple_colors( array $ids ) {
		$colors = array();
		foreach ( $ids as $id ) {
			$palette = self::palette();
			$colors[] = self::color( $id, isset( $palette[ $id ]['label'] ) ? $palette[ $id ]['label'] : ucfirst( $id ), isset( $palette[ $id ]['hex'] ) ? $palette[ $id ]['hex'] : '#ffffff' );
		}
		return $colors;
	}

	/** @return array<int,array<string,string>> */
	private static function shirt_colors() {
		return self::simple_colors( array( 'black', 'white', 'red', 'navy', 'aqua', 'dark-gray', 'khaki', 'yellow', 'light-green', 'dark-green', 'pastel-pink', 'magenta' ) );
	}

	/** @return array<int,array<string,string>> */
	private static function cap_variants() {
		return array(
			self::color( 'black', 'Negra totalmente', '#111111' ),
			self::color( 'black-white', 'Negra con frente blanco', '#f7f7f7' ),
			self::color( 'green', 'Verde totalmente', '#17623a' ),
			self::color( 'red', 'Roja totalmente', '#c51f32' ),
			self::color( 'black-yellow', 'Negra con frente amarillo', '#f5d84c' ),
			self::color( 'black-red', 'Negra con frente rojo', '#c51f32' ),
			self::color( 'black-green', 'Negra con frente verde', '#17623a' ),
			self::color( 'black-pink', 'Negra con frente rosado', '#f2a9c9' ),
		);
	}

	/** @return array<string,array<string,string>> */
	private static function palette() {
		return array(
			'black'        => array( 'label' => 'Negro', 'hex' => '#111111' ),
			'white'        => array( 'label' => 'Blanco', 'hex' => '#ffffff' ),
			'red'          => array( 'label' => 'Rojo', 'hex' => '#c51f32' ),
			'blue'         => array( 'label' => 'Azul', 'hex' => '#2563eb' ),
			'navy'         => array( 'label' => 'Azul navi', 'hex' => '#15233f' ),
			'aqua'         => array( 'label' => 'Aqua', 'hex' => '#20c7c9' ),
			'dark-gray'    => array( 'label' => 'Gris oscuro', 'hex' => '#3c4043' ),
			'khaki'        => array( 'label' => 'Kaki', 'hex' => '#b7a277' ),
			'yellow'       => array( 'label' => 'Amarillo', 'hex' => '#f5d84c' ),
			'light-green'  => array( 'label' => 'Verde claro', 'hex' => '#8bd36f' ),
			'dark-green'   => array( 'label' => 'Verde oscuro', 'hex' => '#17623a' ),
			'pastel-pink'  => array( 'label' => 'Rosa pastel', 'hex' => '#f2a9c9' ),
			'magenta'      => array( 'label' => 'Magenta', 'hex' => '#d7288f' ),
		);
	}

	/** @return array<string,string> */
	private static function color( $id, $label, $hex ) {
		return array( 'id' => $id, 'label' => $label, 'hex' => $hex, 'variation_value' => $id );
	}

	/** @return void */
	private static function install_template( Template_Manager $templates, array $definition ) {
		$existing = get_posts(
			array(
				'post_type'      => Template_Manager::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::TEMPLATE_META,
				'meta_value'     => $definition['slug'],
				'no_found_rows'  => true,
			)
		);
		if ( $existing ) {
			return;
		}
		$template_id = wp_insert_post(
			array(
				'post_type'   => Template_Manager::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $definition['title'],
			),
			true
		);
		if ( is_wp_error( $template_id ) ) {
			return;
		}
		update_post_meta( $template_id, Template_Manager::META_KEY, $templates->sanitize_config( $definition['config'] ) );
		update_post_meta( $template_id, self::TEMPLATE_META, $definition['slug'] );
	}
}