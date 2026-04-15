<?php
/**
 * AI Syndication: Bot Manager
 *
 * Manages API key registration, authentication, and permissions
 * for AI agents accessing the store's product catalog.
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles bot/agent registration, authentication, and access control.
 */
class WC_AI_Syndication_Bot_Manager {

	/**
	 * Option key for storing registered bots.
	 */
	const BOTS_OPTION = 'wc_ai_syndication_bots';

	/**
	 * Transient prefix for access counters.
	 */
	const ACCESS_LOG_PREFIX = 'wc_ai_bot_access_';

	/**
	 * Valid bot statuses.
	 */
	const VALID_STATUSES = [ 'active', 'revoked' ];

	/**
	 * Known permission keys.
	 */
	const VALID_PERMISSIONS = [ 'read_products', 'read_categories', 'prepare_cart', 'check_inventory' ];

	/**
	 * Authenticate an incoming API request.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return string|WP_Error Bot ID on success, WP_Error on failure.
	 */
	public function authenticate( $request ) {
		$api_key = $request->get_header( 'X-AI-Agent-Key' );
		if ( empty( $api_key ) ) {
			// Also check query param fallback for simpler integrations.
			$api_key = $request->get_param( 'ai_agent_key' );
		}

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'ai_syndication_missing_key',
				__( 'Missing X-AI-Agent-Key header.', 'woocommerce-ai-syndication' ),
				[ 'status' => 401 ]
			);
		}

		$api_key = sanitize_text_field( $api_key );
		$bots    = $this->get_bots();

		foreach ( $bots as $bot_id => $bot ) {
			if ( wp_check_password( $api_key, $bot['key_hash'] ) && 'active' === ( $bot['status'] ?? 'active' ) ) {
				$this->log_access( $bot_id );
				return $bot_id;
			}
		}

		return new WP_Error(
			'ai_syndication_invalid_key',
			__( 'Invalid API key.', 'woocommerce-ai-syndication' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Register a new bot/agent.
	 *
	 * @param string $name        Bot name (e.g. "ChatGPT", "Gemini").
	 * @param array  $permissions Bot permissions.
	 * @return array Bot data with plaintext API key (shown only once).
	 */
	public function register_bot( $name, $permissions = [] ) {
		$bot_id  = wp_generate_uuid4();
		$api_key = 'wc_ai_' . wp_generate_password( 32, false );
		$bots    = $this->get_bots();

		$default_permissions = [
			'read_products'  => true,
			'read_categories' => true,
			'prepare_cart'   => true,
			'check_inventory' => true,
		];

		$bots[ $bot_id ] = [
			'name'        => sanitize_text_field( $name ),
			'key_hash'    => wp_hash_password( $api_key ),
			'key_prefix'  => substr( $api_key, 0, 10 ) . '...',
			'permissions'  => wp_parse_args( $permissions, $default_permissions ),
			'status'      => 'active',
			'created_at'  => current_time( 'mysql', true ),
			'last_access' => null,
			'request_count' => 0,
		];

		$this->save_bots( $bots );

		return [
			'bot_id'  => $bot_id,
			'name'    => $bots[ $bot_id ]['name'],
			'api_key' => $api_key,
			'status'  => 'active',
		];
	}

	/**
	 * Revoke a bot's access.
	 *
	 * @param string $bot_id Bot ID.
	 * @return bool True if revoked, false if not found.
	 */
	public function revoke_bot( $bot_id ) {
		$bots = $this->get_bots();
		if ( ! isset( $bots[ $bot_id ] ) ) {
			return false;
		}

		$bots[ $bot_id ]['status'] = 'revoked';
		$this->save_bots( $bots );
		return true;
	}

	/**
	 * Delete a bot entirely.
	 *
	 * @param string $bot_id Bot ID.
	 * @return bool True if deleted, false if not found.
	 */
	public function delete_bot( $bot_id ) {
		$bots = $this->get_bots();
		if ( ! isset( $bots[ $bot_id ] ) ) {
			return false;
		}

		unset( $bots[ $bot_id ] );
		$this->save_bots( $bots );
		return true;
	}

	/**
	 * Update a bot's settings.
	 *
	 * @param string $bot_id Bot ID.
	 * @param array  $data   Data to update (name, permissions, status).
	 * @return bool True if updated, false if not found.
	 */
	public function update_bot( $bot_id, $data ) {
		$bots = $this->get_bots();
		if ( ! isset( $bots[ $bot_id ] ) ) {
			return false;
		}

		if ( isset( $data['name'] ) ) {
			$bots[ $bot_id ]['name'] = sanitize_text_field( $data['name'] );
		}

		if ( isset( $data['status'] ) && in_array( $data['status'], self::VALID_STATUSES, true ) ) {
			$bots[ $bot_id ]['status'] = $data['status'];
		}

		if ( isset( $data['permissions'] ) && is_array( $data['permissions'] ) ) {
			$sanitized_permissions = [];
			foreach ( self::VALID_PERMISSIONS as $key ) {
				$sanitized_permissions[ $key ] = ! empty( $data['permissions'][ $key ] );
			}
			$bots[ $bot_id ]['permissions'] = $sanitized_permissions;
		}

		$this->save_bots( $bots );
		return true;
	}

	/**
	 * Regenerate API key for an existing bot.
	 *
	 * @param string $bot_id Bot ID.
	 * @return array|false New bot data with plaintext key, or false if not found.
	 */
	public function regenerate_key( $bot_id ) {
		$bots = $this->get_bots();
		if ( ! isset( $bots[ $bot_id ] ) ) {
			return false;
		}

		$api_key = 'wc_ai_' . wp_generate_password( 32, false );

		$bots[ $bot_id ]['key_hash']   = wp_hash_password( $api_key );
		$bots[ $bot_id ]['key_prefix'] = substr( $api_key, 0, 10 ) . '...';
		$this->save_bots( $bots );

		return [
			'bot_id'  => $bot_id,
			'name'    => $bots[ $bot_id ]['name'],
			'api_key' => $api_key,
		];
	}

	/**
	 * Check if a bot has a specific permission.
	 *
	 * @param string $bot_id     Bot ID.
	 * @param string $permission Permission key.
	 * @return bool
	 */
	public function has_permission( $bot_id, $permission ) {
		$bots = $this->get_bots();
		if ( ! isset( $bots[ $bot_id ] ) ) {
			return false;
		}

		return ! empty( $bots[ $bot_id ]['permissions'][ $permission ] );
	}

	/**
	 * Get all registered bots (without key hashes for display).
	 *
	 * @return array
	 */
	public function get_bots_for_display() {
		$bots   = $this->get_bots();
		$result = [];

		foreach ( $bots as $bot_id => $bot ) {
			$result[] = [
				'id'            => $bot_id,
				'name'          => $bot['name'],
				'key_prefix'    => $bot['key_prefix'],
				'permissions'   => $bot['permissions'],
				'status'        => $bot['status'],
				'created_at'    => $bot['created_at'],
				'last_access'   => $bot['last_access'],
				'request_count' => $bot['request_count'],
			];
		}

		return $result;
	}

	/**
	 * Get all registered bots.
	 *
	 * @return array
	 */
	private function get_bots() {
		return get_option( self::BOTS_OPTION, [] );
	}

	/**
	 * Save bots to database.
	 *
	 * @param array $bots The bots data.
	 */
	private function save_bots( $bots ) {
		update_option( self::BOTS_OPTION, $bots, false );
	}

	/**
	 * Log an access event for a bot using a lightweight transient counter.
	 *
	 * Instead of writing to wp_options on every request (which causes DB
	 * contention and race conditions under concurrent load), we increment
	 * a per-bot transient and schedule a flush to persist the totals.
	 *
	 * @param string $bot_id Bot ID.
	 */
	private function log_access( $bot_id ) {
		$transient_key = self::ACCESS_LOG_PREFIX . $bot_id;
		$count         = (int) get_transient( $transient_key );
		set_transient( $transient_key, $count + 1, HOUR_IN_SECONDS );

		// Register a shutdown flush (runs once per request regardless of how many bots authenticated).
		if ( ! has_action( 'shutdown', [ __CLASS__, 'flush_access_logs' ] ) ) {
			add_action( 'shutdown', [ __CLASS__, 'flush_access_logs' ] );
		}
	}

	/**
	 * Flush accumulated access counters to the bots option.
	 *
	 * Runs once on shutdown to batch all access increments into a single DB write.
	 */
	public static function flush_access_logs() {
		$bots    = get_option( self::BOTS_OPTION, [] );
		$changed = false;

		foreach ( $bots as $bot_id => &$bot ) {
			$transient_key = self::ACCESS_LOG_PREFIX . $bot_id;
			$pending       = (int) get_transient( $transient_key );

			if ( $pending > 0 ) {
				$bot['request_count'] = ( $bot['request_count'] ?? 0 ) + $pending;
				$bot['last_access']   = current_time( 'mysql', true );
				delete_transient( $transient_key );
				$changed = true;
			}
		}
		unset( $bot );

		if ( $changed ) {
			update_option( self::BOTS_OPTION, $bots, false );
		}
	}
}
