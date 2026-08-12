<?php

/**
 * Import Woocommerce plugin file.
 *
 * Copyright (C) 2010-2020, Smackcoders Inc - info@smackcoders.com
 */

namespace Smackcoders\SMWC;

if (! defined('ABSPATH'))
	exit; // Exit if accessed directly

require_once('ImportHelpers.php');
require_once('MediaHandling.php');

class WooCommerceCustomerImport extends ImportHelpers
{
	private static $woocommerce_core_instance = null, $media_instance,$woocommerce_meta_instance;

	public static function getInstance()
	{

		if (WooCommerceCustomerImport::$woocommerce_core_instance == null) {
			WooCommerceCustomerImport::$woocommerce_core_instance = new WooCommerceCustomerImport;
			WooCommerceCustomerImport::$woocommerce_meta_instance = new WooCommerceMetaImport;
			WooCommerceCustomerImport::$media_instance = new MediaHandling();
			return WooCommerceCustomerImport::$woocommerce_core_instance;
		}
		return WooCommerceCustomerImport::$woocommerce_core_instance;
	}

	public function users_import_function ($data_array, $mode, $hash_key, $unikey_name, $line_number, $check = '', $update_based_on = 'normal', $duplicate_action = 'skip') {
		global $wpdb,$core_instance;
		$helpers_instance = ImportHelpers::getInstance();
		$returnArr = array();
		$log_table_name = $wpdb->prefix ."import_detail_log";
		$retID = 0;
		$mode_of_affect = 'Inserted';

		$update_based_on = in_array($update_based_on, array('normal', 'skip'), true) ? $update_based_on : 'normal';
		$duplicate_action = in_array($duplicate_action, array('skip', 'update', 'create'), true) ? $duplicate_action : 'skip';
		$user_match_fields = array('ID', 'user_email');

		$updated_row_counts = $helpers_instance->update_count($hash_key, $unikey_name);
		$created_count = $updated_row_counts['created'];
		$updated_count = $updated_row_counts['updated'];
		$skipped_count = $updated_row_counts['skipped'];

		if (isset($data_array['role']) && $data_array['role'] !== '' && $data_array['role'] !== 'customer') {
			$core_instance->detailed_log[$line_number]['Message'] = "Role not allowed. Only 'customer' role is permitted.";
			$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
			$wpdb->update(
				$log_table_name,
				array( 'skipped' => (int) $skipped_count ),
				array( 'hash_key' => $hash_key ),
				array( '%d' ),
				array( '%s' )
			);
			return array('MODE' => $mode, 'ERROR_MSG' => "Role not allowed. Only 'customer' role is permitted.");
		}

		$data_array['role'] = 'customer';

		$data_array = apply_filters('smack_csv_modify_userdata_filter', $data_array);

		$existing_id = $this->find_existing_user_id($data_array, $check);
		$has_match = $existing_id > 0;
		$duplicate_handling_active = (
			$update_based_on === 'normal'
			&& !empty($check)
			&& in_array($check, $user_match_fields, true)
		);

		if ($update_based_on === 'skip' && !empty($check) && in_array($check, $user_match_fields, true) && !$has_match) {
			$core_instance->detailed_log[$line_number]['Message'] = 'Skipped. No matching record found.';
			$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
			$wpdb->update(
				$log_table_name,
				array( 'skipped' => (int) $skipped_count ),
				array( 'hash_key' => $hash_key ),
				array( '%d' ),
				array( '%s' )
			);
			return array('MODE' => $mode);
		}

		if ($duplicate_handling_active && $has_match && $duplicate_action === 'skip') {
			$core_instance->detailed_log[$line_number]['Message'] = 'Skipped, Due to duplicate User found!.';
			$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
			$core_instance->detailed_log[$line_number]['id'] = $existing_id;
			$wpdb->update(
				$log_table_name,
				array( 'skipped' => (int) $skipped_count ),
				array( 'hash_key' => $hash_key ),
				array( '%d' ),
				array( '%s' )
			);
			return array('MODE' => $mode, 'ID' => $existing_id);
		}

		if ($duplicate_handling_active && $has_match && $duplicate_action === 'update') {
			$data_array['ID'] = $existing_id;
			$result = $this->update_existing_user($data_array, $hash_key, $line_number, $log_table_name, $updated_count);
			if ($result === false) {
				$core_instance->detailed_log[$line_number]['Message'] = 'Skipped, Due to duplicate User update failed!.';
				$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
				$wpdb->update(
					$log_table_name,
					array( 'skipped' => (int) $skipped_count ),
					array( 'hash_key' => $hash_key ),
					array( '%d' ),
					array( '%s' )
				);
				return array('MODE' => $mode);
			}
			$retID = $result['ID'];
			$mode_of_affect = $result['MODE'];
		} elseif ($duplicate_handling_active && $has_match && $duplicate_action === 'create') {
			$result = $this->insert_new_user($data_array, $hash_key, $line_number, $log_table_name, $created_count, $skipped_count);
			if ($result === false) {
				return array('MODE' => $mode);
			}
			$retID = $result['ID'];
			$mode_of_affect = $result['MODE'];
		} elseif ($mode === 'Update' && $has_match) {
			$data_array['ID'] = $existing_id;
			$result = $this->update_existing_user($data_array, $hash_key, $line_number, $log_table_name, $updated_count);
			if ($result === false) {
				$core_instance->detailed_log[$line_number]['Message'] = 'Skipped, Due to duplicate User update failed!.';
				$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
				$wpdb->update(
					$log_table_name,
					array( 'skipped' => (int) $skipped_count ),
					array( 'hash_key' => $hash_key ),
					array( '%d' ),
					array( '%s' )
				);
				return array('MODE' => $mode);
			}
			$retID = $result['ID'];
			$mode_of_affect = $result['MODE'];
		} else {
			$result = $this->insert_new_user($data_array, $hash_key, $line_number, $log_table_name, $created_count, $skipped_count);
			if ($result === false) {
				return array('MODE' => $mode);
			}
			$retID = $result['ID'];
			$mode_of_affect = $result['MODE'];
		}
		$metaData = array();
		foreach ( $data_array as $daKey => $daVal ) {

			switch ( $daKey ) {
				case 'biographical_info' :
					$metaData['description'] = $data_array[ $daKey ];
					break;
				case 'disable_visual_editor' :
					$metaData['rich_editing'] = $data_array[ $daKey ];
					break;
				case 'enable_keyboard_shortcuts':
					$metaData['comment_shortcuts'] = $data_array[ $daKey ];
					break;
				case 'admin_color':
					$metaData['admin_color'] = $data_array[ $daKey ];
					break;
				case 'show_toolbar':
					$metaData['show_admin_bar_front'] = $data_array[ $daKey ];
					break;
				case 'smack_uci_import':
					$metaData['smack_uci_import'] = $data_array[ $daKey ];
			}
		}

		if ( ! empty ( $metaData ) ) {
			foreach ( $metaData as $meta_key => $meta_value ) {
				update_user_meta( $retID, $meta_key, $meta_value );

				//filter for modifying user metadata after import
				apply_filters('smack_csv_modify_metadata_filter', $retID, $meta_key, $meta_value);
			}
		}
		$user_data = get_userdata($retID);
		$user_title = isset($user_data) ? $user_data->display_name : '';
		$core_instance->detailed_log[$line_number]['user_title'] = $user_title;
		$core_instance->detailed_log[$line_number]['Email'] = $data_array['user_email'];
		$core_instance->detailed_log[$line_number]['Role'] = $data_array['role'];
		$ucisettings = get_option('sm_uci_pro_settings');
		if(isset($ucisettings['send_user_password']) && $ucisettings['send_user_password'] == "true") {
			$send_user_password = new SendPassword;
			$send_user_password->send_login_credentials_to_users();	
		}
		$returnArr['ID'] = $retID;
		$returnArr['MODE'] = $mode_of_affect;
		return $returnArr;
	}

