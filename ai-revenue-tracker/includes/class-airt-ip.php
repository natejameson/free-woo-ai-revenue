<?php
/**
 * IP range helpers used to verify that a bot really comes from its owner.
 *
 * @package AIRevenueTracker
 */

defined( 'ABSPATH' ) || exit;

final class AIRT_IP {

	/**
	 * @param string $ip   IPv4 or IPv6 address.
	 * @param string $cidr Network in CIDR notation (a bare address matches exactly).
	 * @return bool
	 */
	public static function in_cidr( $ip, $cidr ) {
		$ip_bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $ip_bin ) {
			return false;
		}

		$cidr = trim( (string) $cidr );
		if ( false === strpos( $cidr, '/' ) ) {
			$net  = $cidr;
			$bits = strlen( $ip_bin ) * 8;
		} else {
			list( $net, $bits ) = explode( '/', $cidr, 2 );
			if ( ! ctype_digit( $bits ) ) {
				return false;
			}
			$bits = (int) $bits;
		}

		$net_bin = @inet_pton( trim( $net ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $net_bin || strlen( $net_bin ) !== strlen( $ip_bin ) ) {
			return false;
		}

		$max = strlen( $ip_bin ) * 8;
		if ( $bits < 0 || $bits > $max ) {
			return false;
		}

		$bytes = intdiv( $bits, 8 );
		$rem   = $bits % 8;

		if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
			return false;
		}
		if ( $rem > 0 ) {
			$mask = ( 0xff << ( 8 - $rem ) ) & 0xff;
			if ( ( ord( $ip_bin[ $bytes ] ) & $mask ) !== ( ord( $net_bin[ $bytes ] ) & $mask ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string   $ip    Address.
	 * @param string[] $cidrs Networks.
	 * @return bool
	 */
	public static function in_any( $ip, array $cidrs ) {
		foreach ( $cidrs as $cidr ) {
			if ( self::in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pull every CIDR string out of a decoded IP range file, whatever its shape.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return string[]
	 */
	public static function extract_cidrs( $data ) {
		$found = array();
		if ( is_string( $data ) ) {
			$value = trim( $data );
			if ( preg_match( '#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $value ) || ( false !== strpos( $value, ':' ) && preg_match( '#^[0-9a-f:]+/\d{1,3}$#i', $value ) ) ) {
				$found[] = $value;
			}
		} elseif ( is_array( $data ) ) {
			foreach ( $data as $item ) {
				$found = array_merge( $found, self::extract_cidrs( $item ) );
			}
		}
		return array_values( array_unique( $found ) );
	}
}
