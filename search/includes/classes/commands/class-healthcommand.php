<?php

namespace Automattic\VIP\Search\Commands;

use \WP_CLI;
use WP_Error;

require_once __DIR__ . '/../../../../vip-helpers/vip-wp-cli.php';
require_once __DIR__ . '/../class-health.php';

/**
 * Commands to view and manage the health of VIP Search indexes
 *
 * @package Automattic\VIP\Search
 */
class HealthCommand extends \WPCOM_VIP_CLI_Command {
	private const SUCCESS_ICON = "\u{2705}"; // unicode check mark
	private const FAILURE_ICON = "\u{274C}"; // unicode cross mark
	private const INFO_ICON    = "\u{1F7E7}"; // unicode info mark

	public function __construct() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		parent::__construct();
	}

	/**
	 * Stop an ongoing validate-contents from continuing to be run.
	 *
	 * @subcommand stop-validate-contents
	 */
	public function stop_validate_contents( $args, $assoc_args ) {
		$health = new \Automattic\VIP\Search\Health( \Automattic\VIP\Search\Search::instance() );

		if ( ! $health->is_validate_content_ongoing() ) {
			WP_CLI::error( 'There is no validate-contents run ongoing' );
		}

		$stop = wp_cache_add( $health::STOP_VALIDATE_CONTENTS_KEY, true, $health::CACHE_GROUP );
		if ( $stop ) {
			WP_CLI::success( 'Attempting to abort validate-contents run...' );
		} else {
			WP_CLI::error( 'Failed to abort validate-contents run! There is already a request to stop it.' );
		}
	}

	/**
	 * Get information on rate limiting.
	 *
	 * ## OPTIONS
	 *
	 *[--format=<format>]
	 * : Accepts 'table', 'json', 'csv', or 'yaml'. Default: table
	 *
	 * @subcommand rate-limits [--format=<format>]
	 */
	public function rate_limits( $args, $assoc_args ) {
		$format = $assoc_args['format'] ?? 'table';
		if ( ! in_array( $format, [ 'table', 'json', 'csv', 'yaml' ], true ) ) {
			WP_CLI::error( __( '--format only accepts the following values: table, json, csv, yaml' ) );
		}

		$search = \Automattic\VIP\Search\Search::instance();

		$search_rate_limited   = $search::is_rate_limited();
		$indexing_rate_limited = $search->queue->is_indexing_ratelimited();

		$is_rate_limited = $search_rate_limited || $indexing_rate_limited;
		if ( ! $is_rate_limited ) {
			WP_CLI::success( 'No rate limiting found!' );
		} else {
			$rate_limit = [];
			if ( $search_rate_limited ) {
				$search_start_time = $search::get_query_rate_limit_start();

				$rate_limit[] = [
					'type'                => 'search',
					'start_time'          => $search_start_time,
					'readable_start_time' => human_time_diff( $search_start_time ) . ' ago',
					'info'                => sprintf( '(%d of %d)', $search::get_query_count(), $search::$max_query_count ),
				];
			}

			if ( $indexing_rate_limited ) {
				$indexing_start_time = $search->queue::get_indexing_rate_limit_start();

				$rate_limit[] = [
					'type'                => 'indexing',
					'start_time'          => $indexing_start_time,
					'readable_start_time' => human_time_diff( $indexing_start_time ) . ' ago',
					'info'                => 'n/a',
				];
			}

			WP_CLI\Utils\format_items( $format, $rate_limit, [ 'type', 'start_time', 'readable_start_time', 'info' ] );
		}
	}

	/**
	 * Validate DB and ES index counts for all objects for active indexables
	 *
	 * ## OPTIONS
	 *
	 *
	 * ## EXAMPLES
	 *     wp vip-search health validate-counts
	 *
	 * @subcommand validate-counts
	 */
	public function validate_counts( $args, $assoc_args ) {
		foreach ( \ElasticPress\Indexables::factory()->get_all( null, true ) as $indexable_slug ) {
			$this->validate_indexable_count( $indexable_slug, $assoc_args );
			WP_CLI::line( '' );
		}
	}

	/**
	 * ## OPTIONS
	 *
	 * [--version=<int>]
	 * : Index version to validate - defaults to all
	 *
	 * [--network-wide]
	 * : Validate all sites in a multisite network
	 *
	 * ## EXAMPLES
	 *     wp vip-search health validate-users-count
	 *
	 * @subcommand validate-users-count
	 */
	public function validate_users_count( $args, $assoc_args ) {
		$this->validate_indexable_count( 'user', $assoc_args );
	}

	/**
	 * ## OPTIONS
	 *
	 * [--version=<int>]
	 * : Index version to validate - defaults to all
	 *
	 * [--network-wide]
	 * : Validate all sites in a multisite network
	 *
	 * ## EXAMPLES
	 *     wp vip-search health validate-posts-count
	 *
	 * @subcommand validate-posts-count
	 */
	public function validate_posts_count( $args, $assoc_args ) {
		$this->validate_indexable_count( 'post', $assoc_args );
	}

	/**
	 * ## OPTIONS
	 *
	 * [--version=<int>]
	 * : Index version to validate - defaults to all
	 *
	 * [--network-wide]
	 * : Validate all sites in a multisite network
	 *
	 * ## EXAMPLES
	 *     wp vip-search health validate-terms-count
	 *
	 * @subcommand validate-terms-count
	 */
	public function validate_terms_count( $args, $assoc_args ) {
		$this->validate_indexable_count( 'term', $assoc_args );
	}

		/**
	 * ## OPTIONS
	 *
	 * [--version=<int>]
	 * : Index version to validate - defaults to all
	 *
	 * [--network-wide]
	 * : Validate all sites in a multisite network
	 *
	 * ## EXAMPLES
	 *     wp vip-search health validate-comments-count
	 *
	 * @subcommand validate-comments-count
	 */
	public function validate_comments_count( $args, $assoc_args ) {
		$this->validate_indexable_count( 'comment', $assoc_args );
	}

	/**
	 * Generic internal function to validate counts on any indexable,
	 * supporting multisite installations
	 *
	 * @param string $indexable_slug Slug of the indexable to validate
	 * @param array $assoc_args CLI arguments
	 */
	private function validate_indexable_count( $indexable_slug, $assoc_args ) {
		$indexable = \ElasticPress\Indexables::factory()->get( $indexable_slug );
		if ( ! $indexable ) {
			WP_CLI::line( "Cannot find indexable '$indexable_slug', probably the feature is not enabled\n" );
			return;
		}

		if ( isset( $assoc_args['version'] ) ) {
			$version = intval( $assoc_args['version'] );
		} else {
			$version = null;
		}

		if ( isset( $assoc_args['network-wide'] ) && is_multisite() ) {
			if ( isset( $version ) ) {
				WP_CLI::error( 'The --network-wide argument is not compatible with --version when not using network mode (the `EP_IS_NETWORK` constant), as subsites  can have differing index versions' );
				return;
			}

			$sites = \ElasticPress\Utils\get_sites();

			foreach ( $sites as $site ) {
				if ( ! \ElasticPress\Utils\is_site_indexable( $site['blog_id'] ) ) {
					WP_CLI::line( 'Skipping site ' . $site['blog_id'] . ' as it\'s not indexable\n\n' );
					continue;
				}

				switch_to_blog( $site['blog_id'] );

				if ( ! $indexable->index_exists( $site['blog_id'] ) ) {
					$blog_id = $site['blog_id'];
					WP_CLI::line( "Skipping validation of '$indexable_slug' index for site $blog_id as it doesn't exist.\n\n" );
					continue;
				}

				WP_CLI::line( "\nValidating $indexable_slug count for site " . $site['blog_id'] . ' (' . $site['domain'] . $site['path'] . ')\n' );

				$this->validate_indexable_count_for_site( $indexable_slug, $version );

				restore_current_blog();
			}
		} else {
			if ( ! $indexable->index_exists() ) {
				WP_CLI::line( "Skipping validation of '$indexable_slug' index as it doesn't exist.\n" );
				return;
			}

			WP_CLI::line( "Validating $indexable_slug count\n" );

			$this->validate_indexable_count_for_site( $indexable_slug, $version );
		}
	}

	/**
	 * Validate counts for an indexable on a single site
	 *
	 * @param string $indexable_slug Slug of the indexable to validate
	 * @param int $version Validate only a specific version instead of all of them
	 */
	private function validate_indexable_count_for_site( $indexable_slug, $version = null ) {
		$search = \Automattic\VIP\Search\Search::instance();

		$versions = [];
		$results  = [];

		if ( isset( $version ) ) {
			$versions[] = $version;
		} else {
			// Defaults to all versions
			$indexable = \ElasticPress\Indexables::factory()->get( $indexable_slug );

			if ( ! $indexable ) {
				WP_CLI::line( "Cannot find indexable '$indexable_slug', probably the feature is not enabled" );
				return;
			}

			$version_objects = $search->versioning->get_versions( $indexable );

			$versions = wp_list_pluck( $version_objects, 'number' );
		}

		foreach ( $versions as $version_number ) {
			switch ( $indexable_slug ) {
				case 'post':
					$results = \Automattic\VIP\Search\Health::validate_index_posts_count( array(
						'index_version' => $version_number,
					) );
					break;
				case 'user':
					$results = \Automattic\VIP\Search\Health::validate_index_users_count( array(
						'index_version' => $version_number,
					) );
					break;
				case 'term':
					$results = \Automattic\VIP\Search\Health::validate_index_terms_count( array(
						'index_version' => $version_number,
					) );
					break;
				case 'comment':
					$results = \Automattic\VIP\Search\Health::validate_index_comments_count( array(
						'index_version' => $version_number,
					) );
					break;
			}

			if ( is_wp_error( $results ) ) {
				/** @var WP_Error $results */
				WP_CLI::error( $results->get_error_message() );
				return;
			}

			$this->render_results( $results );
		}
	}

	/**
	 * Helper function to parse and render results of index verification functions
	 *
	 * @param array $results Array of results generated by index verification functions
	 */
	private function render_results( array $results ) {
		foreach ( $results as $result ) {
			// If it's an error, print out a warning and go to the next iteration
			if ( array_key_exists( 'error', $result ) ) {
				WP_CLI::warning( 'Error while validating count: ' . $result['error'] );
				continue;
			}

			$identification = sprintf( 'entity: %s, type: %s, index_version: %d', $result['entity'], $result['type'], $result['index_version'] );

			if ( $result['skipped'] ) {
				$reason_message = 'index-not-found' === $result['reason'] ? 'index was not found in ES' : 'there are no documents in ES';
				$message        = sprintf( '%s skipping, because %s when counting %s', self::INFO_ICON, $reason_message, $identification );
			} else {
				$message = ' inconsistencies found';
				if ( $result['diff'] ) {
					$icon = self::FAILURE_ICON;
				} else {
					$icon    = self::SUCCESS_ICON;
					$message = 'no' . $message;
				}

				$message = sprintf( '%s %s when counting %s - (DB: %s, ES: %s, Diff: %s)', $icon, $message, $identification, $result['db_total'], $result['es_total'], $result['diff'] );
			}

			WP_CLI::line( $message );
		}
	}

	/**
	 * Validate DB and ES index contents for all objects.
	 *
	 * ## OPTIONS
	 *
	 * [--inspect]
	 * : Whether to give more verbose output for index inconsistencies.
	 *
	 * [--start_post_id=<int>]
	 * : Starting post id to check.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--last_post_id=<int>]
	 * : Last post id to check.
	 *
	 * [--batch_size=<int>]
	 * : Batch size.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--max_diff_size=<int>]
	 * : Max count of objects before exiting.
	 * ---
	 * default: 1000
	 * ---
	 *
	 * [--format=<string>]
	 * : Format of output display.
	 * ---
	 * default: csv
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * [--do_not_heal]
	 * : Whether to not to correct inconsistencies.
	 *
	 * [--silent]
	 * : Whether to silence all non-error output except for the final results.
	 *
	 * [--force_parallel_execution]
	 * : Whether to force execution even if the process is already ongoing.
	 *
	 * [--mode=<string>]
	 * : Mode to target validation to a specific subset of index inconsistencies.
	 * ---
	 * options:
	 *   - missing
	 *   - mismatch
	 *
	 * ## EXAMPLES
	 *
	 *     # Simultaneously check for and fix any index inconsistencies (e.g. missing posts and content mismatches) between DB and ES.
	 *     $ wp vip-search health validate-contents
	 *     ...✘
	 *     Warning: Inconsistencies fixed:
	 *     type,id,issue
	 *     post,5,missing_from_index
	 *     post,1,mismatch
	 *
	 * @link https://docs.wpvip.com/how-tos/vip-search/check-index-health/
	 *
	 * @subcommand validate-contents
	 */
	public function validate_contents( $args, $assoc_args ) {
		$health = new \Automattic\VIP\Search\Health( \Automattic\VIP\Search\Search::instance() );

		$results = $health->validate_index_posts_content( $assoc_args );

		if ( is_wp_error( $results ) ) {
			if ( $results->get_error_code() === 'es_validate_content_aborted' ) {
				WP_CLI::error( $results->get_error_message() );
			}

			$diff = $results->get_error_data( 'diff' );

			if ( ! empty( $diff ) ) {
				$this->render_contents_diff( $diff, $assoc_args['format'], $assoc_args['max_diff_size'] );
			}

			$message = $results->get_error_message();
			if ( 'es_content_validation_already_ongoing' === $results->get_error_code() ) {
				$message .= "\n\nYou can use --force_parallel_execution to run the command even with the lock in place";
			}
			WP_CLI::error( $message );
		}

		if ( empty( $results ) ) {

			if ( ! isset( $assoc_args['silent'] ) ) {
				WP_CLI::success( 'No inconsistencies found!' );
			}

			exit();
		}

		if ( ! isset( $assoc_args['silent'] ) ) {
			$message = 'Inconsistencies ' . ( ! isset( $assoc_args['do_not_heal'] ) ? 'fixed' : 'found' ) . ':';
			// Not empty, so inconsistencies were found...
			WP_CLI::warning( $message );
		}

		$this->render_contents_diff( $results, $assoc_args['format'], $assoc_args['max_diff_size'], isset( $assoc_args['silent'] ) );
	}

	private function render_contents_diff( $diff, $format, $max_diff_size, $silent = false ) {
		if ( ! is_array( $diff ) || empty( $diff ) || 0 >= $max_diff_size ) {
			return;
		}

		if ( ! in_array( $format, array( 'table', 'json', 'csv', 'yaml', 'ids', 'count' ) ) ) {
			$format = 'csv';
		}

		$max_diff_size = intval( $max_diff_size );

		$truncate_msg = '';
		if ( count( $diff ) > $max_diff_size ) {
			$truncate_msg = sprintf( 'Truncated diff processing at %d out of %d since max_diff_size is %d', $max_diff_size, count( $diff ), $max_diff_size );
			$diff         = array_slice( $diff, 0, $max_diff_size, true );
		}

		// Array pop without modifying the diff array
		$d = $this->get_last( $diff );

		if ( array_key_exists( 'type', $d ) && array_key_exists( 'id', $d ) && array_key_exists( 'issue', $d ) ) {
			\WP_CLI\Utils\format_items( $format, $diff, array( 'type', 'id', 'issue' ) );
		} else {
			WP_CLI::warning( 'Formatting is being ignored!' );
			foreach ( $diff as $d ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
				var_dump( $d );
			}
		}

		if ( ! empty( $truncate_msg ) && ! $silent ) {
			WP_CLI::warning( $truncate_msg );
		}
	}

	private function get_last( $array ) {
		return end( $array );
	}
}