	private function find_existing_user_id($data_array, $check) {
		if (empty($check)) {
			return 0;
		}
		if ($check === 'ID') {
			$id = isset($data_array['ID']) ? trim((string) $data_array['ID']) : '';
			if ($id !== '' && is_numeric($id)) {
				$user_id = absint($id);
				if ($user_id > 0) {
					$user = get_user_by('id', $user_id);
					return $user ? (int) $user->ID : 0;
				}
			}
			return 0;
		}
		if ($check === 'user_email') {
			$email = isset($data_array['user_email']) ? trim((string) $data_array['user_email']) : '';
			if ($email !== '' && is_email($email)) {
				$user = get_user_by('email', $email);
				return $user ? (int) $user->ID : 0;
			}
			return 0;
		}
		return 0;
	}

	private function prepare_user_credentials(&$data_array) {
		$send_password = isset($data_array['user_pass']) ? $data_array['user_pass'] : '';
		if (empty($data_array['user_pass'])) {
			$data_array['user_pass'] = wp_generate_password(12, false);
			$data_array['smack_uci_import'] = array(
				'user_login' => $data_array['user_login'],
				'user_pass'  => $data_array['user_pass'],
				'user_email' => $data_array['user_email'],
				'role'       => $data_array['role'],
			);
		} else {
			if (strlen($data_array['user_pass']) !== 34 && $data_array['user_pass'][0] !== '$') {
				$data_array['user_pass'] = wp_hash_password($data_array['user_pass']);
			}
			$data_array['smack_uci_import'] = array(
				'user_login' => $data_array['user_login'],
				'user_pass'  => $data_array['user_pass'],
				'user_email' => $data_array['user_email'],
				'role'       => $data_array['role'],
			);
		}
		return $send_password;
	}

