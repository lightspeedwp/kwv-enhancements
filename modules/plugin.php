<?php
/**
 * The plugin container.
 *
 * Holds one instance of each module, registers their hooks on load, and
 * delegates activation/deactivation to the modules that opt in.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps KWV Enhancements and owns its modules.
 */
final class Plugin {

	/**
	 * The shared instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * The registered modules, in load order.
	 *
	 * @var Module[]
	 */
	private $modules;

	/**
	 * Get the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Build the module set. Order matters for activation (Careers before Events
	 * is fine; each module owns its own ordering internally).
	 */
	private function __construct() {
		$careers = new Careers();

		$this->modules = array(
			new AcfJson(),
			new BlockBindings(),
			$careers,
			new ApplicationForm( new EmailTemplate() ),
			new ApplicationContext( $careers ),
			new CareersModal( $careers ),
			new Events(),
			new BookingForm(),
			new EventAccommodation(),
			new WinemakersClub(),
			new PayflexConfigurationCache(),
		);
	}

	/**
	 * Register every module's hooks. Called once when the plugin boots.
	 */
	public function register(): void {
		foreach ( $this->modules as $module ) {
			$module->register();
		}
	}

	/**
	 * Activation: run each activatable module's setup, then flush rewrite rules
	 * once so /careers/{slug} and /events/{slug} resolve immediately.
	 */
	public function activate(): void {
		foreach ( $this->modules as $module ) {
			if ( $module instanceof Activatable ) {
				$module->activate();
			}
		}

		flush_rewrite_rules();
	}

	/**
	 * Deactivation: run each deactivatable module's teardown (clearing crons),
	 * then flush rewrite rules.
	 */
	public function deactivate(): void {
		foreach ( $this->modules as $module ) {
			if ( $module instanceof Deactivatable ) {
				$module->deactivate();
			}
		}

		flush_rewrite_rules();
	}
}
