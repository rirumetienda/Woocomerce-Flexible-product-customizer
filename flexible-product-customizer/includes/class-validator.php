<?php
/**
 * Server-side design document validation.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Validator {
	/** @var Template_Manager */
	private $templates;

	public function __construct( Template_Manager $templates ) {
		$this->templates = $templates;
	}

	/**
	 * Normalize untrusted browser design data against its immutable template snapshot.
	 *
	 * @param array $design   Raw design document.
	 * @param array $session  Session record.
	 * @return array|\WP_Error
	 */
	public function validate_design( array $design, array $session ) {
		$snapshot = isset( $session['payload']['template_snapshot'] ) && is_array( $session['payload']['template_snapshot'] )
			? $session['payload']['template_snapshot']
			: $this->templates->get_public_config( $session['template_id'] );
		$snapshot = $this->templates->normalize_snapshot( $snapshot );

		$colors = wp_list_pluck( $snapshot['colors'], 'id' );
		$color  = sanitize_title( isset( $design['color_id'] ) ? $design['color_id'] : '' );
		if ( ! in_array( $color, $colors, true ) ) {
			return new \WP_Error( 'fpcw_invalid_color', __( 'The selected product color is not available.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
		}
		$available_surface_ids = $this->templates->available_surface_ids( $snapshot, $color );

		$uploads = isset( $session['payload']['uploads'] ) && is_array( $session['payload']['uploads'] ) ? $session['payload']['uploads'] : array();
		$files   = array();
		foreach ( $uploads as $file ) {
			if ( ! empty( $file['id'] ) ) {
				$files[ $file['id'] ] = $file;
			}
		}

		$raw_surfaces = isset( $design['surfaces'] ) && is_array( $design['surfaces'] ) ? $design['surfaces'] : array();
		$raw_by_id    = array();
		foreach ( $raw_surfaces as $raw_surface ) {
			if ( is_array( $raw_surface ) && ! empty( $raw_surface['id'] ) ) {
				$raw_by_id[ sanitize_title( $raw_surface['id'] ) ] = $raw_surface;
			}
		}

		$clean_surfaces = array();
		$used_files     = array();
		$element_count  = 0;
		foreach ( $snapshot['surfaces'] as $surface ) {
			$raw     = isset( $raw_by_id[ $surface['id'] ] ) ? $raw_by_id[ $surface['id'] ] : array();
			$objects = isset( $raw['objects'] ) && is_array( $raw['objects'] ) ? $raw['objects'] : array();
			if ( ! in_array( $surface['id'], $available_surface_ids, true ) ) {
				if ( $objects ) {
					return new \WP_Error( 'fpcw_surface_unavailable', sprintf( __( 'The surface %s is not available for the selected color.', 'flexible-product-customizer' ), $surface['label'] ), array( 'status' => 400 ) );
				}
				continue;
			}
			$images  = 0;
			$texts   = 0;
			$clean   = array();

			foreach ( $objects as $object ) {
				if ( ! is_array( $object ) || empty( $object['type'] ) ) {
					continue;
				}
				$type = sanitize_key( $object['type'] );
				if ( 'image' === $type ) {
					++$images;
					if ( ! $surface['allow_images'] || $images > $surface['max_images'] ) {
						return new \WP_Error( 'fpcw_image_limit', sprintf( __( 'The image limit for %s was exceeded.', 'flexible-product-customizer' ), $surface['label'] ), array( 'status' => 400 ) );
					}
					$file_id = sanitize_key( isset( $object['file_id'] ) ? $object['file_id'] : '' );
					if ( ! isset( $files[ $file_id ] ) ) {
						return new \WP_Error( 'fpcw_missing_file', __( 'A design image is no longer available.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
					}
					$used_files[] = $file_id;
					$clean[]      = array_merge( $this->geometry( $object, $surface, $snapshot['product_type'] ), array( 'id' => sanitize_key( $object['id'] ), 'type' => 'image', 'file_id' => $file_id ) );
				} elseif ( 'text' === $type ) {
					++$texts;
					if ( ! $surface['allow_text'] || $texts > $surface['max_texts'] ) {
						return new \WP_Error( 'fpcw_text_limit', sprintf( __( 'The text limit for %s was exceeded.', 'flexible-product-customizer' ), $surface['label'] ), array( 'status' => 400 ) );
					}
					$text = sanitize_textarea_field( isset( $object['text'] ) ? $object['text'] : '' );
					$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
					if ( '' === $text || $length > 300 ) {
						return new \WP_Error( 'fpcw_invalid_text', __( 'Text must contain between 1 and 300 characters.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
					}
					$font = sanitize_text_field( isset( $object['font'] ) ? $object['font'] : '' );
					if ( ! in_array( $font, $snapshot['fonts'], true ) ) {
						$font = $snapshot['fonts'][0];
					}
					$text_color = sanitize_hex_color( isset( $object['color'] ) ? $object['color'] : '' ) ?: '#000000';
					$clean[] = array_merge(
						$this->geometry( $object, $surface, $snapshot['product_type'] ),
						array(
							'id' => sanitize_key( $object['id'] ), 'type' => 'text', 'text' => $text, 'font' => $font,
							'font_size' => $this->number( isset( $object['font_size'] ) ? $object['font_size'] : 32, 8, 300 ),
							'color' => $text_color, 'outline_color' => sanitize_hex_color( isset( $object['outline_color'] ) ? $object['outline_color'] : '' ) ?: '#ffffff',
							'outline_width' => $this->number( isset( $object['outline_width'] ) ? $object['outline_width'] : 3, 1, 20 ),
							'bold' => ! empty( $object['bold'] ), 'italic' => ! empty( $object['italic'] ), 'underline' => ! empty( $object['underline'] ),
							'outline' => ! empty( $object['outline'] ), 'align' => in_array( isset( $object['align'] ) ? $object['align'] : '', array( 'left', 'center', 'right' ), true ) ? $object['align'] : 'center',
						)
					);
				}
			}

			if ( $clean ) {
				$clean_surfaces[] = array( 'id' => $surface['id'], 'objects' => $clean );
			}
			$element_count += count( $clean );
		}
		if ( ! $element_count ) {
			return new \WP_Error( 'fpcw_empty_design', __( 'Add an image or text before saving the customization.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
		}

		return array(
			'schema_version' => 2,
			'color_id'       => $color,
			'surfaces'       => $clean_surfaces,
			'used_file_ids'  => array_values( array_unique( $used_files ) ),
		);
	}

	/** @param array $object Object. @param array $surface Surface. @param string $product_type Product geometry. @return array */
	private function geometry( array $object, array $surface, $product_type ) {
		$rotation = isset( $object['rotation'] ) ? (int) $object['rotation'] : 0;
		$rotation = in_array( $rotation, array( 0, 90, 180, 270 ), true ) ? $rotation : 0;
		if ( 'cylindrical' === $product_type && ! empty( $surface['print_area'] ) ) {
			$area = array( 'x' => 0.0, 'y' => 0.0, 'width' => (float) $surface['print_area']['width'], 'height' => (float) $surface['print_area']['height'] );
		} else {
			$area = array(
				'x'      => (float) $surface['workspace']['x'],
				'y'      => (float) $surface['workspace']['y'],
				'width'  => (float) $surface['workspace']['width'],
				'height' => (float) $surface['workspace']['height'],
			);
		}
		$max_width = $area['width'] * 4;
		$max_height = $area['height'] * 4;
		$width    = $this->number( isset( $object['width'] ) ? $object['width'] : 1, 1, $max_width );
		$height   = $this->number( isset( $object['height'] ) ? $object['height'] : 1, 1, $max_height );
		$swapped  = in_array( $rotation, array( 90, 270 ), true );
		$bounds_w = $swapped ? $height : $width;
		$bounds_h = $swapped ? $width : $height;
		$scale    = min( 1, $max_width / $bounds_w, $max_height / $bounds_h );
		$width   *= $scale;
		$height  *= $scale;
		$bounds_w = $swapped ? $height : $width;
		$bounds_h = $swapped ? $width : $height;
		$visible_x = min( $area['width'] * 0.18, max( 12, $area['width'] * 0.04 ) );
		$visible_y = min( $area['height'] * 0.18, max( 12, $area['height'] * 0.04 ) );
		$x        = $this->number( isset( $object['x'] ) ? $object['x'] : 0, $area['x'] + $visible_x - ( $bounds_w / 2 ), $area['x'] + $area['width'] - $visible_x + ( $bounds_w / 2 ) );
		$y        = $this->number( isset( $object['y'] ) ? $object['y'] : 0, $area['y'] + $visible_y - ( $bounds_h / 2 ), $area['y'] + $area['height'] - $visible_y + ( $bounds_h / 2 ) );
		return array(
			'x'        => round( $x, 2 ),
			'y'        => round( $y, 2 ),
			'width'    => round( $width, 2 ),
			'height'   => round( $height, 2 ),
			'rotation' => $rotation,
		);
	}

	/** @param mixed $value Value. @param float $min Min. @param float $max Max. @return float */
	private function number( $value, $min, $max ) {
		return round( max( $min, min( $max, (float) $value ) ), 2 );
	}
}