	private function insert_new_user($data_array, $hash_key, $line_number, $log_table_name, $created_count, $skipped_count) {
		global $wpdb, $core_instance;
		unset($data_array['ID']);
		$send_password = $this->prepare_user_credentials($data_array);
		$retID = wp_insert_user($data_array);
		if (!is_wp_error($retID)) {
			update_user_meta($retID, 'sendPassword', $send_password);
			if (!empty($data_array['user_pass'])) {
				$wpdb->update(
					$wpdb->users,
					array( 'user_pass' => $data_array['user_pass'] ),
					array( 'ID' => (int) $retID ),
					array( '%s' ),
					array( '%d' )
				);
			}
			$core_instance->detailed_log[$line_number]['Message'] = 'Inserted User ID: ' . $retID;
			$core_instance->detailed_log[$line_number]['state'] = 'Inserted';
			$core_instance->detailed_log[$line_number]['id'] = $retID;
			$wpdb->update(
				$log_table_name,
				array( 'created' => (int) $created_count ),
				array( 'hash_key' => $hash_key ),
				array( '%d' ),
				array( '%s' )
			);
			return array('ID' => $retID, 'MODE' => 'Inserted');
		}
		$core_instance->detailed_log[$line_number]['Message'] = 'Skipped, Due to duplicate User found with same email!.';
		$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
		$wpdb->update(
			$log_table_name,
			array( 'skipped' => (int) $skipped_count ),
			array( 'hash_key' => $hash_key ),
			array( '%d' ),
			array( '%s' )
		);
		return false;
	}

	private function update_existing_user($data_array, $hash_key, $line_number, $log_table_name, $updated_count) {
		global $wpdb, $core_instance;
		$update_data = $data_array;
		if (isset($update_data['user_pass']) && $update_data['user_pass'] !== '') {
			if (strlen($update_data['user_pass']) !== 34 || $update_data['user_pass'][0] !== '$') {
				$update_data['user_pass'] = wp_hash_password($update_data['user_pass']);
			}
		} else {
			unset($update_data['user_pass']);
		}
		$retID = wp_update_user($update_data);
		if (is_wp_error($retID)) {
			return false;
		}
		$core_instance->detailed_log[$line_number]['Message'] = 'Updated User ID: ' . $data_array['ID'];
		$core_instance->detailed_log[$line_number]['state'] = 'Updated';
		$core_instance->detailed_log[$line_number]['id'] = $data_array['ID'];
		$wpdb->update(
			$log_table_name,
			array( 'updated' => (int) $updated_count ),
			array( 'hash_key' => $hash_key ),
			array( '%d' ),
			array( '%s' )
		);
		return array('ID' => $data_array['ID'], 'MODE' => 'Updated');
	}

	public function getRoles($capability = null) {
		global $wp_roles;
		$roles = array();
		if($capability != null) {
			foreach ( $wp_roles->roles as $rkey => $rval ) {
				$roles[ $rkey ] = '';
				for ( $cnt = 0; $cnt < count( $rval['capabilities'] ); $cnt ++ ) {
					$findval = "level_" . $cnt;
					if ( array_key_exists( $findval, $rval['capabilities'] ) ) {
						$roles[ $rkey ] = $roles[ $rkey ] . $cnt . ',';
					}
				}
			}
		} else {
			if ( ! isset( $wp_roles ) )
				$wp_roles = new \WP_Roles();

			$roles = $wp_roles->get_names();
		}
		return $roles;
	}


}

global $uci_woocomm_customer_instance;
$uci_woocomm_customer_instance = new WooCommerceCustomerImport;