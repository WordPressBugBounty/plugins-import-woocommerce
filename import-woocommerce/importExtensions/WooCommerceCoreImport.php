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

class WooCommerceCoreImport extends ImportHelpers
{
	private static $woocommerce_core_instance = null, $media_instance,$woocommerce_meta_instance;

	public static function getInstance()
	{

		if (WooCommerceCoreImport::$woocommerce_core_instance == null) {
			WooCommerceCoreImport::$woocommerce_core_instance = new WooCommerceCoreImport;
			WooCommerceCoreImport::$woocommerce_meta_instance = new WooCommerceMetaImport;
			WooCommerceCoreImport::$media_instance = new MediaHandling();
			return WooCommerceCoreImport::$woocommerce_core_instance;
		}
		return WooCommerceCoreImport::$woocommerce_core_instance;
	}

	/**
	 * Safely update an import detail log counter column.
	 *
	 * @param string $log_table_name
	 * @param string $field
	 * @param int    $count
	 * @param string $unikey_name
	 * @param string $unikey_value
	 * @return int|false
	 */
	private function update_import_log_count( $log_table_name, $field, $count, $unikey_name, $unikey_value ) {
		global $wpdb;
		$allowed_fields = array( 'skipped', 'created', 'updated', 'failed' );
		$allowed_keys = array( 'hash_key', 'templatekey' );
		if ( ! in_array( $field, $allowed_fields, true ) ) {
			return false;
		}
		if ( ! in_array( $unikey_name, $allowed_keys, true ) ) {
			$unikey_name = 'hash_key';
		}
		return $wpdb->update(
			$log_table_name,
			array( $field => (int) $count ),
			array( $unikey_name => $unikey_value ),
			array( '%d' ),
			array( '%s' )
		);
	}

	public function woocommerce_orders_import($data_array, $mode, $check, $unikey, $unikey_name, $line_number, $order_meta_data, $update_based_on = 'normal', $duplicate_action = 'skip')
	{
		global $wpdb;
		$helpers_instance = ImportHelpers::getInstance();
		global $core_instance;

		$log_table_name = $wpdb->prefix . "import_detail_log";

		$update_based_on = in_array($update_based_on, array('normal', 'skip'), true) ? $update_based_on : 'normal';
		$duplicate_action = in_array($duplicate_action, array('skip', 'update', 'create'), true) ? $duplicate_action : 'skip';
		$order_match_fields = array('ORDERID');

		$updated_row_counts = $helpers_instance->update_count($unikey, $unikey_name);
		$created_count = $updated_row_counts['created'];
		$updated_count = $updated_row_counts['updated'];
		$skipped_count = $updated_row_counts['skipped'];

		$existing_id = $this->find_existing_order_id($data_array, $check);
		$has_match = $existing_id > 0;
		$duplicate_handling_active = (
			$update_based_on === 'normal'
			&& !empty($check)
			&& in_array($check, $order_match_fields, true)
		);

		if ($update_based_on === 'skip' && !empty($check) && in_array($check, $order_match_fields, true) && !$has_match) {
			$core_instance->detailed_log[$line_number]['Message'] = 'Skipped. No matching record found.';
			$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
			$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey );
			return array('MODE' => $mode);
		}

