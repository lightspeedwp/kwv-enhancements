<?php
/**
 * Core contracts shared by every KWV Enhancements module.
 *
 * A module is a self-contained unit of functionality (Careers, Events, the SCF
 * JSON wiring, …). The plugin container ({@see Plugin}) holds one instance of
 * each and drives their lifecycle through these interfaces.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A unit of plugin functionality.
 *
 * The container calls register() once, on load, so the module can wire its
 * WordPress hooks.
 */
interface Module {

	/**
	 * Register the module's hooks. Called once when the plugin boots.
	 */
	public function register(): void;
}

/**
 * A module with setup work to run on plugin activation — registering
 * rewrite-backed objects, seeding data, scheduling cron.
 */
interface Activatable {

	/**
	 * Run activation-time setup.
	 *
	 * Rewrite rules are flushed by the container after every module has
	 * activated, so implementations need not flush themselves.
	 */
	public function activate(): void;
}

/**
 * A module with teardown to run on plugin deactivation — clearing its
 * scheduled cron, and so on.
 */
interface Deactivatable {

	/**
	 * Run deactivation-time teardown.
	 */
	public function deactivate(): void;
}
