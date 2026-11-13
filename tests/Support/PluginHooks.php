<?php
/**
 * The plugin's hook contract: every hook it registers, with priority and
 * accepted-argument count. Asserted by both suites (Unit\\HookContractTest
 * against Brain Monkey's record of add_action/add_filter, Integration\\
 * HookContractTest against WordPress's own $wp_filter).
 *
 * Changing a hook means changing this table: a deliberate, reviewed edit.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Support;

/**
 * "hook | Class::method" => array( priority, accepted args ).
 */
final class PluginHooks {

	/**
	 * Hooks registered whenever the plugin loads: "hook | Class::method" => [priority, accepted args].
	 */
	public const HOOKS = array(
		'acf/settings/load_json | AcfJson::add_load_point' => array( 10, 1 ),
		'acf/settings/save_json | AcfJson::set_save_point' => array( 10, 1 ),
		'init | BlockBindings::register_sources'           => array( 10, 1 ),
		'init | Careers::register_post_type'               => array( 10, 1 ),
		'init | Careers::register_meta'                    => array( 10, 1 ),
		'init | Careers::schedule_expiry'                  => array( 10, 1 ),
		'kwv_careers_expire_jobs | Careers::expire'        => array( 10, 1 ),
		'pre_get_posts | Careers::exclude_expired'         => array( 10, 1 ),
		'render_block_core/shortcode | Careers::render_form_block' => array( 10, 2 ),
		'wp_enqueue_scripts | Careers::enqueue_form_style' => array( 10, 1 ),
		'init | ApplicationForm::maybe_install_form'       => array( 20, 1 ),
		'init | ApplicationContext::register_hooks'        => array( 10, 1 ),
		'render_block_core/read-more | CareersModal::tag_trigger' => array( 10, 3 ),
		'render_block_core/post-title | CareersModal::tag_trigger' => array( 10, 3 ),
		'wp_footer | CareersModal::render_dialog'          => array( 5, 1 ),
		'init | Events::register_taxonomy'                 => array( 10, 1 ),
		'init | Events::register_post_type'                => array( 10, 1 ),
		'init | Events::register_meta'                     => array( 10, 1 ),
		'init | Events::schedule_expiry'                   => array( 10, 1 ),
		'init | Events::maybe_seed_brands'                 => array( 20, 1 ),
		'kwv_events_expire_events | Events::expire'        => array( 10, 1 ),
		'init | BookingForm::register_hooks'               => array( 10, 1 ),
		'init | EventAccommodation::register_block'        => array( 10, 1 ),
		'init | Brands::register_source'                   => array( 10, 1 ),
		'init | Brands::register_block'                    => array( 10, 1 ),
		'block_bindings_supported_attributes_core/social-link | Brands::allow_social_link_url' => array( 10, 1 ),
		'render_block | Brands::hide_blocks_bound_to_empty_fields' => array( 10, 2 ),
		'template_redirect | WinemakersClub::maybe_handle_join' => array( 5, 1 ),
		'template_redirect | WinemakersClub::maybe_redirect_single_subscription' => array( 10, 1 ),
		'pre_http_request | PayflexConfigurationCache::serve_from_cache' => array( 10, 3 ),
		'http_response | PayflexConfigurationCache::store_in_cache' => array( 10, 3 ),
	);

	/**
	 * Added on init only when Gravity Forms is active.
	 */
	public const GRAVITY_FORMS_HOOKS = array(
		'gform_validation | ApplicationContext::validate' => array( 10, 1 ),
		'gform_validation_message | ApplicationContext::validation_message' => array( 10, 2 ),
		'gform_pre_submission | ApplicationContext::fill' => array( 10, 1 ),
		'gform_pre_send_email | ApplicationContext::tidy_hr_email' => array( 10, 3 ),
		'gform_pre_render | BookingForm::prepare'         => array( 10, 1 ),
		'gform_admin_pre_render | BookingForm::populate'  => array( 10, 1 ),
		'gform_pre_validation | BookingForm::prepare'     => array( 10, 1 ),
		'gform_pre_submission_filter | BookingForm::prepare' => array( 10, 1 ),
	);

	/**
	 * Registered by the main plugin file (register_activation_hook), named
	 * after the plugin's path; normalised to this form.
	 */
	public const ACTIVATION_HOOKS = array(
		'activate_kwv-enhancements | Plugin::activate'     => array( 10, 1 ),
		'deactivate_kwv-enhancements | Plugin::deactivate' => array( 10, 1 ),
	);
}
