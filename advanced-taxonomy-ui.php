<?php

/**
 * Plugin Name: Advanced Taxonomy UI
 * Plugin URI: http://github.com/micahwave/advanced-taxonomy-ui
 * Author: Micah Ernst
 * Author URI: http://micahernst.com
 * Description: Modify the default taxonomy UIs to support single term selection, or multiple (non-hierarchical) term selection.
 * Version: 0.1
 */

class Taxonomy_UI {

	/**
	 * Possible ui types we'll accept
	 */
	var $ui_types = array( 'radio', 'checkbox', 'select' );

	/**
	 * Keep track of our UI inputs for saving
	 */
	var $inputs = array();

	/**
	 * The taxonomies we want to adjust
	 */
	var $taxonomies = array();

	/**
	 * Setup our hooks
	 */
	public function __construct() {

		add_action( 'init', array( $this, 'init' ), 999 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 20, 2 ); 
	}

	/**
	 * Find the taxonomies that we want to have custom UIs for
	 *
	 * @return void
	 */
	public function init() {

		global $wp_taxonomies;

		foreach( $wp_taxonomies as $key => $tax ) {

			if( isset( $tax->ui ) && in_array( $tax->ui, $this->ui_types ) ) {

				$this->taxonomies[$key] = $tax;

				foreach( $tax->object_type as $type ) {
					$this->inputs[$type][] = array( 
						'tax' => $key, 
						'name' => $key . '-' . $tax->ui,
						'type' => $tax->ui
					);
				}
			}
		}
	}

	/**
	 * Remove the default meta box and then add ours
	 *
	 * @return void
	 */
	public function add_meta_boxes() {

		foreach( $this->taxonomies as $key => $tax ) {

			$id = $tax->hierarchical ? $key . 'div' : 'tagsdiv-' . $key;

			foreach( $tax->object_type as $type ) {

				remove_meta_box( $id, $type, 'side' );

				add_meta_box( 
					$key . '-metabox', 
					$tax->labels->name, 
					array( $this, 'meta_box' ), 
					$type, 
					'side', 
					null, 
					array(
						'tax' => $key,
						'type' => $tax->ui, 
						'input' => $key . '-' . $tax->ui
					)
				);
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

		// add a nonce
		wp_nonce_field( $args['tax'] . '_metabox', $args['tax']. '_nonce' );

		switch( $args['type'] ) {
			case 'checkbox' :
				$this->render_checkboxes( $post, $args['input'], $args['tax'] );
				break;
			case 'radio' :
				$this->render_radio_buttons( $post, $args['input'], $args['tax'] );
				break;
			default :
				$this->render_select( $post, $args['input'], $args['tax'] );
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
			'hide_empty' => false
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

		// pipe this thru ob_start and capture the result so we can filter it
		wp_terms_checklist( $post->ID, apply_filters( 'taxonomy_ui_' . $tax . '_args', $args ) );

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

		if( empty( $this->inputs[$post->post_type] ) )
			return;

		// loop through all the inputs for this post type
		foreach( $this->inputs[$post->post_type] as $input ) {

			// convience vars
			$tax = $input['tax'];
			$nonce = $input['tax'] . '_nonce';
			$name = $input['name'];
			$type = $input['type'];

			if( !isset( $_POST[$nonce] ) )
				continue;

			if( !wp_verify_nonce( $_POST[$nonce], $tax . '_metabox' ) )
				continue;

			if( isset( $_POST[$name] ) ) {

				// only set a single term if its a select or radio button
				if( $type == 'select' || $type == 'radio' ) {

					// set the terms for a post for this taxonomy
					wp_set_object_terms( $post_id, array( intval( $_POST[$name] ) ), $tax );

				} else {

					// save it differently, should already be an array
					$terms = array_map( 'intval', $_POST[$name] );

					wp_set_object_terms( $post_id, $terms, $tax );

				}

			}

			// if nothing is checked delete the term relationships
			if( $type == 'checkbox' && !isset( $_POST[$name] ) ) {
				wp_delete_object_term_relationships( $post_id, $tax );
			}
		}
	}
}
new Taxonomy_UI();