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

		$api_key    = sanitize_text_field( $api_key );
		$bots       = $this->get_bots();
		$key_hash   = wp_hash( $api_key );

		foreach ( $bots as $bot_id => $bot ) {
			if ( hash_equals( $bot['key_hash'], $key_hash ) && 'active' === ( $bot['status'] ?? 'active' ) ) {
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
			'key_hash'    => wp_hash( $api_key ),
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

		$allowed_fields = [ 'name', 'permissions', 'status' ];
		foreach ( $allowed_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$bots[ $bot_id ][ $field ] = 'name' === $field
					? sanitize_text_field( $data[ $field ] )
					: $data[ $field ];
			}
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

		$bots[ $bot_id ]['key_hash']   = wp_hash( $api_key );
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
	 * Log an access event for a bot.
	 *
	 * @param string $bot_id Bot ID.
	 */
	private function log_access( $bot_id ) {
		$bots = $this->get_bots();
		if ( isset( $bots[ $bot_id ] ) ) {
			$bots[ $bot_id ]['last_access']    = current_time( 'mysql', true );
			$bots[ $bot_id ]['request_count'] = ( $bots[ $bot_id ]['request_count'] ?? 0 ) + 1;
			$this->save_bots( $bots );
		}
	}
}
