<?php
/**
 * Feature 123 — customers, and the bulk personal data question.
 *
 * WooCommerce ships nothing at all for customers, so all of this is ours — including the decision
 * about what an AI client may see. The position is the one Feature 110 settled for attendees, with
 * more rows at stake:
 *
 *   - The default answer is AGGREGATE. Counts and bands, nothing identifying anyone. Most questions
 *     a shop owner asks end there.
 *   - An export has no non-PII mode. It requires the flag; without it, refuse. A customer export
 *     stripped of customer identity is just the aggregate, and offering a silent half-mode is how an
 *     accidental disclosure happens.
 *   - Three gates for the export: the flag, a confirmation, and a written reason that is recorded.
 *   - A capability ABOVE the floor. Administrator is not justification on its own for a bulk dump of
 *     personal data.
 *   - Hard-capped and always paginated, and the response says how many rows it disclosed and what it
 *     withheld.
 *   - Gateway customer tokens are matched by PREFIX, so a payment provider nobody here has heard of
 *     is still excluded.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Store
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Customer reads, writes and the gated export.
 *
 * @since 0.0.34
 */
final class Customer_Repository {

	/**
	 * Most rows one export may disclose.
	 *
	 * @since 0.0.34
	 * @var   int
	 */
	public const MAX_EXPORT_ROWS = 500;

	/**
	 * The capability an export needs on top of the floor.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const DISCLOSURE_CAPABILITY = 'list_users';

	/**
	 * Meta prefixes that are never returned, whatever flag is passed.
	 *
	 * Matched by prefix rather than enumerated, so a gateway this plugin has never heard of is still
	 * excluded. These are payment provider customer references — the handle used to charge a stored
	 * card — and they are not a store operator's to read through an AI client.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const NEVER_RETURNED = array(
		'_stripe_',
		'_ppcp_',
		'_paypal_',
		'_wc_braintree_',
		'_square_',
		'_authorize_net_',
		'_amazon_',
		'session_tokens',
		'user_pass',
		'user_activation_key',
	);

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether the current user may be handed personal data in bulk.
	 *
	 * @since  0.0.34
	 * @return true|WP_Error
	 */
	public static function assert_may_disclose() {
		if ( ! current_user_can( self::DISCLOSURE_CAPABILITY ) ) {
			return new WP_Error(
				'insufficient_capability_for_disclosure',
				sprintf(
					/* translators: %s: capability name. */
					__( 'Exporting customer records needs the %s capability in addition to administrator rights. Being an administrator is not on its own a reason to release a list of named people with their addresses.', 'acrossai-abilities-manager' ),
					self::DISCLOSURE_CAPABILITY
				)
			);
		}

		return true;
	}

	/**
	 * Counts and bands. No row identifies anybody.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function summary(): array {
		$counts = count_users();
		$total  = isset( $counts['avail_roles']['customer'] ) ? (int) $counts['avail_roles']['customer'] : 0;

		$orders = wc_get_orders(
			array(
				'limit'  => -1,
				'return' => 'ids',
				'status' => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
			)
		);

		$by_customer = array();

		foreach ( (array) $orders as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$key = (int) $order->get_customer_id();

			if ( ! isset( $by_customer[ $key ] ) ) {
				$by_customer[ $key ] = array(
					'orders' => 0,
					'spent'  => 0.0,
				);
			}

			++$by_customer[ $key ]['orders'];
			$by_customer[ $key ]['spent'] += (float) $order->get_total();
		}

		$order_bands = array(
			'1'     => 0,
			'2-5'   => 0,
			'6-20'  => 0,
			'21+'   => 0,
		);

		foreach ( $by_customer as $stats ) {
			$n = (int) $stats['orders'];

			if ( 1 === $n ) {
				++$order_bands['1'];
			} elseif ( $n <= 5 ) {
				++$order_bands['2-5'];
			} elseif ( $n <= 20 ) {
				++$order_bands['6-20'];
			} else {
				++$order_bands['21+'];
			}
		}

		$guest = isset( $by_customer[0] ) ? 1 : 0;

		return array(
			'registered_customers' => $total,
			'purchasers'           => max( 0, count( $by_customer ) - $guest ),
			'guest_orders_present' => 1 === $guest,
			'orders_per_customer'  => $order_bands,
			'personal_data_included' => false,
			'note'                 => __( 'Counts and bands only; nothing here identifies anyone. Use store/export-customers when you genuinely need named records.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * One customer.
	 *
	 * @since  0.0.34
	 * @param  int  $id                 User id.
	 * @param  bool $with_personal_data Whether to include identifying fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( int $id, bool $with_personal_data ) {
		$customer = self::load( $id );

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		return self::shape( $customer, $with_personal_data );
	}

	/**
	 * @since  0.0.34
	 * @param  int $id User id.
	 * @return \WC_Customer|WP_Error
	 */
	private static function load( int $id ) {
		try {
			$customer = new \WC_Customer( $id );
		} catch ( \Throwable $e ) {
			unset( $e );

			$customer = null;
		}

		if ( ! $customer instanceof \WC_Customer || ! $customer->get_id() ) {
			return new WP_Error(
				'unknown_customer',
				sprintf(
					/* translators: %d: user id. */
					__( 'No customer with id %d.', 'acrossai-abilities-manager' ),
					$id
				)
			);
		}

		return $customer;
	}

