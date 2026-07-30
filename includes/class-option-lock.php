<?php
/**
 * Atomic option-backed locks.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides compare-and-delete recovery for short WordPress option locks.
 */
final class Option_Lock {
	/**
	 * Acquire one fixed option lock.
	 *
	 * @param string $option_name Lock option name.
	 * @param int    $ttl_seconds Lock lifetime.
	 * @return string Ownership token, or an empty string when busy.
	 */
	public static function acquire( string $option_name, int $ttl_seconds ): string {
		$now   = time();
		$token = wp_generate_uuid4();
		$lock  = array(
			'token'      => $token,
			'created_at' => $now,
			'expires_at' => $now + max( 1, $ttl_seconds ),
		);

		if ( add_option( $option_name, $lock, '', false ) ) {
			return $token;
		}

		$current    = get_option( $option_name, array() );
		$expires_at = is_array( $current ) ? absint( $current['expires_at'] ?? 0 ) : 0;

		if ( $expires_at < 1 && is_array( $current ) ) {
			$expires_at = absint( $current['created_at'] ?? 0 ) + max( 1, $ttl_seconds );
		}

		if ( $expires_at > $now || ! self::delete_if_unchanged( $option_name, $current ) ) {
			return '';
		}

		return add_option( $option_name, $lock, '', false ) ? $token : '';
	}

	/**
	 * Release a lock only when the persisted ownership token still matches.
	 *
	 * @param string $option_name Lock option name.
	 * @param string $token       Ownership token.
	 * @return void
	 */
	public static function release( string $option_name, string $token ): void {
		if ( '' === $token ) {
			return;
		}

		$current = get_option( $option_name, array() );

		if ( ! is_array( $current ) || ! hash_equals( $token, (string) ( $current['token'] ?? '' ) ) ) {
			return;
		}

		self::delete_if_unchanged( $option_name, $current );
	}

	/**
	 * Atomically extend a lock still owned by the caller.
	 *
	 * @param string $option_name Lock option name.
	 * @param string $token       Ownership token.
	 * @param int    $ttl_seconds New lifetime.
	 * @return bool Whether ownership was renewed.
	 */
	public static function refresh( string $option_name, string $token, int $ttl_seconds ): bool {
		if ( '' === $token ) {
			return false;
		}

		$current = get_option( $option_name, array() );

		if ( ! is_array( $current ) || ! hash_equals( $token, (string) ( $current['token'] ?? '' ) ) ) {
			return false;
		}

		$replacement               = $current;
		$replacement['expires_at'] = max(
			absint( $current['expires_at'] ?? 0 ) + 1,
			time() + max( 1, $ttl_seconds )
		);

		return self::replace_if_unchanged( $option_name, $current, $replacement );
	}

	/**
	 * Atomically delete the exact lock value that was inspected.
	 *
	 * Production WordPress always exposes wpdb. The strict fallback exists for
	 * lightweight unit-test environments only.
	 *
	 * @param string $option_name Lock option name.
	 * @param mixed  $expected    Exact expected option value.
	 * @return bool Whether that exact value was removed.
	 */
	private static function delete_if_unchanged( string $option_name, $expected ): bool {
		global $wpdb;

		if ( isset( $wpdb ) && $wpdb instanceof \wpdb ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic compare-and-delete is required to avoid deleting a replacement lock.
			$deleted = $wpdb->delete(
				$wpdb->options,
				array(
					'option_name'  => $option_name,
					'option_value' => maybe_serialize( $expected ),
				),
				array( '%s', '%s' )
			);

			if ( 1 !== $deleted ) {
				return false;
			}

			wp_cache_delete( $option_name, 'options' );

			return true;
		}

		if ( get_option( $option_name, null ) !== $expected ) {
			return false;
		}

		return delete_option( $option_name );
	}

	/**
	 * Atomically replace the exact lock value that was inspected.
	 *
	 * @param string $option_name Lock option name.
	 * @param mixed  $expected    Exact expected option value.
	 * @param array  $replacement Replacement lock value.
	 * @return bool Whether that exact value was replaced.
	 */
	private static function replace_if_unchanged( string $option_name, $expected, array $replacement ): bool {
		global $wpdb;

		if ( isset( $wpdb ) && $wpdb instanceof \wpdb ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic compare-and-swap is required to prevent expired owners from overwriting successor work.
			$updated = $wpdb->update(
				$wpdb->options,
				array(
					'option_value' => maybe_serialize( $replacement ),
				),
				array(
					'option_name'  => $option_name,
					'option_value' => maybe_serialize( $expected ),
				),
				array( '%s' ),
				array( '%s', '%s' )
			);

			if ( 1 !== $updated ) {
				return false;
			}

			wp_cache_delete( $option_name, 'options' );

			return true;
		}

		if ( get_option( $option_name, null ) !== $expected ) {
			return false;
		}

		update_option( $option_name, $replacement, false );

		return get_option( $option_name, null ) === $replacement;
	}
}