		if ($duplicate_handling_active && $has_match && $duplicate_action === 'skip') {
			$core_instance->detailed_log[$line_number]['Message'] = 'Skipped, Due to duplicate Order found!.';
			$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
			$core_instance->detailed_log[$line_number]['id'] = $existing_id;
			$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey );
			return array('MODE' => $mode, 'ID' => $existing_id);
		}

		$run_insert = false;
		$run_update = false;
		if ($duplicate_handling_active && $has_match && $duplicate_action === 'update') {
			$run_update = true;
			$data_array['ORDERID'] = $existing_id;
		} elseif ($duplicate_handling_active && $has_match && $duplicate_action === 'create') {
			$run_insert = true;
		} elseif ($mode === 'Update' && $has_match) {
			$run_update = true;
		} elseif ($mode === 'Update') {
			$core_instance->detailed_log[$line_number]['Message'] = 'Skipped. No matching record found.';
			$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
			$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey );
			return array('MODE' => $mode);
		} else {
			$run_insert = true;
		}

		if (!class_exists('WC_Order')) {
			$core_instance->detailed_log[$line_number]['Message'] = "Skipped.";
			$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
			$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey );
			return array('MODE' => $mode);
		}

		if ($run_insert) {
			$order = wc_create_order();
			$order_id = $order->save();
			$mode_of_affect = 'Inserted';
			if (is_wp_error($order_id) || $order_id == '') {
				$error_message = is_wp_error($order_id) ? $order_id->get_error_message() : '';
				$core_instance->detailed_log[$line_number]['Message'] = "Can't insert this Order. " . $error_message;
				$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
				$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey );
				return array('MODE' => $mode, 'ERROR_MSG' => $error_message);
			}
			$core_instance->detailed_log[$line_number]['Message'] = 'Inserted Order ID: ' . $order_id;
			$core_instance->detailed_log[$line_number]['id'] = $order_id;
			$core_instance->detailed_log[$line_number]['adminLink'] = get_edit_post_link($order_id, true);
			$core_instance->detailed_log[$line_number]['state'] = 'Inserted';
			$this->update_import_log_count( $log_table_name, 'created', $created_count, $unikey_name, $unikey );
		} elseif ($run_update) {
			$order_id = isset($data_array['ORDERID']) ? absint($data_array['ORDERID']) : $existing_id;
			if ($order_id <= 0) {
				$core_instance->detailed_log[$line_number]['Message'] = 'Skipped. No matching record found.';
				$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
				$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey );
				return array('MODE' => $mode);
			}
			$data_array['ORDERID'] = $order_id;
			$data_array['ID'] = $order_id;
			$mode_of_affect = 'Updated';
			$core_instance->detailed_log[$line_number]['Message'] = 'Updated Order ID: ' . $order_id;
			$core_instance->detailed_log[$line_number]['id'] = $order_id;
			$core_instance->detailed_log[$line_number]['adminLink'] = get_edit_post_link($order_id, true);
			$core_instance->detailed_log[$line_number]['state'] = 'Updated';
			$this->update_import_log_count( $log_table_name, 'updated', $updated_count, $unikey_name, $unikey );
			$order = wc_get_order($order_id);
			if (!$order) {
				$core_instance->detailed_log[$line_number]['Message'] = 'Skipped, Due to duplicate Order update failed!.';
				$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
				$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey );
				return array('MODE' => $mode);
			}
		} else {
			$core_instance->detailed_log[$line_number]['Message'] = "Skipped.";
			$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
			$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey );
			return array('MODE' => $mode);
		}
		$order_meta_data = is_array($order_meta_data) ? $order_meta_data : array();
		$item_name = isset($order_meta_data['item_name']) ? $order_meta_data['item_name'] : '';
		$item_qty = isset($order_meta_data['item_qty']) ? $order_meta_data['item_qty'] : '';
		$products = ($item_name !== '' && $item_name !== null) ? array_map('trim', explode(',', (string) $item_name)) : array();
		$quantities = ($item_qty !== '' && $item_qty !== null) ? array_map('trim', explode(',', (string) $item_qty)) : array();

		if ($run_insert) {
			$product_ids = $this->resolve_order_product_ids($products);
			for ($i = 0; $i < count($product_ids); $i++) {
				$my_product = wc_get_product($product_ids[$i]);
				if (empty($my_product)) {
					continue;
				}
				if ($my_product->is_type('variable')) {
					$variations = $my_product->get_children();
					for ($j = 0; $j < count($variations); $j++) {
						$quantity  = isset($quantities[$j]) && $quantities[$j] !== '' ? $quantities[$j] : 1;
						$variation = !empty($variations[$j]) ? wc_get_product($variations[$j]) : false;
						if (!empty($variation) && $variation->exists()) {
							$order->add_product($variation, $quantity);
						}
					}
				} else {
					$quantity = isset($quantities[$i]) && $quantities[$i] !== '' ? $quantities[$i] : 1;
					$order->add_product($my_product, $quantity);
				}
			}
			$this->apply_mapped_line_item_totals($order, $order_meta_data);
			$this->apply_mapped_fee_items($order, $order_meta_data);
			$this->apply_mapped_shipping_items($order, $order_meta_data);
		}

		// Set customer information
		$customer_user = isset($order_meta_data['customer_user']) ? $order_meta_data['customer_user'] : '';
		if (is_numeric($customer_user)) {
			$customer_user_id = absint($customer_user);
		} elseif (!empty($customer_user)) {
			$email = $customer_user;
			$customer_user_id = absint($wpdb->get_var($wpdb->prepare(
				"SELECT ID FROM {$wpdb->prefix}users WHERE user_email = %s",
				$email
			)));
		} else {
			$customer_user_id = 0;
		}
		$customer_note = isset($data_array['customer_note']) ? $data_array['customer_note'] : '';

		// Replace with the customer's user ID
		$order->set_customer_id($customer_user_id);
		$billing_first_name = isset($order_meta_data['billing_first_name']) ? $order_meta_data['billing_first_name'] : '';
		$billing_last_name = isset($order_meta_data['billing_last_name']) ? $order_meta_data['billing_last_name'] : '';
		$billing_company = isset($order_meta_data['billing_company']) ? $order_meta_data['billing_company'] : '';
		$billing_address_1 = isset($order_meta_data['billing_address_1']) ? $order_meta_data['billing_address_1'] : '';
		$billing_address_2 = isset($order_meta_data['billing_address_2']) ? $order_meta_data['billing_address_2'] : '';
		$billing_city = isset($order_meta_data['billing_city']) ? $order_meta_data['billing_city'] : '';
		$billing_postcode = isset($order_meta_data['billing_postcode']) ? $order_meta_data['billing_postcode'] : '';
		$billing_country = isset($order_meta_data['billing_country']) ? $order_meta_data['billing_country'] : '';
		$billing_phone = isset($order_meta_data['billing_phone']) ? $order_meta_data['billing_phone'] : '';
		$billing_email = isset($order_meta_data['billing_email']) ? $order_meta_data['billing_email'] : '';
		$billing_state = isset($order_meta_data['billing_state']) ? $order_meta_data['billing_state'] : '';
		$shipping_first_name = isset($order_meta_data['shipping_first_name']) ? $order_meta_data['shipping_first_name'] : '';
		$shipping_last_name = isset($order_meta_data['shipping_last_name']) ? $order_meta_data['shipping_last_name'] : '';
		$shipping_company = isset($order_meta_data['shipping_company']) ? $order_meta_data['shipping_company'] : '';
		$shipping_address_1 = isset($order_meta_data['shipping_address_1']) ? $order_meta_data['shipping_address_1'] : '';
		$shipping_address_2 = isset($order_meta_data['shipping_address_2']) ? $order_meta_data['shipping_address_2'] : '';
		$shipping_city = isset($order_meta_data['shipping_city']) ? $order_meta_data['shipping_city'] : '';
		$shipping_postcode = isset($order_meta_data['shipping_postcode']) ? $order_meta_data['shipping_postcode'] : '';
		$shipping_country = isset($order_meta_data['shipping_country']) ? $order_meta_data['shipping_country'] : '';
		$shipping_phone = isset($order_meta_data['shipping_phone']) ? $order_meta_data['shipping_phone'] : '';
		$shipping_email = isset($order_meta_data['shipping_email']) ? $order_meta_data['shipping_email'] : '';
		$shipping_state = isset($order_meta_data['shipping_state']) ? $order_meta_data['shipping_state'] : '';

		// Set billing and shipping address (replace with actual details)
		$billing_address = array(
			'first_name' => $billing_first_name,
			'last_name'  => $billing_last_name,
			'address_1'  => $billing_address_1,
			'address_2'  => $billing_address_2,
			'city'       => $billing_city,
			'state'      => $billing_state,
			'postcode'   => $billing_postcode,
			'country'    => $billing_country,
			'email'      => $billing_email,
			'phone'      => $billing_phone,
			'company' => $billing_company
		);
		$shipping_address = array(
			'first_name' => $shipping_first_name,
			'last_name'  => $shipping_last_name,
			'address_1'  => $shipping_address_1,
			'address_2'  => $shipping_address_2,
			'city'       => $shipping_city,
			'state'      => $shipping_state,
			'postcode'   => $shipping_postcode,
			'country'    => $shipping_country,
			'email'      => $shipping_email,
			'phone'      => $shipping_phone,
			'company' => $shipping_company
		);
		$order->set_address($billing_address, 'billing');
		$order->set_address($shipping_address, 'shipping');

		$payment_method = isset($order_meta_data['payment_method']) ? $order_meta_data['payment_method'] : '';
		$order_currency = isset($order_meta_data['order_currency']) ? $order_meta_data['order_currency'] : '';

		if ($payment_method !== '') {
			$order->set_payment_method($payment_method);
		}
		$order->set_customer_note($customer_note);
		if ($order_currency !== '') {
			$order->set_currency($order_currency);
		}

		$order->calculate_totals(false);
		$this->apply_mapped_order_financial_fields($order, $order_meta_data);

		$ywot_keys = array(
			'ywot_tracking_code',
			'ywot_tracking_postcode',
			'ywot_carrier_id',
			'ywot_pick_up_date',
			'ywot_estimated_delivery_date',
			'ywot_picked_up',
		);
		foreach ($ywot_keys as $ywot_key) {
			if (isset($order_meta_data[$ywot_key]) && $order_meta_data[$ywot_key] !== '') {
				$order->update_meta_data($ywot_key, $order_meta_data[$ywot_key]);
			}
		}
		if (isset($order_meta_data['recorded_sales']) && $order_meta_data['recorded_sales'] !== '') {
			$recorded = strtolower(trim((string) $order_meta_data['recorded_sales']));
			$order->update_meta_data(
				'_recorded_sales',
				in_array($recorded, array('1', 'yes', 'true'), true) ? 'yes' : 'no'
			);
		}

		$parsed_order_date = $this->parse_order_date($data_array);
		if ($parsed_order_date) {
			$order->set_date_created($parsed_order_date);
		}

		$order_id = $order->save();

		if ($parsed_order_date && $order_id) {
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_date'     => $parsed_order_date,
					'post_date_gmt' => get_gmt_from_date($parsed_order_date),
				),
				array('ID' => $order_id)
			);
		}

		$module = $wpdb->get_var($wpdb->prepare("SELECT post_type FROM {$wpdb->prefix}posts WHERE ID = %d", absint($order_id)));
		$order_status = $data_array['order_status'];
		global $wpdb;
		if ($module == 'shop_order_placehold') {
			if (!empty($order_status)) {
				$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}wc_orders SET status = %s WHERE id = %d", $order_status, absint($order_id)));
			}
		} else {
			if (!empty($order_status)) {
				$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}posts SET post_status = %s WHERE ID = %d", $order_status, absint($order_id)));
			}
		}
		$wpdb->update(
			$wpdb->prefix . 'posts',
			array(
				'post_excerpt' => $customer_note,
			),
			array('id' => $order_id)
		);
		$returnArr['ID'] = $order_id;
		$returnArr['MODE'] = $mode_of_affect;
		return $returnArr;
	}

	private function parse_order_date($data_array)
	{
		if (empty($data_array['order_date'])) {
			return false;
		}
		$order_date_raw = trim((string) $data_array['order_date']);
		if ($order_date_raw === '') {
			return false;
		}
		if (strpos($order_date_raw, '.') !== false) {
			$order_date_raw = str_replace('.', '-', $order_date_raw);
		}
		$timestamp = strtotime($order_date_raw);
		if (!$timestamp) {
			return false;
		}
		return date('Y-m-d H:i:s', $timestamp);
	}

	private function resolve_order_product_ids($products)
	{
		global $wpdb;
		$product_ids = array();
		if (!is_array($products)) {
			return $product_ids;
		}
		foreach ($products as $products_value) {
			$products_value = trim((string) $products_value);
			if ($products_value === '') {
				continue;
			}
			if (is_numeric($products_value)) {
				$product_ids[] = absint($products_value);
				continue;
			}
			$found = $wpdb->get_var($wpdb->prepare(
				"SELECT ID FROM {$wpdb->prefix}posts WHERE post_title = %s AND post_type IN ('product','product_variation') AND post_status = 'publish' ORDER BY ID DESC LIMIT 1",
				$products_value
			));
			if (!empty($found)) {
				$product_ids[] = absint($found);
			}
		}
		return $product_ids;
	}

	private function apply_mapped_order_financial_fields($order, $order_meta_data)
	{
		if (empty($order) || !is_array($order_meta_data)) {
			return;
		}

		if (isset($order_meta_data['payment_method_title']) && $order_meta_data['payment_method_title'] !== '') {
			$order->set_payment_method_title($order_meta_data['payment_method_title']);
		}
		if (isset($order_meta_data['transaction_id']) && $order_meta_data['transaction_id'] !== '') {
			$order->set_transaction_id($order_meta_data['transaction_id']);
		}
		if (isset($order_meta_data['order_shipping']) && $order_meta_data['order_shipping'] !== '') {
			$order->set_shipping_total(wc_format_decimal($order_meta_data['order_shipping']));
		}
		if (isset($order_meta_data['order_shipping_tax']) && $order_meta_data['order_shipping_tax'] !== '') {
			$order->set_shipping_tax(wc_format_decimal($order_meta_data['order_shipping_tax']));
		}
		if (isset($order_meta_data['order_tax']) && $order_meta_data['order_tax'] !== '') {
			$order->set_cart_tax(wc_format_decimal($order_meta_data['order_tax']));
		}
		if (isset($order_meta_data['cart_discount']) && $order_meta_data['cart_discount'] !== '') {
			$order->set_discount_total(wc_format_decimal($order_meta_data['cart_discount']));
		}
		if (isset($order_meta_data['cart_discount_tax']) && $order_meta_data['cart_discount_tax'] !== '') {
			$order->set_discount_tax(wc_format_decimal($order_meta_data['cart_discount_tax']));
		}
		if (isset($order_meta_data['order_total']) && $order_meta_data['order_total'] !== '') {
			$order->set_total(wc_format_decimal($order_meta_data['order_total']));
		}
	}

	private function apply_mapped_line_item_totals($order, $order_meta_data)
	{
		if (empty($order) || !is_array($order_meta_data)) {
			return;
		}

		$line_totals = $this->csv_list($order_meta_data, 'item_line_total');
		$line_subtotals = $this->csv_list($order_meta_data, 'item_line_subtotal');
		$line_taxes = $this->csv_list($order_meta_data, 'item_line_tax');
		$line_subtotal_taxes = $this->csv_list($order_meta_data, 'item_line_subtotal_tax');
		$line_qtys = $this->csv_list($order_meta_data, 'item_qty');
		$tax_classes = $this->csv_list($order_meta_data, 'item_tax_class');
		$product_ids = $this->csv_list($order_meta_data, 'item_product_id');
		$variation_ids = $this->csv_list($order_meta_data, 'item_variation_id');

		if (
			empty($line_totals) && empty($line_subtotals) && empty($line_taxes)
			&& empty($line_subtotal_taxes) && empty($tax_classes)
			&& empty($product_ids) && empty($variation_ids)
		) {
			return;
		}

		$index = 0;
		foreach ($order->get_items('line_item') as $item) {
			if (isset($line_qtys[$index]) && $line_qtys[$index] !== '' && is_numeric($line_qtys[$index])) {
				$item->set_quantity(wc_stock_amount($line_qtys[$index]));
			}
			if (isset($line_subtotals[$index]) && $line_subtotals[$index] !== '') {
				$item->set_subtotal(wc_format_decimal($line_subtotals[$index]));
			}
			if (isset($line_totals[$index]) && $line_totals[$index] !== '') {
				$item->set_total(wc_format_decimal($line_totals[$index]));
			}
			if (isset($line_subtotal_taxes[$index]) && $line_subtotal_taxes[$index] !== '') {
				$item->set_subtotal_tax(wc_format_decimal($line_subtotal_taxes[$index]));
			}
			if (isset($line_taxes[$index]) && $line_taxes[$index] !== '') {
				$item->set_total_tax(wc_format_decimal($line_taxes[$index]));
			}
			if (isset($tax_classes[$index]) && $tax_classes[$index] !== '') {
				$item->set_tax_class($tax_classes[$index]);
			}
			if (isset($product_ids[$index]) && is_numeric($product_ids[$index])) {
				$item->set_product_id(absint($product_ids[$index]));
			}
			if (isset($variation_ids[$index]) && is_numeric($variation_ids[$index])) {
				$item->set_variation_id(absint($variation_ids[$index]));
			}
			$item->save();
			$index++;
		}
	}

	private function apply_mapped_fee_items($order, $order_meta_data)
	{
		$fee_names = $this->csv_list($order_meta_data, 'fee_name');
		if (empty($fee_names) || !class_exists('WC_Order_Item_Fee')) {
			return;
		}
		$fee_totals = $this->csv_list($order_meta_data, 'fee_line_total');
		$fee_taxes = $this->csv_list($order_meta_data, 'fee_line_tax');
		$fee_tax_classes = $this->csv_list($order_meta_data, 'fee_tax_class');

		foreach ($fee_names as $index => $fee_name) {
			$fee_name = trim((string) $fee_name);
			if ($fee_name === '') {
				continue;
			}
			$fee = new \WC_Order_Item_Fee();
			$fee->set_name($fee_name);
			if (isset($fee_totals[$index]) && $fee_totals[$index] !== '') {
				$fee->set_total(wc_format_decimal($fee_totals[$index]));
			}
			if (isset($fee_taxes[$index]) && $fee_taxes[$index] !== '') {
				$fee->set_total_tax(wc_format_decimal($fee_taxes[$index]));
			}
			if (isset($fee_tax_classes[$index]) && $fee_tax_classes[$index] !== '') {
				$fee->set_tax_class($fee_tax_classes[$index]);
			}
			$order->add_item($fee);
		}
	}

	private function apply_mapped_shipping_items($order, $order_meta_data)
	{
		$shipment_names = $this->csv_list($order_meta_data, 'shipment_name');
		if (empty($shipment_names) && (!isset($order_meta_data['order_shipping']) || $order_meta_data['order_shipping'] === '')) {
			return;
		}
		if (!class_exists('WC_Order_Item_Shipping')) {
			return;
		}

		$method_ids = $this->csv_list($order_meta_data, 'shipment_method_id');
		$costs = $this->csv_list($order_meta_data, 'shipment_cost');

		if (empty($shipment_names) && isset($order_meta_data['order_shipping']) && $order_meta_data['order_shipping'] !== '') {
			$shipment_names = array('Shipping');
			$costs = array($order_meta_data['order_shipping']);
		}

		foreach ($shipment_names as $index => $shipment_name) {
			$shipment_name = trim((string) $shipment_name);
			if ($shipment_name === '') {
				continue;
			}
			$shipping = new \WC_Order_Item_Shipping();
			$shipping->set_method_title($shipment_name);
			if (isset($method_ids[$index]) && $method_ids[$index] !== '') {
				$shipping->set_method_id($method_ids[$index]);
			} else {
				$shipping->set_method_id('flat_rate');
			}
			if (isset($costs[$index]) && $costs[$index] !== '') {
				$shipping->set_total(wc_format_decimal($costs[$index]));
			}
			$order->add_item($shipping);
		}
	}

	private function csv_list($order_meta_data, $key)
	{
		if (!isset($order_meta_data[$key]) || $order_meta_data[$key] === '' || $order_meta_data[$key] === null) {
			return array();
		}
		$parts = explode(',', (string) $order_meta_data[$key]);
		return array_map('trim', $parts);
	}

	private function find_existing_order_id($data_array, $check)
	{
		if ($check !== 'ORDERID') {
			return 0;
		}
		$order_id = isset($data_array['ORDERID']) ? trim((string) $data_array['ORDERID']) : '';
		if ($order_id === '' || !is_numeric($order_id)) {
			return 0;
		}
		$order_id = absint($order_id);
		if ($order_id <= 0) {
			return 0;
		}
		$post = get_post($order_id);
		if (!$post) {
			return 0;
		}
		if (!in_array($post->post_type, array('shop_order', 'shop_order_placehold'), true)) {
			return 0;
		}
		return $order_id;
	}

	public function woocommerce_coupons_import($data_array , $mode , $check , $unikey , $unikey_name, $line_number) {
		global $wpdb; 
		$helpers_instance = ImportHelpers::getInstance();
		global $core_instance;
		$log_table_name = $wpdb->prefix ."import_detail_log";

		$updated_row_counts = $helpers_instance->update_count($unikey,$unikey_name);
		$created_count = $updated_row_counts['created'];
		$updated_count = $updated_row_counts['updated'];
		$skipped_count = $updated_row_counts['skipped'];

		$returnArr = array();
		
		$data_array['post_type'] = 'shop_coupon';
		$data_array['post_title'] = $data_array['coupon_code'];
		$data_array['post_name'] = $data_array['coupon_code'];
		if(isset($data_array['description'])) {
			$data_array['post_excerpt'] = $data_array['description'];
		}

		/* Post Status Options */
		if ( !empty($data_array['coupon_status']) ) {
			$data_array = $helpers_instance->assign_post_status( $data_array );
		} else {
			$data_array['coupon_status'] = 'publish';
		}

		if ($mode == 'Insert') {
			$retID = wp_insert_post($data_array);
			$mode_of_affect = 'Inserted';
			
			if(is_wp_error($retID) || $retID == '') {
				$core_instance->detailed_log[$line_number]['Message'] = "Can't insert this Coupon. " . $retID->get_error_message();
				$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey );
				return array('MODE' => $mode, 'ERROR_MSG' => $retID->get_error_message());
			}
			$core_instance->detailed_log[$line_number]['Message'] = 'Inserted Coupon ID: ' . $retID;
			$this->update_import_log_count( $log_table_name, 'created', $created_count, $unikey_name, $unikey );

		} else {
				if($check == 'COUPONID'){
					$coupon_id = absint($data_array['COUPONID']);
					$post_type = $data_array['post_type'];
					$ID_result = $wpdb->get_results($wpdb->prepare(
						"SELECT ID FROM {$wpdb->prefix}posts WHERE ID = %d AND post_type = %s AND post_status NOT IN ('trash','draft') ORDER BY ID DESC",
						$coupon_id,
						$post_type
					));

					if (is_array($ID_result) && !empty($ID_result)) {
						$retID = $ID_result[0]->ID;
						$data_array['ID'] = $retID;
						wp_update_post($data_array);
						$mode_of_affect = 'Updated';

						$core_instance->detailed_log[$line_number]['Message'] = 'Updated Coupon ID: ' . $retID;
						$this->update_import_log_count( $log_table_name, 'updated', $updated_count, $unikey_name, $unikey );			
					} else{
						$core_instance->detailed_log[$line_number]['Message'] = "Skipped.";
						$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey );
						return array('MODE' => $mode);
					}
				}
				else{
					$core_instance->detailed_log[$line_number]['Message'] = "Skipped.";
					$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey );
					return array('MODE' => $mode);
				}
			//} 
		}
		$returnArr['ID'] = $retID;
		$returnArr['MODE'] = $mode_of_affect;
		return $returnArr;
	}

	// public function woocommerce_product_import($data_array, $mode, $check, $unikey_value, $unikey_name, $hash_key, $line_number, $unmatched_row, $wpml_values = null)
	// {
	// 	try{
	// 		if(!empty($product_meta_data)){
	// 			$post_values = array_merge($post_values,$product_meta_data);
	// 		}
	// 	$helpers_instance = ImportHelpers::getInstance();
	// 	global $wpdb;
	// 	global $core_instance, $sitepress;

	// 	$logTableName = $wpdb->prefix . "import_detail_log";

	// 	$data_array['PRODUCTSKU'] = isset($data_array['PRODUCTSKU']) ? $data_array['PRODUCTSKU'] : '';
	// 	$data_array['PRODUCTSKU'] = trim($data_array['PRODUCTSKU']);
	// 	if (isset($data_array['PRODUCTSKU'])) {
	// 		$core_instance->detailed_log[$line_number]['SKU'] = $data_array['PRODUCTSKU'];
	// 	}
	// 	if (isset($core_array['VARIATIONSKU'])) {
	// 		$core_instance->detailed_log[$line_number]['SKU'] = $data_array['VARIATIONSKU'];
	// 	}
	// 	$returnArr = array();
	// 	$assigned_author = '';
	// 	$getResult = '';
	// 	$mode_of_affect = 'Inserted';

	// 	$guid = isset($data_array['GUID']) ? trim($data_array['GUID']) : '';
    //     if (!empty($guid)) {
    //         $existing_guid = $wpdb->get_var("SELECT guid FROM {$wpdb->prefix}posts WHERE guid = '$guid' AND post_type = 'product'");
    //         if ($existing_guid && $mode == 'Insert') {
    //             // Skip duplicate GUID in insert mode
    //             $core_instance->detailed_log[$line_number]['Message'] = "Skipped, Duplicate GUID found.";
    //             $wpdb->get_results("UPDATE $logTableName SET skipped = $skipped_count WHERE $unikey_name = '$unikey_value'");
    //             return ['MODE' => $mode];
    //         }
    //     }
	// 	// Assign post type
	// 	$data_array['post_type'] = 'product';
	// 	$data_array = $core_instance->import_core_fields($data_array);
	// 	$post_type = $data_array['post_type'];

	// 	if ($check == 'ID') {
	// 		if (isset($post_values['ID'])) {
	// 			$ID = $post_values['ID'];
	// 			$getResult =  $wpdb->get_results("SELECT ID FROM {$wpdb->prefix}posts WHERE ID = '$ID' AND post_type = '$post_type' AND post_status != 'trash' order by ID DESC ");
	// 		}
	// 	}
	// 	if ($check == 'post_title') {
	// 		if (isset($data_array['post_title'])) {
	// 			$title = $data_array['post_title'];
	// 			$getResult =  $wpdb->get_results("SELECT ID FROM {$wpdb->prefix}posts WHERE post_title = '$title' AND post_type = '$post_type' AND post_status != 'trash' order by ID DESC ");
	// 		}
	// 	}
	// 	if ($check == 'post_name') {
	// 		if (isset($data_array['post_name'])) {
	// 			$name = $data_array['post_name'];

	// 			if ($sitepress != null && is_plugin_active('wpml-ultimate-importer/wpml-ultimate-importer.php')) {
	// 				$languageCode = $wpml_values['language_code'];
	// 				$getResult =  $wpdb->get_results("SELECT DISTINCT p.ID FROM {$wpdb->prefix}posts p join {$wpdb->prefix}icl_translations pm ON p.ID = pm.element_id WHERE p.post_name = '$name' AND p.post_type = '$post_type' AND p.post_status != 'trash' AND pm.language_code = '{$languageCode}'");
	// 			} else {
	// 				$getResult =  $wpdb->get_results("SELECT ID FROM {$wpdb->prefix}posts WHERE post_name = '$name' AND post_type = '$post_type' AND post_status != 'trash' order by ID DESC ");
	// 			}
	// 		}
	// 	}
	// 	if ($check == 'PRODUCTSKU') {
	// 		if (isset($data_array['PRODUCTSKU'])) {
	// 			$sku = $data_array['PRODUCTSKU'];
	// 			if ($sitepress != null && is_plugin_active('wpml-ultimate-importer/wpml-ultimate-importer.php')) {
	// 				$languageCode = $wpml_values['language_code'];
	// 				$getResult =  $wpdb->get_results("SELECT DISTINCT p.ID FROM {$wpdb->prefix}posts p join {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id inner join {$wpdb->prefix}icl_translations icl ON pm.post_id = icl.element_id WHERE p.post_type = 'product' AND p.post_status != 'trash' and pm.meta_value = '$sku' and icl.language_code = '{$languageCode}'");
	// 			} else {
	// 				$getResult =  $wpdb->get_results("SELECT DISTINCT p.ID FROM {$wpdb->prefix}posts p join {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id WHERE p.post_type = 'product' AND p.post_status != 'trash' and pm.meta_value = '$sku' ");
	// 			}
	// 		}
	// 	}

	// 	$updated_row_counts = $helpers_instance->update_count($unikey_value, $unikey_name);
	// 	$created_count = $updated_row_counts['created'];
	// 	$updated_count = $updated_row_counts['updated'];
	// 	$skipped_count = $updated_row_counts['skipped'];

	// 	if ($mode == 'Insert') {

	// 		if (is_array($getResult) && !empty($getResult)) {
	// 			#skipped
	// 			$core_instance->detailed_log[$line_number]['Message'] = "Skipped, Due to duplicate Product found!.";
	// 			$fields = $wpdb->get_results("UPDATE $logTableName SET skipped = $skipped_count WHERE $unikey_name = '$unikey_value'");
	// 			return array('MODE' => $mode);
	// 		} else {

	// 			$post_id = wp_insert_post($data_array);
	// 			set_post_format($post_id, isset($data_array['post_format']));

	// 			if (!empty($data_array['PRODUCTSKU'])) {
	// 				update_post_meta($post_id, '_sku', $data_array['PRODUCTSKU']);
	// 			}
	// 			if (is_wp_error($post_id) || $post_id == '') {
	// 				# skipped
	// 				$core_instance->detailed_log[$line_number]['Message'] = "Can't insert this Product. " . $post_id->get_error_message();
	// 				$fields = $wpdb->get_results("UPDATE $logTableName SET skipped = $skipped_count WHERE $unikey_name = '$unikey_value'");
	// 				return array('MODE' => $mode);
	// 			} else {
	// 				//WPML support on post types
	// 				global $sitepress;
	// 				if ($sitepress != null) {
	// 					$helpers_instance->UCI_WPML_Supported_Posts($data_array, $post_id);
	// 				}
	// 			}

	// 			if ($unmatched_row == 'true') {
	// 				global $wpdb;
	// 				$type = isset($type) ? $type : '';
	// 				$post_entries_table = $wpdb->prefix . "ultimate_post_entries";
	// 				$file_table_name = $wpdb->prefix . "smackcsv_file_events";
	// 				$get_id  = $wpdb->get_results("SELECT file_name  FROM $file_table_name WHERE `hash_key` = '$hash_key'");
	// 				$file_name = $get_id[0]->file_name;
	// 				$wpdb->get_results("INSERT INTO $post_entries_table (`ID`,`type`, `file_name`,`status`) VALUES ( '{$post_id}','{$type}', '{$file_name}','Inserted')");
	// 			}

	// 			$core_instance->detailed_log[$line_number]['Message'] = 'Inserted Product ID: ' . $post_id . ', ' . $assigned_author;
	// 			$fields = $wpdb->get_results("UPDATE $logTableName SET created = $created_count WHERE $unikey_name = '$unikey_value'");
	// 		}
	// 	}
	// 	if ($mode == 'Update') {

	// 		if (is_array($getResult) && !empty($getResult)) {
	// 			$post_id = $getResult[0]->ID;
	// 			$data_array['ID'] = $post_id;
	// 			wp_update_post($data_array);
	// 			set_post_format($post_id, $data_array['post_format']);

	// 			if ($unmatched_row == 'true') {
	// 				global $wpdb;
	// 				$post_entries_table = $wpdb->prefix . "ultimate_post_entries";
	// 				$file_table_name = $wpdb->prefix . "smackcsv_file_events";
	// 				$get_id  = $wpdb->get_results("SELECT file_name  FROM $file_table_name WHERE `hash_key` = '$hash_key'");
	// 				$file_name = $get_id[0]->file_name;
	// 				$wpdb->get_results("INSERT INTO $post_entries_table (`ID`,`type`, `file_name`,`status`) VALUES ( '{$post_id}','{$type}', '{$file_name}','Updated')");
	// 			}
	// 			$core_instance->detailed_log[$line_number]['Message'] = 'Updated Product ID: ' . $post_id . ', ' . $assigned_author;
	// 			$fields = $wpdb->get_results("UPDATE $logTableName SET updated = $updated_count WHERE $unikey_name = '$unikey_value'");
	// 		} else {
	// 			$post_id = wp_insert_post($data_array);
	// 			set_post_format($post_id, $data_array['post_format']);

	// 			if (is_wp_error($post_id) || $post_id == '') {
	// 				# skipped
	// 				$core_instance->detailed_log[$line_number]['Message'] = "Can't insert this Product. " . $post_id->get_error_message();
	// 				$fields = $wpdb->get_results("UPDATE $logTableName SET skipped = $skipped_count WHERE $unikey_name = '$unikey_value'");
	// 				return array('MODE' => $mode);
	// 			}

	// 			if ($unmatched_row == 'true') {
	// 				global $wpdb;
	// 				$post_entries_table = $wpdb->prefix . "ultimate_post_entries";
	// 				$file_table_name = $wpdb->prefix . "smackcsv_file_events";
	// 				$get_id  = $wpdb->get_results("SELECT file_name  FROM $file_table_name WHERE `hash_key` = '$hash_key'");
	// 				$file_name = $get_id[0]->file_name;
	// 				$wpdb->get_results("INSERT INTO $post_entries_table (`ID`,`type`, `file_name`,`status`) VALUES ( '{$post_id}','{$type}', '{$file_name}','Updated')");
	// 			}
	// 			$core_instance->detailed_log[$line_number]['Message'] = 'Inserted Product ID: ' . $post_id . ', ' . $assigned_author;
	// 			$fields = $wpdb->get_results("UPDATE $logTableName SET created = $created_count WHERE $unikey_name = '$unikey_value'");
	// 		}
	// 	}
	// }
	// catch (\Exception $e) {
	// 	$core_instance->detailed_log[$line_number]['Message'] = $e->getMessage();
	// 	$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
	// 	$wpdb->get_results("UPDATE $log_table_name SET skipped = $skipped_count WHERE $unikey_name = '$unikey_value'");
	// 	return array('MODE' => $mode,'ID' => '');
	// }
	// 	$returnArr['ID'] = $post_id;
	// 	$returnArr['MODE'] = $mode_of_affect;
	// 	if (!empty($data_array['post_author'])) {
	// 		$returnArr['AUTHOR'] = isset($assigned_author) ? $assigned_author : '';
	// 	}
	// 	return $returnArr;
	// }
	 public function woocommerce_product_import($post_values, $mode, $check, $unikey_value, $unikey_name, $hash_key, $line_number, $unmatched_row,$header_array,$value_array, $wpml_values = null,$product_meta_data=null,$attr_data=null, $update_based_on = 'normal', $duplicate_action = 'skip'){
		try{
			if(!empty($product_meta_data)){
				$post_values = array_merge($post_values,$product_meta_data);
			}
			global $wpdb;
			global $wpdb,$core_instance,$sitepress; 
			$wpml_values = null;
			$helpers_instance = ImportHelpers::getInstance();
			$media_instance = MediaHandling::getInstance();
			$woocommerce_meta_instance = WooCommerceMetaImport::getInstance();
	
			$log_table_name = $wpdb->prefix ."import_detail_log";
			$returnArr = array();
			$assigned_author = '';
			$mode_of_affect = 'Inserted';
			$updated_row_counts = $helpers_instance->update_count($unikey_value,$unikey_name);
			$created_count = $updated_row_counts['created'];
			$updated_count = $updated_row_counts['updated'];
			$skipped_count = $updated_row_counts['skipped'];
			// Avoid PHP notices when mapping doesn't provide product_type.
			$product_type = !empty($post_values['product_type']) ? $post_values['product_type'] : 1;
			if (is_plugin_active('jet-booking/jet-booking.php')){
				$booking_type = trim(jet_abaf()->settings->get( 'apartment_post_type' ));
			}
			if (class_exists('WC_Product')) {
				if($product_type == 'variation' || $product_type== 8){
					$post_type = 'product_variation';
				}
				else{
					$post_type = 'product';
				}
				$sku = $post_values['PRODUCTSKU'];
				if($check == 'ID'){	
					$ID = absint($post_values['ID']);	
					if($sitepress != null && isset($wpml_values['language_code']) && !empty($wpml_values['language_code'])) {
						$language_code = $wpml_values['language_code'];
						$get_result =  $wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->prefix}posts p join {$wpdb->prefix}icl_translations pm ON p.ID = pm.element_id WHERE p.ID = %d AND p.post_type = %s AND p.post_status != 'trash' AND pm.language_code = %s", $ID, $post_type, $language_code));
					}
					elseif(isset($poly_values) && !empty($poly_values)){
						$language_code = $poly_values['language_code'];
						if(!empty($ID)){
							$get_result=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->prefix}posts as p inner join {$wpdb->prefix}term_relationships as tr ON tr.object_id=p.ID inner join {$wpdb->prefix}term_taxonomy as tax on tax.term_taxonomy_id=tr.term_taxonomy_id inner join {$wpdb->prefix}terms as t on t.term_id=tax.term_id  where tax.taxonomy ='language'  and t.slug=%s and p.ID=%d AND p.post_status != 'trash'", $language_code, $ID));
						}
					}
					else{
						$get_result =  $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->prefix}posts WHERE ID = %d AND post_type = %s AND post_status != 'trash' order by ID DESC ", $ID, $post_type));			
					}
				}
				if($check == 'post_title'){
					$title = $post_values['post_title'];
					if($sitepress != null && isset($wpml_values['language_code']) && !empty($wpml_values['language_code'])) {
						$language_code = $wpml_values['language_code'];
						$get_result =  $wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->prefix}posts p join {$wpdb->prefix}icl_translations pm ON p.ID = pm.element_id WHERE p.post_title = %s AND p.post_type = %s AND p.post_status != 'trash' AND pm.language_code = %s", $title, $post_type, $language_code));
					}
					elseif(isset($poly_values) && !empty($poly_values)){
						$language_code = $poly_values['language_code'];
						$get_result=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->prefix}posts as p inner join {$wpdb->prefix}term_relationships as tr ON tr.object_id=p.ID inner join {$wpdb->prefix}term_taxonomy as tax on tax.term_taxonomy_id=tr.term_taxonomy_id inner join {$wpdb->prefix}terms as t on t.term_id=tax.term_id  where tax.taxonomy ='language'  and t.slug=%s and p.post_title=%s AND p.post_status != 'trash'", $language_code, $title));
					}
					else{
						$get_result =  $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->prefix}posts WHERE post_title = %s AND post_type = %s AND post_status != 'trash' order by ID DESC ", $title, $post_type));		
					}
					
				}
				if($check == 'post_name'){
					$name = $post_values['post_name'];
					if($sitepress != null && isset($wpml_values['language_code']) && !empty($wpml_values['language_code'])) {
						$language_code = $wpml_values['language_code'];
						$get_result =  $wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->prefix}posts p join {$wpdb->prefix}icl_translations pm ON p.ID = pm.element_id WHERE p.post_name = %s AND p.post_type = %s AND p.post_status != 'trash' AND pm.language_code = %s", $name, $post_type, $language_code));
					}
					elseif(isset($poly_values) && !empty($poly_values)){
						$language_code = $poly_values['language_code'];
						$get_result=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->prefix}posts as p inner join {$wpdb->prefix}term_relationships as tr ON tr.object_id=p.ID inner join {$wpdb->prefix}term_taxonomy as tax on tax.term_taxonomy_id=tr.term_taxonomy_id inner join {$wpdb->prefix}terms as t on t.term_id=tax.term_id  where tax.taxonomy ='language'  and t.slug=%s and p.post_name=%s", $language_code, $name));
					}
					else{
					$get_result =  $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->prefix}posts WHERE post_name = %s AND post_type = %s AND post_status != 'trash' order by ID DESC ", $name, $post_type));	
					}
				}
				if($check == 'PRODUCTSKU'){
					$sku = $post_values['PRODUCTSKU'];
					if($sitepress != null && isset($wpml_values['language_code']) && !empty($wpml_values['language_code'])) {
						$language_code = $wpml_values['language_code'];
						$get_result =  $wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->prefix}posts p join {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id inner join {$wpdb->prefix}icl_translations icl ON pm.post_id = icl.element_id WHERE p.post_type = %s AND p.post_status != 'trash' and pm.meta_value = %s and icl.language_code = %s", $post_type, $sku, $language_code));               
					}
					elseif(isset($poly_values) && !empty($poly_values)){
						$language_code = $poly_values['language_code'];
						$get_result=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->prefix}posts as p inner join {$wpdb->prefix}postmeta pm ON p.ID=pm.post_id inner join {$wpdb->prefix}term_relationships as tr ON tr.object_id=p.ID inner join {$wpdb->prefix}term_taxonomy as tax on tax.term_taxonomy_id=tr.term_taxonomy_id inner join {$wpdb->prefix}terms as t on t.term_id=tax.term_id  where tax.taxonomy ='language'  and t.slug=%s and p.post_name=%s and pm.meta_value = %s", $language_code, $name, $sku));
					}
					else{
						$get_result =  $wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->prefix}posts p join {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id WHERE p.post_type = %s AND p.post_status != 'trash' and pm.meta_value = %s ", $post_type, $sku));
					}
				}
				$update_based_on = in_array($update_based_on, array('normal', 'skip'), true) ? $update_based_on : 'normal';
				$duplicate_action = in_array($duplicate_action, array('skip', 'update', 'create'), true) ? $duplicate_action : 'skip';
				$get_result = isset($get_result) ? $get_result : array();
				$core_match_fields = array('ID', 'post_title', 'post_name', 'PRODUCTSKU');
				$has_match = is_array($get_result) && !empty($get_result);
				$product = null;
				$product_id = '';
				$type = 'WooCommerce Product';

				if ($update_based_on === 'skip' && !empty($check) && in_array($check, $core_match_fields, true) && !$has_match) {
					$core_instance->detailed_log[$line_number]['Message'] = 'Skipped. No matching record found.';
					$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
					$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey_value );
					return array('MODE' => $mode);
				}

				if ($mode == 'Insert') {
					if ($has_match && !empty($check) && $duplicate_action === 'skip' && $update_based_on === 'normal') {
						$core_instance->detailed_log[$line_number]['Message'] = 'Skipped, Due to duplicate Product found!.';
						$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
						$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey_value );
						return array('MODE' => $mode);
					}
					if ($has_match && !empty($check) && $duplicate_action === 'update') {
						$product_id = $get_result[0]->ID;
						$product = wc_get_product($product_id);
						if ($product) {
							$this->apply_post_values_to_wc_product($product, $post_values);
							$product_id = $product->save();
							$core_instance->detailed_log[$line_number]['Message'] = 'Updated Product ID: ' . $product_id;
							$core_instance->detailed_log[$line_number]['state'] = 'Updated';
							$mode_of_affect = 'Updated';
							$this->update_import_log_count( $log_table_name, 'updated', $updated_count, $unikey_name, $unikey_value );
						}
					} elseif (!$has_match || empty($check) || $duplicate_action === 'create') {
						$created = $this->create_wc_product_from_post_values($post_values, $line_number, $core_instance, $unikey_value, $unikey_name, $unmatched_row, $type, $log_table_name, $created_count);
						if ($created === false) {
							return array('MODE' => $mode);
						}
						$product = $created['product'];
						$product_id = $created['product_id'];
						$mode_of_affect = 'Inserted';
					}
				}
				if ($mode == 'Update') {
					if ($has_match) {
						$product_id = $get_result[0]->ID;
						$product = wc_get_product($product_id);
						if ($product) {
							$this->apply_post_values_to_wc_product($product, $post_values);
							$product_id = $product->save();
							$core_instance->detailed_log[$line_number]['Message'] = 'Updated Product ID: ' . $product_id;
							$core_instance->detailed_log[$line_number]['state'] = 'Updated';
							$mode_of_affect = 'Updated';
							$this->update_import_log_count( $log_table_name, 'updated', $updated_count, $unikey_name, $unikey_value );
						}
					} elseif ($update_based_on === 'skip') {
						$core_instance->detailed_log[$line_number]['Message'] = 'Skipped. No matching record found.';
						$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
						$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey_value );
						return array('MODE' => $mode);
					} else {
						$created = $this->create_wc_product_from_post_values($post_values, $line_number, $core_instance, $unikey_value, $unikey_name, $unmatched_row, $type, $log_table_name, $created_count);
						if ($created === false) {
							return array('MODE' => $mode);
						}
						$product = $created['product'];
						$product_id = $created['product_id'];
						$mode_of_affect = 'Inserted';
					}
				}
			if(!empty($product) && !empty($product_id)){
				$woocommerce_meta_instance->woocommerce_meta_import_function($product_meta_data, '', $product_id, '', 'WooCommerce Product', $line_number, $header_array, $value_array, $mode, $hash_key,$attr_data);
			}
		}
	}catch (\Exception $e) {
		$core_instance->detailed_log[$line_number]['Message'] = $e->getMessage();
		$core_instance->detailed_log[$line_number]['state'] = 'Skipped';
		$this->update_import_log_count( $log_table_name, 'skipped', $skipped_count, $unikey_name, $unikey_value );
		return array('MODE' => $mode,'ID' => '');
	}
			$returnArr['ID'] = $product_id;
			$returnArr['MODE'] = $mode_of_affect;
			$returnArr['post_type'] = $post_type;
			if (!empty($post_values['post_author'])) {
				$returnArr['AUTHOR'] = isset($assigned_author) ? $assigned_author : '';
			}
			return $returnArr;
	}

	/**
	 * Apply mapped core values to an existing WooCommerce product object.
	 *
	 * @param \WC_Product $product
	 * @param array       $post_values
	 * @return \WC_Product
	 */
	private function apply_post_values_to_wc_product($product, $post_values)
	{
		if (!empty($post_values['post_title'])) {
			$product->set_name($post_values['post_title']);
		}
		if (!empty($post_values['post_name'])) {
			$product->set_slug(sanitize_title($post_values['post_name']));
		}
		if (!empty($post_values['post_excerpt']) && method_exists($product, 'set_short_description')) {
			$product->set_short_description($post_values['post_excerpt']);
		}
		if (isset($post_values['post_content']) && $post_values['post_content'] !== null && $post_values['post_content'] !== '') {
			$content = html_entity_decode($post_values['post_content']);
			$content = str_replace('\n', "\n", $content);
			$product->set_description($content);
		}
		$prod_sku = $post_values['PRODUCTSKU'] ?? null;
		if (!empty($prod_sku) && method_exists($product, 'set_sku')) {
			$existing_id = wc_get_product_id_by_sku(wc_clean($prod_sku));
			if ($existing_id == 0 || (int) $existing_id === (int) $product->get_id()) {
				$product->set_sku(wc_clean($prod_sku));
			}
		}
		if (!empty($post_values['post_status'])) {
			$product->set_status($post_values['post_status']);
		}
		return $product;
	}

	/**
	 * Create a new WooCommerce product from mapped post values.
	 *
	 * @return array|false Product payload or false on failure.
	 */
	private function create_wc_product_from_post_values($post_values, $line_number, $core_instance, $unikey_value, $unikey_name, $unmatched_row, $type, $log_table_name, $created_count)
	{
		global $wpdb;
		$post_values['produc_type'] = isset($post_values['product_type']) ? $post_values['product_type'] : 'simple';
		$pt = (int) ($post_values['product_type'] ?? 1);
		$product_type = $pt;
		if ($pt === 1) {
			$product_type = 'simple';
		} elseif ($pt === 2) {
			$product_type = 'grouped';
		} elseif ($pt === 3) {
			$product_type = 'external';
		} elseif ($pt === 4) {
			$product_type = 'variable';
		} elseif ($pt === 5) {
			$product_type = 'subscription';
		} elseif ($pt === 6) {
			$product_type = 'variable-subscription';
		} elseif ($pt === 7) {
			$product_type = 'bundle';
		} elseif ($pt === 8) {
			$product_type = 'variation';
		} elseif ($pt === 9) {
			$product_type = 'jet_booking';
		}

		if ($product_type == 'external') {
			$product = new \WC_Product_External();
		} elseif ($product_type == 'variable') {
			$product = new \WC_Product_Variable();
		} elseif ($product_type == 'grouped') {
			$product = new \WC_Product_Grouped();
		} elseif ($product_type == 'variation') {
			$product = new \WC_Product_Variation();
		} elseif ($product_type == 'jet_booking' && class_exists('\WC_Product_Jet_Booking')) {
			$product = new \WC_Product_Jet_Booking();
		} else {
			$product = new \WC_Product_Simple();
		}

		$product = $this->apply_post_values_to_wc_product($product, $post_values);
		$prod_sku = $post_values['PRODUCTSKU'] ?? null;
		if (!empty($prod_sku)) {
			$sku_check = wc_get_product_id_by_sku(wc_clean($prod_sku));
			if ($sku_check == 0) {
				$product->set_sku(wc_clean($prod_sku));
			}
		}
		$product_id = $product->save();
		$core_instance->detailed_log[$line_number]['Type_of_Product'] = $product_type;
		wp_set_object_terms($product_id, $product_type, 'product_type');

		if ($unmatched_row == 'true') {
			$post_entries_table = $wpdb->prefix . 'post_entries_table';
			$file_table_name = $wpdb->prefix . 'smackcsv_file_events';
			$allowed_keys = array( 'hash_key', 'templatekey' );
			if ( ! in_array( $unikey_name, $allowed_keys, true ) ) {
				$unikey_name = 'hash_key';
			}
			$get_id = $wpdb->get_results($wpdb->prepare("SELECT file_name FROM $file_table_name WHERE `$unikey_name` = %s", $unikey_value));
			$file_name = $get_id[0]->file_name;
			$wpdb->insert(
				$post_entries_table,
				array(
					'ID' => absint($product_id),
					'type' => $type,
					'file_name' => $file_name,
					'status' => 'Inserted',
				),
				array( '%d', '%s', '%s', '%s' )
			);
		}

		$core_instance->detailed_log[$line_number]['Message'] = 'Inserted Product ID: ' . $product_id;
		$core_instance->detailed_log[$line_number]['state'] = 'Inserted';
		$this->update_import_log_count( $log_table_name, 'created', $created_count, $unikey_name, $unikey_value );

		return array(
			'product' => $product,
			'product_id' => $product_id,
		);
	}

	public function woocommerce_variations_import($data_array, $mode, $check, $unikey, $unikey_name, $line_number, $variation_count)
	{
		global $wpdb, $core_instance;
		$logTableName = $wpdb->prefix . "import_detail_log";
		$helpers_instance = ImportHelpers::getInstance();
		$updated_row_counts = $helpers_instance->update_count($unikey, $unikey_name);
		$skipped_count = $updated_row_counts['skipped'];

		$productInfo = '';
		$returnArr = array('MODE' => $mode, 'ID' => '');
		$product_id = isset($data_array['PRODUCTID']) ? $data_array['PRODUCTID'] : '';
		$parent_sku = isset($data_array['PARENTSKU']) ? $data_array['PARENTSKU'] : '';
		$variation_id =  isset($data_array['VARIATIONID']) ? $data_array['VARIATIONID'] : '';
		$variation_sku = isset($data_array['VARIATIONSKU']) ? $data_array['VARIATIONSKU'] : '';
		if ($product_id != '' && ($variation_sku == '' || $variation_id == '')) {
			if ($variation_sku != '') {
				$variation_condition = 'update_using_variation_sku';
			} else if ($variation_id != '') {
				$variation_condition = 'update_using_variation_id';
			} else {
				$variation_condition = 'insert_using_product_id';
			}
		} elseif ($parent_sku != '') {
			$get_parent_product_id = $wpdb->get_results($wpdb->prepare("select id from {$wpdb->prefix}posts where post_status != 'trash' and post_type = 'product' and id in (select post_id from {$wpdb->prefix}postmeta where meta_value = %s)", $parent_sku));
			$count = count($get_parent_product_id);
			$key = 0;
			if (! empty($get_parent_product_id)) {
				$product_id = $get_parent_product_id[$key]->id;
				//Check whether the product is variable type
				$term_details = wp_get_object_terms($product_id, 'product_type');
				if ((!empty($term_details)) && ($term_details[0]->name != 'variable')) {

					$core_instance->detailed_log[$line_number]['Message'] = "Skipped,Product is not variable in type.";
					$this->update_import_log_count( $logTableName, 'skipped', $skipped_count, $unikey_name, $unikey );
					return array('MODE' => $mode, 'ID' => '');
				}
			} else {
				$product_id = '';
				$core_instance->detailed_log[$line_number]['Message'] = "Skipped,Product is not available.";
				$this->update_import_log_count( $logTableName, 'skipped', $skipped_count, $unikey_name, $unikey );
				return array('MODE' => $mode, 'ID' => '');
			}
			if ($mode == 'Insert') {
				$variation_condition = 'insert_using_product_sku';
			}
			if ($variation_sku != '' && $mode == 'Update') {
				$variation_condition = 'update_using_variation_sku';
			}
			if ($variation_id != '') {
				$variation_condition = 'update_using_variation_id';
			}
		} elseif ($parent_sku == '' && ($variation_sku != '' || $variation_id != '')) {
			if ($variation_sku != '') {
				$variation_condition = 'update_using_variation_sku';
			}
			if ($variation_id != '') {
				$variation_condition = 'update_using_variation_id';
			}
		}

		if ($variation_sku != '' && $variation_id != '') {
			update_post_meta($variation_id, '_sku', $variation_sku);
		}

		if ($product_id != '') {
			$is_exist_product = $wpdb->get_results($wpdb->prepare("select * from {$wpdb->prefix}posts where ID = %d", $product_id));
			if (!empty($is_exist_product) && $is_exist_product[0]->ID == $product_id) {
				$productInfo = $is_exist_product[0];
			} else {
				#return $returnArr;
			}
		}

		if (isset($variation_condition)) {
			switch ($variation_condition) {
				case 'update_using_variation_id_and_sku':

					$get_variation_data = $wpdb->get_results($wpdb->prepare("select DISTINCT pm.post_id from {$wpdb->prefix}posts p join {$wpdb->prefix}postmeta pm on p.ID = pm.post_id where p.ID = %d and p.post_type = %s and pm.meta_value = %s", $variation_id, 'product_variation', $variation_sku));

					if (! empty($get_variation_data) && $get_variation_data[0]->post_id == $variation_id) {
						$returnArr = $this->importVariationData($product_id, $variation_id, 'update_using_variation_id_and_sku', $unikey, $unikey_name, $line_number, $variation_count, $get_variation_data);
					} else {
						$returnArr = $this->importVariationData($product_id, $variation_id, 'default', $unikey, $unikey_name, $line_number, $variation_count, $productInfo);
					}
					break;
				case 'update_using_variation_id':

					$get_variation_data = $wpdb->get_results($wpdb->prepare("select * from {$wpdb->prefix}posts where ID = %d and post_type = %s", $variation_id, 'product_variation'));
					if (! empty($get_variation_data) && $get_variation_data[0]->ID == $variation_id) {
						$returnArr = $this->importVariationData($product_id, $variation_id, 'update_using_variation_id', $unikey, $unikey_name, $line_number, $variation_count, $get_variation_data);
					} else {
						$returnArr = $this->importVariationData($product_id, $variation_id, 'default', $unikey, $unikey_name, $line_number, $variation_count, $productInfo);
					}
					break;
				case 'update_using_variation_sku':
					$variation_data = $wpdb->get_results($wpdb->prepare("select post_id from {$wpdb->prefix}postmeta where meta_value = %s and post_id in (select id from {$wpdb->prefix}posts where post_type = 'product_variation' and post_status != 'trash' and post_parent = %d)", $variation_sku, absint($product_id)));
					$variation_id = !empty($variation_data) ? $variation_data[0]->post_id : "";
					if ($variation_id)
						$get_variation_data = $wpdb->get_results($wpdb->prepare("select * from {$wpdb->prefix}posts where ID = %d and post_type = %s", $variation_id, 'product_variation'));
					else
						$get_variation_data = [];
					if (! empty($get_variation_data) && $get_variation_data[0]->ID == $variation_id) {
						$returnArr = $this->importVariationData($product_id, $variation_id, 'update_using_variation_sku', $unikey, $unikey_name, $line_number, $variation_count, $get_variation_data);
					} else {
						$returnArr = $this->importVariationData($product_id, $variation_id, 'default', $unikey, $unikey_name, $line_number, $variation_count, $productInfo);
					}
					break;
				case 'insert_using_product_id':
					$returnArr = $this->importVariationData($product_id, $variation_id, 'insert_using_product_id', $unikey, $unikey_name, $line_number, $variation_count,  $productInfo);
					break;
				case 'insert_using_product_sku':
					$returnArr = $this->importVariationData($product_id, $variation_id, 'insert_using_product_sku', $unikey, $unikey_name, $line_number, $variation_count, $productInfo);
					break;
				default:
					$returnArr = $this->importVariationData($product_id, $variation_id, 'default', $unikey, $unikey_name, $line_number, $variation_count, $productInfo);
					break;
			}
		}
		return $returnArr;
	}

	public function importVariationData($product_id, $variation_id, $type, $unikey, $unikey_name, $line_number, $variation_count, $exist_variation_data = array())
	{
		global $wpdb;
		$helpers_instance = ImportHelpers::getInstance();
		global $core_instance;
		$logTableName = $wpdb->prefix . "import_detail_log";

		$updated_row_counts = $helpers_instance->update_count($unikey, $unikey_name);
		$created_count = $updated_row_counts['created'];
		$updated_count = $updated_row_counts['updated'];
		$skipped_count = $updated_row_counts['skipped'];
		if ($type == 'default' || $type == 'insert_using_product_id' || $type == 'insert_using_product_sku') {

			$get_count_of_variations = $wpdb->get_results($wpdb->prepare("select count(*) as variations_count from {$wpdb->prefix}posts where post_parent = %d and post_type = %s", $product_id, 'product_variation'));
			$variations_count = $get_count_of_variations[0]->variations_count;
			$menu_order_count = 0;
			if ($variations_count == 0) {
				$variations_count = '';
				$menu_order = 0;
			} else {
				$variations_count = $variations_count + 1;
				$menu_order_count = $variations_count - 1;
				$variations_count = '-' . $variations_count;
			}
			$get_variation_data = $wpdb->get_results($wpdb->prepare("select * from {$wpdb->prefix}posts where ID = %d", $product_id));
			foreach ($get_variation_data as $key => $val) {

				if ($product_id == $val->ID) {

					$variation_data = array();
					$variation_data['post_title'] = $val->post_title;
					$variation_data['post_date'] = $val->post_date;
					$variation_data['post_type'] = 'product_variation';
					$variation_data['post_status'] = 'publish';
					$variation_data['comment_status'] = 'closed';
					$variation_data['ping_status'] = 'closed';
					$variation_data['menu_order'] = $menu_order_count;
					$variation_data['post_name'] = 'product-' . $val->ID . '-variation' . $variations_count;
					$variation_data['post_parent'] = $val->ID;
				}
			}
			$variationid = wp_insert_post($variation_data);
			if (empty($variation_count)) {
				$core_instance->detailed_log[$line_number]['Message'] = 'Inserted Variation ID: ' . $variationid;
			} else {
				$parent_id = $wpdb->get_var($wpdb->prepare("SELECT post_parent FROM {$wpdb->prefix}posts WHERE id = %d ", absint($variationid)));
				$core_instance->detailed_log[$line_number]['Message'] = 'Inserted Product ID: ' . $parent_id . '   Inserted Variation ID: ' . $variationid;
			}
			$this->update_import_log_count( $logTableName, 'created', $created_count, $unikey_name, $unikey );
			$returnArr = array('ID' => $variationid, 'MODE' => 'Inserted');
			return $returnArr;
		} elseif ($type == 'update_using_variation_id' || $type == 'update_using_variation_sku' || $type == 'update_using_variation_id_and_sku') {

			$core_instance->detailed_log[$line_number]['Message'] = 'Updated Variation ID: ' . $variation_id;
			$this->update_import_log_count( $logTableName, 'updated', $updated_count, $unikey_name, $unikey );

			$returnArr = array('ID' => $variation_id, 'MODE' => 'Updated');
			return $returnArr;
		}
	}
}

global $uci_woocomm_instance;
$uci_woocomm_instance = new WooCommerceCoreImport;