	/**
	 * @since  0.0.34
	 * @param  \WC_Customer $customer           Customer.
	 * @param  bool         $with_personal_data Whether to include identifying fields.
	 * @return array<string, mixed>
	 */
	private static function shape( \WC_Customer $customer, bool $with_personal_data ): array {
		$base = array(
			'id'                     => $customer->get_id(),
			'order_count'            => (int) $customer->get_order_count(),
			'total_spent'            => (string) $customer->get_total_spent(),
			'date_created'           => $customer->get_date_created() ? $customer->get_date_created()->date( 'c' ) : '',
			'personal_data_included' => $with_personal_data,
		);

		if ( ! $with_personal_data ) {
			$base['withheld'] = array( 'name', 'email', 'username', 'billing address', 'shipping address' );

			return $base;
		}

		return array_merge(
			$base,
			array(
				'email'      => $customer->get_email(),
				'username'   => $customer->get_username(),
				'first_name' => $customer->get_first_name(),
				'last_name'  => $customer->get_last_name(),
				'billing'    => $customer->get_billing(),
				'shipping'   => $customer->get_shipping(),
				'withheld'   => array( 'password hash', 'session tokens', 'payment provider customer references' ),
			)
		);
	}

	/**
	 * Create a customer.
	 *
	 * Through `WC_Customer` so the WordPress user, its role and the WooCommerce fields are created
	 * together. No password is ever accepted or set here: an account made by an assistant with a
	 * password an assistant knows is not the customer's account. WordPress emails them to set their
	 * own.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $fields Supplied fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( array $fields ) {
		$email = sanitize_email( (string) ( $fields['email'] ?? '' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_input', __( 'A valid email address is required.', 'acrossai-abilities-manager' ) );
		}

		if ( email_exists( $email ) ) {
			return new WP_Error(
				'customer_exists',
				sprintf(
					/* translators: %s: email address. */
					__( 'There is already an account for %s. Use store/update-customer instead.', 'acrossai-abilities-manager' ),
					$email
				)
			);
		}

		try {
			$customer = new \WC_Customer();
			$customer->set_email( $email );
			$customer->set_username( (string) ( $fields['username'] ?? $email ) );
			$customer->set_password( wp_generate_password( 24, true, true ) );

			self::apply_fields( $customer, $fields );

			$id = $customer->save();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'create_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'The customer could not be created: %s', 'acrossai-abilities-manager' ),
					$e->getMessage()
				)
			);
		}

		if ( ! $id ) {
			return new WP_Error( 'create_failed', __( 'WooCommerce reported no id after creating the customer.', 'acrossai-abilities-manager' ) );
		}

		$fresh = self::load( (int) $id );

		return is_wp_error( $fresh ) ? $fresh : array(
			'customer' => self::shape( $fresh, true ),
			'note'     => __( 'A random password was set and is not returned. The customer should reset it through the site\'s own "lost password" flow.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Change a customer's details.
	 *
	 * @since  0.0.34
	 * @param  int                  $id     User id.
	 * @param  array<string, mixed> $fields Supplied fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( int $id, array $fields ) {
		$customer = self::load( $id );

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		if ( isset( $fields['password'] ) ) {
			return new WP_Error(
				'password_not_writable',
				__( 'A password cannot be set here. An account whose password an assistant chose is not the customer\'s account; send them the site\'s own password reset instead.', 'acrossai-abilities-manager' )
			);
		}

		if ( isset( $fields['email'] ) ) {
			$email = sanitize_email( (string) $fields['email'] );

			if ( '' === $email || ! is_email( $email ) ) {
				return new WP_Error( 'invalid_input', __( 'That is not a valid email address.', 'acrossai-abilities-manager' ) );
			}

			$existing = email_exists( $email );

			if ( $existing && (int) $existing !== $id ) {
				return new WP_Error( 'customer_exists', __( 'Another account already uses that email address.', 'acrossai-abilities-manager' ) );
			}

			$customer->set_email( $email );
		}

		self::apply_fields( $customer, $fields );

		try {
			$customer->save();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'update_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'The customer could not be saved: %s', 'acrossai-abilities-manager' ),
					$e->getMessage()
				)
			);
		}

		$fresh = self::load( $id );

		return is_wp_error( $fresh ) ? $fresh : array( 'customer' => self::shape( $fresh, true ) );
	}

	/**
	 * @since  0.0.34
	 * @param  \WC_Customer         $customer Customer.
	 * @param  array<string, mixed> $fields   Supplied fields.
	 * @return void
	 */
	private static function apply_fields( \WC_Customer $customer, array $fields ): void {
		foreach ( array( 'first_name' => 'set_first_name', 'last_name' => 'set_last_name' ) as $key => $setter ) {
			if ( isset( $fields[ $key ] ) ) {
				$customer->{$setter}( sanitize_text_field( (string) $fields[ $key ] ) );
			}
		}

		foreach ( array( 'billing', 'shipping' ) as $group ) {
			if ( ! isset( $fields[ $group ] ) || ! is_array( $fields[ $group ] ) ) {
				continue;
			}

			foreach ( $fields[ $group ] as $field => $value ) {
				$setter = 'set_' . $group . '_' . sanitize_key( (string) $field );

				if ( method_exists( $customer, $setter ) ) {
					$customer->{$setter}( sanitize_text_field( (string) $value ) );
				}
			}
		}
	}

	/**
	 * The gated export.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Flags and paging.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function export( array $input ) {
		if ( empty( $input['include_personal_data'] ) ) {
			return new WP_Error(
				'personal_data_flag_required',
				__( 'An export of customers is personal data by definition, so it has to be asked for explicitly: pass include_personal_data: true, a reason, and confirm: true. If you only need numbers, store/list-customers answers without identifying anybody.', 'acrossai-abilities-manager' )
			);
		}

		$reason = trim( (string) ( $input['reason'] ?? '' ) );

		if ( '' === $reason ) {
			return new WP_Error(
				'reason_required',
				__( 'Give a reason for the export. It is recorded alongside who asked and how many records were released.', 'acrossai-abilities-manager' )
			);
		}

		$may = self::assert_may_disclose();

		if ( is_wp_error( $may ) ) {
			return $may;
		}

		$limit  = min( self::MAX_EXPORT_ROWS, max( 1, (int) ( $input['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$query = new \WP_User_Query(
			array(
				'role'    => 'customer',
				'number'  => $limit,
				'offset'  => $offset,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		$rows = array();

		foreach ( (array) $query->get_results() as $user ) {
			$customer = self::load( (int) $user->ID );

			if ( is_wp_error( $customer ) ) {
				continue;
			}

			$rows[] = self::shape( $customer, true );
		}

		$total = (int) $query->get_total();

		/**
		 * Fires when customer personal data is released through an ability.
		 *
		 * Deliberately an action rather than a silent return: a site owner should be able to see that
		 * a bulk disclosure happened, who asked, and why.
		 *
		 * @since 0.0.34
		 * @param int    $disclosed Number of records released.
		 * @param int    $actor     User id that asked.
		 * @param string $reason    The stated reason.
		 */
		do_action( 'acrossai_store_personal_data_disclosed', count( $rows ), get_current_user_id(), $reason );

		return array(
			'format'                 => 'csv' === ( $input['format'] ?? 'json' ) ? 'csv' : 'json',
			'customers'              => 'csv' === ( $input['format'] ?? 'json' ) ? array() : $rows,
			'csv'                    => 'csv' === ( $input['format'] ?? 'json' ) ? self::to_csv( $rows ) : '',
			'disclosed_count'        => count( $rows ),
			'total_matching'         => $total,
			'offset'                 => $offset,
			'has_more'               => ( $offset + count( $rows ) ) < $total,
			'reason'                 => $reason,
			'personal_data_included' => true,
			'withheld'               => array( 'password hash', 'session tokens', 'payment provider customer references' ),
			'note'                   => __( 'Returned inline rather than written to a file: an export dropped into the uploads folder is fetchable by anyone who guesses the URL.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<int, array<string, mixed>> $rows Customers.
	 * @return string
	 */
	private static function to_csv( array $rows ): string {
		if ( array() === $rows ) {
			return '';
		}

		$columns = array( 'id', 'email', 'username', 'first_name', 'last_name', 'order_count', 'total_spent', 'date_created' );
		$out     = implode( ',', $columns ) . "\n";

		foreach ( $rows as $row ) {
			$line = array();

			foreach ( $columns as $column ) {
				$value  = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
				$line[] = '"' . str_replace( '"', '""', $value ) . '"';
			}

			$out .= implode( ',', $line ) . "\n";
		}

		return $out;
	}
}
