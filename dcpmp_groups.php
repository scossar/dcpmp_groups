<?php
/**
 * Plugin Name: DCPMP Groups
 * Version: 0.1
 * Author: scossar
 */

namespace DCPMP;

use WPDiscourse\Utilities\Utilities as DiscourseUtilities;

add_action( 'init', __NAMESPACE__ . '\\init' );

/**
 * Initializes the plugin.
 *
 * Only sets up the hooks if the WP Discourse plugin is loaded.
 */
function init() {
	if ( class_exists( 'WPDiscourse\Discourse\Discourse' ) ) {
		add_action( 'pmpro_added_order', __NAMESPACE__ . '\\member_added' );
		add_action( 'pmpro_after_change_membership_level', __NAMESPACE__ . '\\member_removed', 10, 3 );
	}
}

/**
 * Gets the level name / Discourse group name for a PMP membership level.
 *
 * @param int $id The level id.
 *
 * @return mixed|\WP_Error
 */
function get_level_for_id( $id ) {
	$levels_to_discourse_groups = array(
		// Replace this with your membership level's ID and the Discourse group name you want to add members to.
		1 => 'walking',
	);

	if ( empty( $levels_to_discourse_groups[ $id ] ) ) {

		return new \WP_Error( 'pmpdc_group_not_set_error', 'A Discourse group has not been assigned to the level.' );
	}

	return $levels_to_discourse_groups[ $id ];
}

/**
 * Adds a member to a Discourse group.
 *
 * If the member was previously suspended, they will be unsuspended.
 *
 * @param object $member_order The PMP membership order.
 *
 * @return array|mixed|object|\WP_Error
 */
function member_added( $member_order ) {
	if ( ! empty( $member_order->membership_id ) && ! empty( $member_order->user_id ) ) {
		$user_id = $member_order->user_id;
		$group_name = get_level_for_id( $member_order->membership_id );
		if ( is_wp_error( $group_name ) ) {

			return new \WP_Error( 'dcpmp_group_not_found', 'There is no Discourse group for the corresponding membership level.' );
		}

		// Ignore the return value of this, if the user isn't suspended, it will have returned an error.
		unsuspend_user( $user_id );

		// Adds the user to the Discourse group. It's unfortunate to be looking up the user's Discourse id
		// again, but the add_user_to_discourse_group function takes the WordPress id.
		$result = DiscourseUtilities::add_user_to_discourse_group( $user_id, $group_name );

		if ( ! empty( $result->success ) ) {
			// If the user has been added to the group, add a metadata key/value pair that can be used later.
			add_user_meta( $user_id, "dcpmp_group_{$group_name}", 1, true );
		}

		return $result;
	}

	return new \WP_Error( 'dcpmp_order_not_set_error', 'There was an error with the PMP order.' );
}

/**
 * Removes a member from a Discourse group after a PMP membership has been cancelled.
 *
 * It also suspends the user by calling suspend_user.
 *
 * @param int $level_id The level id, not used.
 * @param int $user_id The id of the user to remove.
 * @param $cancel_level The level that has been cancelled.
 *
 * @return array|mixed|null|object|\WP_Error
 */
function member_removed( $level_id, $user_id, $cancel_level ) {
	if ( ! empty( $cancel_level ) ) {
		$group_name = get_level_for_id( $cancel_level );
		if ( is_wp_error( $group_name ) ) {

			return new \WP_Error( 'dcpmp_group_not_found', 'There is no Discourse group for the corresponding membership level.' );
		}

		// Removes the user.
		$result = DiscourseUtilities::remove_user_from_discourse_group( $user_id, $group_name );
		if ( ! empty( $result->success ) ) {
			// Remove the membership level metadata key.
			delete_user_meta( $user_id, "dcpmp_group_{$group_name}" );
			suspend_user( $user_id );
		}

		return $result;
	}

	return null;
}

/**
 * Suspends a Discourse user for 10 years.
 *
 * @param int $user_id The id of the user to suspend.
 *
 * @return array|mixed|object|\WP_Error
 */
function suspend_user( $user_id ) {
	$discourse_user = DiscourseUtilities::get_discourse_user( $user_id, true );
	if ( ! is_wp_error( $discourse_user ) ) {
		$discourse_user_id = $discourse_user->id;
		$options = DiscourseUtilities::get_options();
		$suspend_url = esc_url_raw( $options['url'] . "/admin/users/{$discourse_user_id}/suspend" );
		$api_key = $options['api-key'];
		$api_username = $options['publish-username'];

		// Set the 'reason' and the 'message' here.
		$response = wp_remote_post(
			$suspend_url, array(
				'method' => 'PUT',
				'body' => array(
					'api_key' => $api_key,
					'api_username' => $api_username,
					'suspend_until' => date( "Y-m-d", strtotime( "+10 years" ) ),
					'reason' => 'Membership expired',
					'message' => 'Your account has expired',
				)
			)
		);

		return $response;
	}

	return $discourse_user;
}

/**
 * Unsuspends a Discourse user.
 *
 * @param int $user_id The id of the user to unsuspend.
 *
 * @return string|\WP_Error
 */
function unsuspend_user( $user_id ) {
	$discourse_user = DiscourseUtilities::get_discourse_user( $user_id, true );

	if ( ! is_wp_error( $discourse_user ) ) {
		$discourse_user_id = $discourse_user->id;
		$suspended = ! empty( $discourse_user->suspended_till );

		if ( $suspended ) {
			$options = DiscourseUtilities::get_options();
			$suspend_url = esc_url_raw( $options['url'] . "/admin/users/{$discourse_user_id}/unsuspend" );
			$api_key = $options['api-key'];
			$api_username = $options['publish-username'];

			$response = wp_remote_post(
				$suspend_url, array(
					'method' => 'PUT',
					'body' => array(
						'api_key' => $api_key,
						'api_username' => $api_username,
					)
				)
			);

			if ( ! DiscourseUtilities::validate( $response ) ) {

				return new \WP_Error( 'dcpmp_response_error', 'The user could not be unsuspended' );
			}

			// The user was unsuspended.
			return 'unsuspended';
		}

		return 'no_action_taken';
	}

	return new \WP_Error( 'dcpmp_response_error', 'The user could not be retrieved from Discourse.' );
}
