<?php

/**
 * Plugin Name: Advanced Taxonomy UI
 * Plugin URI: http://github.com/micahwave/advanced-taxonomy-ui
 * Author: Micah Ernst
 * Author URI: http://micahernst.com
 * Description: Modify the default taxonomy UIs to support single term selection, or multiple (non-hierarchical) term selection.
 * Version: 0.1
 */

if( !class_exists( 'Advanced_Taxonomy_UI' ) ) : 

class Advanced_Taxonomy_UI {

	/**
	 * Possible ui types we'll accept
	 */
	var $ui_types = array( 'radio', 'checkbox', 'select' );

	/**
	 * Keep track of the meta boxes to add
	 */
	var $boxes = array();

	/**
	 * Setup our hooks
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 20, 2 ); 
	}

	/**
	 *
	 */
	public function add_custom_metabox( $tax, $type, $post_types ) {

		$post_types = (array) $post_types;

		foreach( $post_types as $post_type ) {
			$this->boxes[$post_type][] = array(
				'type' => $type,
				'taxonomy' => $tax,
				'name' => 'advanced_taxonomy_' . $tax . '_' . $type
			);
		}
	}

	/**
	 * Remove the default meta box and then add ours
	 *
	 * @return void
	 */
	public function add_meta_boxes() {

		$taxonomies = get_taxonomies( array(), 'objects' );

		foreach( $this->boxes as $post_type => $fields ) {

			foreach( $fields as $field ) {

				$key = $field['taxonomy'];

				// tax exists
				if( isset( $taxonomies[$key] ) ) {

					$tax = $taxonomies[$key];

					$id = $tax->hierarchical ? $key . 'div' : 'tagsdiv-' . $key;

					remove_meta_box( $id, $post_type, 'side' );

					add_meta_box( 
						$key . '-metabox', 
						$tax->labels->name, 
						array( $this, 'meta_box' ), 
						$post_type, 
						'side', 
						null, 
						array(
							'tax' => $key,
							'type' => $field['type'], 
							'name' => $field['name']
						)
					);
				}
			}	
		}
	}

	/**
	 * Determine which type of input well be rendering
	 *
	 * @param object $post
	 * @param array $args
	 * @return void
	 */
	public function meta_box( $post, $args ) {

		// hate this
		$args = $args['args'];

		$nonce = 'advanced_taxonomy_' . $args['tax'] . '_nonce';
		$action = 'advanced_taxonomy_' . $args['tax'] . '_action';

		// add a nonce
		wp_nonce_field( $action, $nonce );

		switch( $args['type'] ) {
			case 'checkbox' :
				$this->render_checkboxes( $post, $args['name'], $args['tax'] );
				break;
			case 'radio' :
				$this->render_radio_buttons( $post, $args['name'], $args['tax'] );
				break;
			default :
				$this->render_select( $post, $args['name'], $args['tax'] );
				break;
		}
	}

	/**
	 * Render a select
	 *
	 * @param object $post
	 * @param string $name
	 * @param string $tax
	 * @return void
	 */
	public function render_select( $post, $name, $tax ) {
		
		$select_args = array(
			'echo' => 0,
			'name' => $name,
			'taxonomy' => $tax,
			'show_option_none' => 'None',
			'selected' => $this->get_selected_term_id( $post->ID, $tax ),
			'hide_empty' => 0,
			'orderby' => 'name'
		);

		// allow select args to be adjusted
		$select_args = apply_filters( 'taxonomy_ui_select_args', $select_args );

		$html = sprintf( 
			'<p>%s</p>',
			wp_dropdown_categories( $select_args )
		);

		echo apply_filters( 'taxonomy_ui_' . $tax . '_select_html', $html, $post );
	}

	/**
	 * Render radio buttons
	 *
	 * @param object $post
	 * @param string $name
	 * @param string $tax
	 * @return void
	 */
	public function render_radio_buttons( $post, $name, $tax ) {

		$terms = get_terms( $tax, array(
			'hide_empty' => false,
			'number' => 200
		));

		$html = '<div style="line-height:1.5;">';

		if( $terms ) {

			// get the selected term for this post
			$selected_term_id = $this->get_selected_term_id( $post->ID, $tax );

			foreach( $terms as $term ) {
				$html .= sprintf(
					'<input type="radio" name="%s" value="%d" %s> <label>%s</label><br>',
					$tax,
					$term->term_id,
					checked( $selected_term_id, $term->term_id, 0 ),
					$term->name
				);
			}
		}

		$html .= '</div>';

		echo apply_filters( 'taxonomy_ui_' . $tax . '_radio_button_html', $html, $post );
	}

	/**
	 * Render checkboxes
	 *
	 * @param object $post
	 * @param string $name
	 * @param string $tax
	 * @return void
	 */
	public function render_checkboxes( $post, $name, $tax ) {

		$args = array(
			'taxonomy' => $tax
		);

		ob_start();

		$args = apply_filters( 'taxonomy_ui_' . $tax . '_args', $args );

		// pipe this thru ob_start and capture the result so we can filter it
		wp_terms_checklist( $post->ID, $args );

		$list = ob_get_contents();

		ob_end_clean();

		if( empty( $list ) ) {
			$list = 'No terms available.';
		} else {

			$list = str_replace( 'tax_input[' . $tax . ']', $name, $list );

			$list = '<ul>' . $list . '</ul>';
		}

		echo apply_filters( 'taxonomy_ui_' . $tax . '_checkbox_html', '<p>' . $list . '</p>', $post );
		
	}

	/**
	 * Get the selected term for a given post id
	 *
	 * @param int $post_id
	 * @param string $tax
	 * @return int
	 */
	public function get_selected_term_id( $post_id, $tax ) {

		$terms = wp_get_object_terms( $post_id, $tax );

		return ( $terms && is_array( $terms ) ) ? $terms[0]->term_id : 0;
	}

	/**
	 * Save our new taxonomy inputs
	 *
	 * @param int $post_id
	 * @param object $post
	 * @return void
	 */
	public function save_post( $post_id, $post ) {

		if( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE )
			return;
	
		if( !current_user_can( 'edit_post', $post_id ) )
			return;

		if( empty( $this->boxes[$post->post_type] ) )
			return;

		// loop through all the inputs for this post type
		foreach( $this->boxes[$post->post_type] as $input ) {

			// convience vars
			$tax = $input['taxonomy'];
			$nonce = 'advanced_taxonomy_' . $tax . '_nonce';
			$action = 'advanced_taxonomy_'  . $tax . '_action';
			$name = $input['name'];
			$type = $input['type'];

			if( !isset( $_POST[$nonce] ) )
				continue;

			if( !wp_verify_nonce( $_POST[$nonce], $action ) )
				continue;

			// must be set and greater than 0
			if( isset( $_POST[$name] ) && $_POST[$name] > 0 ) {

				$terms = ( $type == 'select' || $type == 'radio' ) ? array( intval( $_POST[$name] ) ) : array_map( 'intval', $_POST[$name] );

				wp_set_object_terms( $post_id, $terms, $tax );

			} else {
				
				wp_delete_object_term_relationships( $post_id, $tax );
			}
		}
	}
}
global $ati;
$ati = new Advanced_Taxonomy_UI();

/**
 * Helper to add custom metaboxes
 */
function advanced_taxonomy_metabox( $tax, $type, $post_types ) {
	global $ati;
	$ati->add_custom_metabox( $tax, $type, $post_types );
}

endif;