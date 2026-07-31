<?php
/**
 * Monthly AI generation usage tracking.
 *
 * @package WebscribeAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WebscribeAI_Usage
 */
class WebscribeAI_Usage {

	/**
	 * Option name for usage tracking.
	 */
	const OPTION_NAME = 'webscribeai_usage';

	/**
	 * Maximum AI generations allowed per calendar month.
	 */
	const MONTHLY_LIMIT = 500;

	/**
	 * Get the current month's usage count, resetting first if the stored
	 * count is from a previous month.
	 *
	 * @return int
	 */
	public static function get_count(): int {
		return self::get_data()['count'];
	}

	/**
	 * Get the monthly generation limit.
	 *
	 * @return int
	 */
	public static function get_limit(): int {
		return self::MONTHLY_LIMIT;
	}

	/**
	 * Whether another generation can be performed this month.
	 *
	 * @return bool
	 */
	public static function has_remaining(): bool {
		return self::get_count() < self::MONTHLY_LIMIT;
	}

	/**
	 * Record one AI generation against the current month's count.
	 */
	public static function increment(): void {
		$data          = self::get_data();
		$data['count'] = $data['count'] + 1;

		update_option( self::OPTION_NAME, $data, false );
	}

	/**
	 * Read the stored usage data, resetting the count if the stored month
	 * doesn't match the current month.
	 *
	 * @return array{month: string, count: int}
	 */
	private static function get_data(): array {
		$data  = (array) get_option( self::OPTION_NAME, [] );
		$month = current_time( 'Y-m' );

		if ( ( $data['month'] ?? '' ) !== $month ) {
			$data = [
				'month' => $month,
				'count' => 0,
			];
			update_option( self::OPTION_NAME, $data, false );
		}

		return [
			'month' => $data['month'],
			'count' => (int) $data['count'],
		];
	}
}
