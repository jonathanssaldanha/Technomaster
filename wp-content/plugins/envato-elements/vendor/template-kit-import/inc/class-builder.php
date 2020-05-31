<?php
/**
 * Template Kit Import:
 *
 * This starts things up. Registers the SPL and starts up some classes.
 *
 * @package Envato/Envato_Template_Kit_Import
 * @since 0.0.2
 */

namespace Envato_Template_Kit_Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


/**
 * Builder registration and management.
 *
 * @since 0.0.2
 */
abstract class Builder extends Base {

	/**
	 * Which builder this is for (e.g. 'elementor' or 'gutenberg')
	 *
	 * @var string
	 */
	public $builder = null;

	/**
	 * The current loaded kit ID frmo the CPT database
	 *
	 * @var int
	 */
	public $kit_id = null;

	/**
	 * Checks a template kid ID against the CPT database and loads if it's the correct type.
	 *
	 * @param $template_kit_id
	 *
	 * @return int|null
	 */
	public function load_kit( $template_kit_id ) {
		$this->kit_id = null;
		$post         = get_post( $template_kit_id );
		if ( CPT_Kits::get_instance()->cpt_slug === $post->post_type ) {
			// Confirmed that the required ID is in fact one of our uploaded template kits.
			$builder = get_post_meta( $post->ID, 'envato_tk_builder', true );
			if ( $this->builder === $builder ) {
				$this->kit_id = $post->ID;
			}
		}
		return $this->kit_id;
	}

	/**
	 * Imports a template to the desired page builder
	 *
	 * @param $template_index
	 */
	public function import_template( $template_index ) {
		// Child class needs to override this.
	}

	/**
	 * Gets the imported manifest data from the CPT post meta
	 *
	 * @return array
	 */
	public function get_manifest_data() {
		return get_post_meta( $this->kit_id, 'envato_tk_manifest', true );
	}

	/**
	 * Gets the list of required plugins from metadata
	 *
	 * @return array
	 */
	public function get_required_plugins() {
		$manifest_data = $this->get_manifest_data();
		return Required_Plugin::get_instance()->check_for_required_plugins( ! empty( $manifest_data['required_plugins'] ) ? $manifest_data['required_plugins'] : array() );
	}

	/**
	 * Gets any required theme for this Template Kit
	 *
	 * @return array
	 */
	public function get_required_theme() {
		return array();
	}

	/**
	 * Gets the list of available templates in this kit from metadata
	 *
	 * @return array
	 */
	public function get_available_templates() {
		$manifest             = $this->get_manifest_data();
		$manifest_has_changed = false;
		if ( $manifest && ! empty( $manifest['templates'] ) ) {
			$templates           = $manifest['templates'];
			$screenshot_base_url = $this->get_template_kit_temporary_url();
			foreach ( $templates as $template_index => $template ) {
				$templates[ $template_index ]['screenshot_url'] = $screenshot_base_url . $template['screenshot'];
				// Checking the additional template informatino strings for &amp; and converting to & so they display.
				if( ! empty( $template['metadata']['additional_template_information'] ) ) {
						$templates[ $template_index ]['metadata']['additional_template_information'] = array_map( 'htmlspecialchars_decode', $template['metadata']['additional_template_information'] );
				}
				// Check if the name of the template has any characters that need decoding and convert them.
				$templates[ $template_index ]['name'] = html_entity_decode($templates[ $template_index ]['name']);
				// todo: this will be an array of imports into the page builder library
				// So we can show if the template has been imported already etc.
				if ( ! isset( $templates[ $template_index ]['imports'] ) ) {
					$templates[ $template_index ]['imports'] = array();
				}
				foreach ( $templates[ $template_index ]['imports'] as $import_id => $imported_template_id ) {
					// check it still exists.
					$maybe_exists = get_post( $imported_template_id );
					if ( ! $maybe_exists || $maybe_exists->ID !== (int) $imported_template_id ) {
						$manifest_has_changed = true;
						unset( $manifest['templates'][ $template_index ]['imports'][ $import_id ] );
					}
				}
			}
			if ( $manifest_has_changed ) {
				// we've removed an imported template, need to refresh our manifest data.
				update_post_meta( $this->kit_id, 'envato_tk_manifest', $manifest );
			}
			return $templates;
		}
		return array();
	}

	/**
	 * Gets the local path for the extracted ZIP file on the hosting account.
	 * Used to get the path for JSON files so page builders can load them in.
	 *
	 * @return string
	 */
	public function get_template_kit_temporary_folder() {
		$template_kit_folder_name = get_post_meta( $this->kit_id, 'envato_tk_folder_name', true );
		$wp_upload_dir            = wp_upload_dir();
		$template_kit_folder_path = $wp_upload_dir['basedir'] . '/template-kits/' . $template_kit_folder_name . '/';
		// Confirm we're pulling this data from the right location:
		if ( strpos( $template_kit_folder_path, $wp_upload_dir['basedir'] ) === 0 ) {
			return $template_kit_folder_path;
		}
		return null;
	}

	/**
	 * Gets the local URL for the extracted ZIP file on the hosting account.
	 * Used for screenshot previews and things like that.
	 *
	 * @return string
	 */
	public function get_template_kit_temporary_url() {
		$template_kit_folder_name = get_post_meta( $this->kit_id, 'envato_tk_folder_name', true );
		$wp_upload_dir            = wp_upload_dir();
		return $wp_upload_dir['baseurl'] . '/template-kits/' . $template_kit_folder_name . '/';
	}

	/**
	 * Get details from the manifest file about a particular template within this kit.
	 *
	 * @param $template_index
	 *
	 * @return bool|array
	 */
	public function get_template_data( $template_index ) {
		$templates = $this->get_available_templates();
		if ( isset( $templates[ $template_index ] ) ) {
			return $templates[ $template_index ];
		}
		return false;
	}

	/**
	 * Record an import event.
	 * For now we append this data to the stored manifest details in the CPT metadata.
	 *
	 * @param $template_index
	 * @param $imported_template_id
	 */
	public function record_import_event( $template_index, $imported_template_id ) {
		$manifest = $this->get_manifest_data();
		if ( $manifest && ! empty( $manifest['templates'] ) ) {
			if ( isset( $manifest['templates'][ $template_index ] ) ) {
				if ( ! isset( $manifest['templates'][ $template_index ]['imports'] ) ) {
					$manifest['templates'][ $template_index ]['imports'] = array();
				}
				// Append import event to manifest file.
				$manifest['templates'][ $template_index ]['imports'][] = array(
					'imported_template_id' => $imported_template_id,
				);
			}
		}
		update_post_meta( $this->kit_id, 'envato_tk_manifest', $manifest );

	}


	/**
	 * What text to display on input buttons
	 *
	 * @return string
	 */
	public function get_import_button_text() {
		return esc_html__( 'Import Template', 'template-kit-import' );
	}


	/**
	 * Get the URL to the list of imported templates.
	 *
	 * @param $imported_template_id int - Optional imported template ID.
	 *
	 * @return string
	 */
	public function get_imported_template_edit_url( $imported_template_id = 0 ) {
		return admin_url( 'edit.php?post_type=page' );
	}


	/**
	 * Get the current template kit name.
	 *
	 * @return string
	 */
	public function get_name() {
		$post = get_post( $this->kit_id );
		return $post->post_title;
	}

	/**
	 * Get any unmet requirements for importing this template to WordPress
	 *
	 * @param $template array
	 *
	 * @return array
	 */
	public function get_list_of_unmet_requirements( $template ) {
		return array();
	}

}
