<?php
/**
 * Main plugin coordinator.
 *
 * @package Monte_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires shared dependencies and plugin hooks.
 */
class Monte_Mail_Queue_Plugin {
	/**
	 * Settings dependency.
	 *
	 * @var Monte_Mail_Queue_Settings
	 */
	private $settings;

	/**
	 * Installer dependency.
	 *
	 * @var Monte_Mail_Queue_Installer
	 */
	private $installer;

	/**
	 * Repository dependency.
	 *
	 * @var Monte_Mail_Queue_Repository
	 */
	private $repository;

	/**
	 * Source detector dependency.
	 *
	 * @var Monte_Mail_Queue_Source_Detector
	 */
	private $source_detector;

	/**
	 * Interceptor dependency.
	 *
	 * @var Monte_Mail_Queue_Interceptor
	 */
	private $interceptor;

	/**
	 * Throttle window dependency.
	 *
	 * @var Monte_Mail_Queue_Throttle_Window
	 */
	private $throttle_window;

	/**
	 * Azure client dependency.
	 *
	 * @var Monte_Mail_Queue_Azure_Email_Client
	 */
	private $azure_client;

	/**
	 * Worker dependency.
	 *
	 * @var Monte_Mail_Queue_Worker
	 */
	private $worker;

	/**
	 * Admin dependency.
	 *
	 * @var Monte_Mail_Queue_Admin
	 */
	private $admin;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings        = new Monte_Mail_Queue_Settings();
		$this->installer       = new Monte_Mail_Queue_Installer( $this->settings );
		$this->repository      = new Monte_Mail_Queue_Repository( $this->settings );
		$this->source_detector = new Monte_Mail_Queue_Source_Detector();
		$this->interceptor     = new Monte_Mail_Queue_Interceptor( $this->settings, $this->repository, $this->source_detector );
		$this->throttle_window = new Monte_Mail_Queue_Throttle_Window( $this->settings, $this->repository );
		$this->azure_client    = new Monte_Mail_Queue_Azure_Email_Client( $this->settings );
		$this->worker          = new Monte_Mail_Queue_Worker( $this->settings, $this->repository, $this->interceptor, $this->throttle_window, $this->azure_client );
	}

	/**
	 * Registers plugin hooks.
	 *
	 * @return void
	 */
	public function init() {
		if ( wp_doing_cron() ) {
			$this->installer->maybe_upgrade();
		}

		add_filter( 'cron_schedules', array( $this->installer, 'add_cron_schedule' ) );
		add_filter( 'pre_wp_mail', array( $this->interceptor, 'pre_wp_mail' ), 10, 2 );
		add_action( WMQT_CRON_HOOK, array( $this->worker, 'process_queue' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( $this->installer, 'maybe_upgrade' ) );
			$this->admin()->init();
		}
	}

	/**
	 * Returns settings dependency.
	 *
	 * @return Monte_Mail_Queue_Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Returns installer dependency.
	 *
	 * @return Monte_Mail_Queue_Installer
	 */
	public function installer() {
		return $this->installer;
	}

	/**
	 * Returns repository dependency.
	 *
	 * @return Monte_Mail_Queue_Repository
	 */
	public function repository() {
		return $this->repository;
	}

	/**
	 * Returns source detector dependency.
	 *
	 * @return Monte_Mail_Queue_Source_Detector
	 */
	public function source_detector() {
		return $this->source_detector;
	}

	/**
	 * Returns interceptor dependency.
	 *
	 * @return Monte_Mail_Queue_Interceptor
	 */
	public function interceptor() {
		return $this->interceptor;
	}

	/**
	 * Returns worker dependency.
	 *
	 * @return Monte_Mail_Queue_Worker
	 */
	public function worker() {
		return $this->worker;
	}

	/**
	 * Returns throttle window dependency.
	 *
	 * @return Monte_Mail_Queue_Throttle_Window
	 */
	public function throttle_window() {
		return $this->throttle_window;
	}

	/**
	 * Returns Azure client dependency.
	 *
	 * @return Monte_Mail_Queue_Azure_Email_Client
	 */
	public function azure_client() {
		return $this->azure_client;
	}

	/**
	 * Returns admin dependency.
	 *
	 * @return Monte_Mail_Queue_Admin
	 */
	public function admin() {
		if ( ! $this->admin instanceof Monte_Mail_Queue_Admin ) {
			$this->admin = new Monte_Mail_Queue_Admin( $this->settings, $this->repository, $this->installer, $this->throttle_window, $this->azure_client );
		}

		return $this->admin;
	}
}
