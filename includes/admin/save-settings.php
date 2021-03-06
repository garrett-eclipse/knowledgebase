<?php
/**
 * Save settings.
 *
 * Functions to register, read, write and update settings.
 * Portions of this code have been inspired by Easy Digital Downloads, WordPress Settings Sandbox, etc.
 *
 * @link  https://webberzone.com
 * @since 1.2.0
 *
 * @package    WZKB
 * @subpackage Admin/Save_Settings
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}


/**
 * Sanitize the form data being submitted.
 *
 * @since  1.2.0
 * @param  array $input Input unclean array.
 * @return array Sanitized array
 */
function wzkb_settings_sanitize( $input = array() ) {

	// First, we read the options collection.
	global $wzkb_settings;

	// This should be set if a form is submitted, so let's save it in the $referrer variable.
	if ( empty( $_POST['_wp_http_referer'] ) ) {
		return $input;
	}

	parse_str( sanitize_text_field( wp_unslash( $_POST['_wp_http_referer'] ) ), $referrer ); // Input var okay.

	// Get the various settings we've registered.
	$settings = wzkb_get_registered_settings();
	$settings_types = wzkb_get_registered_settings_types();

	// Check if we need to set to defaults.
	$reset = isset( $_POST['settings_reset'] );

	if ( $reset ) {
		wzkb_settings_reset();
		$wzkb_settings = get_option( 'wzkb_settings' );

		add_settings_error( 'wzkb-notices', '', __( 'Settings have been reset to their default values. Reload this page to view the updated settings', 'knowledgebase' ), 'error' );

		// Re-register post type and flush the rewrite rules.
		wzkb_register_post_type();
		flush_rewrite_rules();

		return $wzkb_settings;
	}

	// Get the tab. This is also our settings' section.
	$tab = isset( $referrer['tab'] ) ? $referrer['tab'] : 'general';

	$input = $input ? $input : array();

	/**
	 * Filter the settings for the tab. e.g. wzkb_settings_general_sanitize.
	 *
	 * @since  1.2.0
	 * @param  array $input Input unclean array
	 */
	$input = apply_filters( 'wzkb_settings_' . $tab . '_sanitize', $input );

	// Create out output array by merging the existing settings with the ones submitted.
	$output = array_merge( $wzkb_settings, $input );

	// Loop through each setting being saved and pass it through a sanitization filter.
	foreach ( $settings_types as $key => $type ) {

		/**
		 * Skip settings that are not really settings.
		 *
		 * @since  1.5.0
		 * @param  array $non_setting_types Array of types which are not settings.
		 */
		$non_setting_types = apply_filters( 'wzkb_non_setting_types', array( 'header', 'descriptive_text' ) );

		if ( in_array( $type, $non_setting_types, true ) ) {
			continue;
		}

		if ( array_key_exists( $key, $output ) ) {

			/**
			 * Field type filter.
			 *
			 * @since 1.2.0
			 * @param array $output[$key] Setting value.
			 * @param array $key Setting key.
			 */
			$output[ $key ] = apply_filters( 'wzkb_settings_sanitize_' . $type, $output[ $key ], $key );
		}

		/**
		 * Field type filter for a specific key.
		 *
		 * @since 1.2.0
		 * @param array $output[$key] Setting value.
		 * @param array $key Setting key.
		 */
		$output[ $key ] = apply_filters( 'wzkb_settings_sanitize' . $key, $output[ $key ], $key );

		// Delete any key that is not present when we submit the input array.
		if ( empty( $input[ $key ] ) ) {
			unset( $output[ $key ] );
		}
	}

	// Delete any settings that are no longer part of our registered settings.
	if ( array_key_exists( $key, $output ) && ! array_key_exists( $key, $settings_types ) ) {
		unset( $output[ $key ] );
	}

	add_settings_error( 'wzkb-notices', '', __( 'Settings updated.', 'knowledgebase' ), 'updated' );

	// Re-register post type and flush the rewrite rules.
	wzkb_register_post_type();
	flush_rewrite_rules();

	/**
	 * Filter the settings array before it is returned.
	 *
	 * @since 1.5.0
	 * @param array $output Settings array.
	 * @param array $input Input settings array.
	 */
	return apply_filters( 'wzkb_settings_sanitize', $output, $input );

}


