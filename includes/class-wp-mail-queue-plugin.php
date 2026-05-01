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
	 * Repository dependency.
	 *
	 * @var WP_Mail_Queue_Repository
	 */
	private $repository;

	/**
	 * Source detector dependency.
	 *
	 * @var WP_Mail_Queue_Source_Detector
	 */
	private $source_detector;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings        = new WP_Mail_Queue_Settings();
		$this->installer       = new WP_Mail_Queue_Installer( $this->settings );
		$this->repository      = new WP_Mail_Queue_Repository( $this->settings );
		$this->source_detector = new WP_Mail_Queue_Source_Detector();
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

	/**
	 * Returns repository dependency.
	 *
	 * @return WP_Mail_Queue_Repository
	 */
	public function repository() {
		return $this->repository;
	}

	/**
	 * Returns source detector dependency.
	 *
	 * @return WP_Mail_Queue_Source_Detector
	 */
	public function source_detector() {
		return $this->source_detector;
	}
}
