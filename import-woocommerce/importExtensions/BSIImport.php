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

class BSIImport extends ImportHelpers
{
	private static $woocommerce_core_instance = null, $media_instance,$woocommerce_meta_instance;

	public static function getInstance()
	{

		if (BSIImport::$woocommerce_core_instance == null) {
			BSIImport::$woocommerce_core_instance = new BSIImport;
			BSIImport::$woocommerce_meta_instance = new WooCommerceMetaImport;
			BSIImport::$media_instance = new MediaHandling();
			return BSIImport::$woocommerce_core_instance;
		}
		return BSIImport::$woocommerce_core_instance;
	}
	public function set_bsi_values($header_array ,$value_array , $map, $post_id , $type){
		$post_values = [];
		$helpers_instance = ImportHelpers::getInstance();	
		$post_values = $helpers_instance->get_header_values($map , $header_array , $value_array);

		$this->bsi_import_function($post_values, $post_id);    
	}

	public function bsi_import_function($data_array, $uID){
		foreach( $data_array as $daKey => $daVal ) {
			if(strpos($daKey, 'msi_') === 0) {
				$msi_custom_key = substr($daKey, 4);
				$msi_shipping_array[$msi_custom_key] = $daVal;
			} elseif(strpos($daKey, 'mbi_') === 0) {
				$mbi_custom_key = substr($daKey, 4);
				$mbi_billing_array[$mbi_custom_key] = $daVal;
			} else {
				update_user_meta($uID, $daKey, $daVal);
			}
		}
		//Import MarketPress Shipping Info
		if (!empty ($msi_shipping_array)) {
			$custom_key = 'mp_shipping_info';
			update_user_meta($uID, $custom_key, $msi_shipping_array);
		}
		//Import MarketPress Billing Info
		if (!empty ($mbi_billing_array)) {
			$custom_key = 'mp_billing_info';
			update_user_meta($uID, $custom_key, $mbi_billing_array);
		}

        $user_data = get_userdata($uID);
    
        if ($user_data) {
            $customer = new \WC_Customer($uID);
            $customer->set_date_created(time()); // Set registration date

            // Set billing details dynamically
            $customer->set_billing_first_name($user_data->first_name);
            $customer->set_billing_last_name($user_data->last_name);
            $customer->set_billing_email($user_data->user_email);
            $customer->save();
        }
	}
}
global $customer_billing_class;
$customer_billing_class = new BSIImport();