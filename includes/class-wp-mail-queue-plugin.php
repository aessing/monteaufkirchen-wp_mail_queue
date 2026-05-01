<?php
/**
 * Main plugin coordinator.
 *
 * @package WP_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires shared dependencies and plugin hooks.
 */
class WP_Mail_Queue_Plugin {
	/**
	 * Settings dependency.
	 *
	 * @var WP_Mail_Queue_Settings
	 */
	private $settings;

	/**
	 * Installer dependency.
	 *
	 * @var WP_Mail_Queue_Installer
	 */
	private $installer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings  = new WP_Mail_Queue_Settings();
		$this->installer = new WP_Mail_Queue_Installer( $this->settings );
	}

	/**
	 * Registers foundational hooks.
	 *
	 * Later tasks will construct and wire the interceptor, worker, and admin
	 * classes here after those classes exist.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'cron_schedules', array( $this->installer, 'add_cron_schedule' ) );
	}

	/**
	 * Returns settings dependency.
	 *
	 * @return WP_Mail_Queue_Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Returns installer dependency.
	 *
	 * @return WP_Mail_Queue_Installer
	 */
	public function installer() {
		return $this->installer;
	}
}