/**
 * Sanitize text fields
 *
 * @since 1.2.0
 *
 * @param  array $value The field value.
 * @return string  $value  Sanitized value
 */
function wzkb_sanitize_text_field( $value ) {
	return wzkb_sanitize_textarea_field( $value );
}
add_filter( 'wzkb_settings_sanitize_text', 'wzkb_sanitize_text_field' );


/**
 * Sanitize CSV fields
 *
 * @since 1.5.0
 *
 * @param  array $value The field value.
 * @return string  $value  Sanitized value
 */
function wzkb_sanitize_csv_field( $value ) {

	return implode( ',', array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $value ) ) ) ) );
}
add_filter( 'wzkb_settings_sanitize_csv', 'wzkb_sanitize_csv_field' );


/**
 * Sanitize CSV fields which hold numbers e.g. IDs
 *
 * @since 1.5.0
 *
 * @param  array $value The field value.
 * @return string  $value  Sanitized value
 */
function wzkb_sanitize_numbercsv_field( $value ) {

	return implode( ',', array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $value ) ) ) ) ) );
}
add_filter( 'wzkb_settings_sanitize_numbercsv', 'wzkb_sanitize_numbercsv_field' );


/**
 * Sanitize textarea fields
 *
 * @since 1.2.0
 *
 * @param  array $value The field value.
 * @return string  $value  Sanitized value
 */
function wzkb_sanitize_textarea_field( $value ) {

	global $allowedposttags;

	// We need more tags to allow for script and style.
	$moretags = array(
		'script'    => array(
			'type'     => true,
			'src'      => true,
			'async'    => true,
			'defer'    => true,
			'charset'  => true,
			'lang'     => true,
		),
		'style'     => array(
			'type'     => true,
			'media'    => true,
			'scoped'   => true,
			'lang'     => true,
		),
		'link'      => array(
			'rel'      => true,
			'type'     => true,
			'href'     => true,
			'media'    => true,
			'sizes'    => true,
			'hreflang' => true,
		),
	);

	$allowedtags = array_merge( $allowedposttags, $moretags );

	/**
	 * Filter allowed tags allowed when sanitizing text and textarea fields.
	 *
	 * @since 1.5.0
	 *
	 * @param array $allowedtags Allowed tags array.
	 * @param array $value The field value.
	 */
	$allowedtags = apply_filters( 'wzkb_sanitize_allowed_tags', $allowedtags, $value );

	return wp_kses( wp_unslash( $value ), $allowedtags );

}
add_filter( 'wzkb_settings_sanitize_textarea', 'wzkb_sanitize_textarea_field' );


/**
 * Sanitize checkbox fields
 *
 * @since 1.5.0
 *
 * @param  array $value The field value.
 * @return string|int  $value  Sanitized value
 */
function wzkb_sanitize_checkbox_field( $value ) {

	$value = ( -1 === (int) $value ) ? 0 : 1;

	return $value;
}
add_filter( 'wzkb_settings_sanitize_checkbox', 'wzkb_sanitize_checkbox_field' );


/**
 * Sanitize post_types fields
 *
 * @since 1.5.0
 *
 * @param  array $value The field value.
 * @return string  $value  Sanitized value
 */
function wzkb_sanitize_post_types_field( $value ) {

	$post_types = is_array( $value ) ? array_map( 'sanitize_text_field', wp_unslash( $value ) ) : array( 'post', 'page' );

	return implode( ',', $post_types );
}
add_filter( 'wzkb_settings_sanitize_post_types', 'wzkb_sanitize_post_types_field' );


