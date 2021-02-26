<?php

/**
 * Plugin Name: Discourse WooCommerce Sync
 * Plugin URI: http://github/paviliondev/discourse-woocommerce-sync
 * Description: Syncs WooCommerce memberships with Discourse groups
 * Version: 0.3.2
 * Author: Angus McLeod
 * Author URI: http://thepavilion.io
 */

defined( 'ABSPATH' ) or die( 'No scripts' );

use WPDiscourse\Utilities\Utilities as DiscourseUtilities;

$member_group_map = array();
$member_group_map[] = (object) array('plan_id' => 61128, 'group_id' => 62);
$member_group_map[] = (object) array('plan_id' => 91699, 'group_id' => 65);

$product_group_map = array();
$product_group_map[] = (object) array('product_ids' => [27680,29439,29449,31904,31926,31928,27679], 'group_id' => 66);

function get_discourse_group_id($plan_id) {
	global $member_group_map;
	$group_id = nil;
		
	foreach($member_group_map as $item) {		
    if ($plan_id == $item->plan_id) {
				$group_id = $item->group_id;
    }
	}
		
	return $group_id;
}

const ACTIVE_STATUSES = array('wcm-active');
const INACTIVE_STATUSES = array('wcm-expired', 'wcm-cancelled');

function determine_plan_group_action($status) {
	$action = null;
	
	if (in_array($status, ACTIVE_STATUSES)) {
		$action = 'PUT';
	} elseif (in_array($status, INACTIVE_STATUSES)) {
		$action = 'DELETE';
	}
	
	return $action;
}

function update_discourse_group_access($user_id, $action, $group_id) {
	$options = DiscourseUtilities::get_options();
	$base_url = $options['url'];
	$api_key = $options['api-key'];
  $api_username = $options['publish-username'];
	$logger = wc_get_logger();

	if ( empty( $base_url ) || empty( $api_key ) || empty( $api_username ) ) {
	  return new \WP_Error( 'discourse_configuration_error', 'The WP Discourse plugin has not been properly configured.' );
	}
	
	$logger->info( sprintf('Updating discourse group access %s %s %s', $user_id, $action, $group_id) );

	$external_url = esc_url_raw( $base_url . "/groups/". $group_id ."/members" );
	
	$user_info = get_userdata($user_id);
	$discourse_user_id = $user_info->user_id;
	$user_email = $user_info->user_email;
	
	$body = array();
	
	if ($discourse_user_id) {
		$body['user_id'] = $discourse_user_id;
	} else {
		$body['user_emails'] = $user_email;
	}
	
	$headers = array(
		'Content-type' => 'application/json',
		'Accept'			 => 'application/json',
		'Api-Key'      => $api_key,
		'Api-Username' => $api_username,
	);
	
	$logger->info( sprintf('Sending %s request to %s with headers %s and body %s', $action, $external_url, json_encode($headers), json_encode($body)) );

	$response = wp_remote_request($external_url,
		array(
    	 'method' 	=> $action,
			 'headers' 	=> $headers,
			 'body'    	=> json_encode($body)
    )
	);

	$logger->info( sprintf( 'Response from Discourse: %s %s' , wp_remote_retrieve_response_code($response), wp_remote_retrieve_response_message($response) ) );

	if ( ! DiscourseUtilities::validate( $response ) ) {
		return new \WP_Error( 'discourse_response_error', 'There has been an error in retrieving the user data from Discourse.' );
	}
};

function handle_wc_membership_saved($membership_plan, $args) {
	$logger = wc_get_logger();
	$logger->info( sprintf('Running handle_wc_membership_saved %s, %s, %s', $args['user_id'], $args['user_membership_id'], $args['is_update'] ) );

	$user_id = $args['user_id'];
	$membership = wc_memberships_get_user_membership($args['user_membership_id']);
	$plan_id = $membership->plan->id;
	
	$group_id = get_discourse_group_id($plan_id);

	if ($membership && $group_id) {
		$action = determine_plan_group_action($membership->status);
	
		update_discourse_group_access($user_id, $action, $group_id);
	}
};

function handle_wc_membership_status_change($user_membership, $old_status, $new_status) {
	$logger = wc_get_logger();
	$logger->info( sprintf('Running handle_wc_membership_status_change %s, %s, %s', json_encode($user_membership), $old_status, $new_status ) );
	return null;
}

add_action('wc_memberships_user_membership_saved', 'handle_wc_membership_saved', 10, 2);

add_action('wc_memberships_user_membership_status_changed', 'handle_wc_membership_status_change', 10, 3);

function full_wc_membership_sync() {
	global $product_group_map;
	$allusers = get_users();
	$logger = wc_get_logger();

	$logger->info( sprintf('Running full_wc_membership_sync') );

	foreach ( $allusers as $user ) {
		$user_id = $user->id;
		
		foreach ( $member_group_map as $item ) {
			$membership = wc_memberships_get_user_membership($user_id, $item->plan_id);
			$plan_id = $membership->plan->id;

			$logger->info( sprintf('Checking membership of %s', $user->user_login) );
			
			$group_id = get_discourse_group_id($plan_id);

			if ($membership && $group_id) {
				$logger->info( sprintf('Updating group access of %s: %s', $user->user_login, $group_id) );
				$action = determine_plan_group_action($membership->status);
				update_discourse_group_access($user_id, $action, $group_id);

				$logger->info( sprintf('Sleeping for 4 seconds') );
				sleep(4);
			}
		}
						
		foreach ( $product_group_map as $item ) {
						
			foreach ( $item->product_ids as $product_id ) {
								
				$has_bought = wc_customer_bought_product( null, $user_id, $product_id );
				$group_id = $item->group_id;
				$action = "PUT";
				
				$logger->info( sprintf('Updating group access of %s: %s', $user->user_login, $group_id) );
				
				update_discourse_group_access($user_id, $action, $group_id);
				
				$logger->info( sprintf('Sleeping for 4 seconds') );
				sleep(4);
			}
		}
	}
}

add_action('run_full_wc_membership_sync', 'full_wc_membership_sync');

// Handle single product purchases

function handle_wc_membership_order_status_change( $order_id, $old_status, $new_status ) {
	global $product_group_map;
	$logger = wc_get_logger();
	
	if ($new_status == "completed") {
		$order = new WC_Order($order_id);
		$items = $order->get_items();
		
		$logger->info( sprintf('Order items %s', json_encode($items) ) );
		
		foreach ( $items as $item_id => $item ) {
			$product_id = $item->get_product_id();
			
			foreach($product_group_map as $struct) {
		    if (in_array($product_id, $struct->product_ids)) {
					$group_id = $struct->group_id;
					$user_id = $order->user_id;
					$action = "PUT";
					
					$logger->info( sprintf('Running update_discourse_group_access %s, %s, %s', $user_id, $action, $group_id ) );
					
					update_discourse_group_access($user_id, $action, $group_id);
		    }
			}
		}
  }
}

add_action('woocommerce_order_status_changed', 'handle_wc_membership_order_status_change', 10, 3);
