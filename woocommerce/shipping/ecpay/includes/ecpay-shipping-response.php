<?php
defined('RY_WT_VERSION') OR exit('No direct script access allowed');

class RY_ECPay_Shipping_Response extends RY_ECPay_Shipping_Api {
	public static function init() {
		add_action('woocommerce_api_ry_ecpay_shipping_callback', [__CLASS__, 'check_shipping_callback']);
		add_action('valid-shipping-request', [__CLASS__, 'shipping_callback']);
	}

	public static function check_shipping_callback() {
		$ipn_info = wp_unslash($_POST);
		if( !empty($_POST) ) {
			$ipn_info = wp_unslash($_POST);
			if( self::ipn_request_is_valid($ipn_info) ) {
				do_action('valid-shipping-request', $ipn_info);
			} else {
				self::die_error();
			}
		}
	}

	protected static function ipn_request_is_valid($ipn_info) {
		RY_ECPay_Shipping::log('IPN request: ' . var_export($ipn_info, true));

		list($MerchantID, $HashKey, $HashIV, $CVS_type) = RY_ECPay_Shipping::get_ecpay_api_info();
		$check_value = self::get_check_value($ipn_info);
		$ipn_info_check_value = self::generate_check_value($ipn_info, $HashKey, $HashIV, 'md5');
		if( $check_value == $ipn_info_check_value ) {
			return true;
		} else {
			RY_ECPay_Shipping::log('IPN request check failed. Response:' . $check_value . ' Self:' . $ipn_info_check_value, 'error');
			return false;
		}
	}

	public static function shipping_callback($ipn_info) {
		$order_id = self::get_order_id($ipn_info, RY_WT::get_option('ecpay_shipping_order_prefix'));
		if( $order = wc_get_order($order_id) ) {
			$shipping_list = $order->get_meta('_ecpay_shipping_info', true);
			if( !is_array($shipping_list) ) {
				$shipping_list = [];
			}
			if( !isset($shipping_list[$ipn_info['AllPayLogisticsID']]) ) {
				$shipping_list[$ipn_info['AllPayLogisticsID']] = [];
			}
			$old_info = $shipping_list[$ipn_info['AllPayLogisticsID']];
			$shipping_list[$ipn_info['AllPayLogisticsID']]['status'] = self::get_status($ipn_info);
			$shipping_list[$ipn_info['AllPayLogisticsID']]['status_msg'] = self::get_status_msg($ipn_info);
			$shipping_list[$ipn_info['AllPayLogisticsID']]['edit'] = (string) new WC_DateTime();

			if( isset($shipping_list[$ipn_info['AllPayLogisticsID']]['ID']) ) {				
				$order->update_meta_data('_ecpay_shipping_info', $shipping_list);
				$order->save_meta_data();
			}

			if( 'yes' === RY_WT::get_option('ecpay_shipping_log_status_change', 'no') ) {
				if( isset($old_info['status']) ) {
					if( $old_info['status'] != $shipping_list[$ipn_info['AllPayLogisticsID']]['status'] ) {
						$order->add_order_note(sprintf(
							/* translators: 1: EcPay ID 2: Old status 3: New status */
							__('%1$s shipping status from %2$s to %3$s', 'ry-woocommerce-tools'),
							$ipn_info['AllPayLogisticsID'],
							$old_info['status_msg'],
							$shipping_list[$ipn_info['AllPayLogisticsID']]['status_msg']
						));
					}
				}
			}

			if( method_exists(__CLASS__, 'shipping_status_' . $shipping_list[$ipn_info['AllPayLogisticsID']]['status']) ) {
				call_user_func([__CLASS__, 'shipping_status_' . $shipping_list[$ipn_info['AllPayLogisticsID']]['status']], $order, $ipn_info);
			}

			do_action('ry_ecpay_shipping_response_status_' . $shipping_list[$ipn_info['AllPayLogisticsID']]['status'], $ipn_info, $order);
			do_action('ry_ecpay_shipping_response', $ipn_info, $order);

			self::die_success();
		}

		RY_ECPay_Shipping::log('Order not found', 'error');
		self::die_error();
	}

	protected static function shipping_status_2063($order) {
		$order->update_status('ry-at-cvs');
	}

	protected static function shipping_status_2067($order) {
		if( 'yes' == RY_WT::get_option('ecpay_shipping_auto_completed', 'yes') ) {
			$order->update_status('completed');
		}
	}

	protected static function shipping_status_2073($order) {
		$order->update_status('ry-at-cvs');
	}

	protected static function shipping_status_3018($order) {
		$order->update_status('ry-at-cvs');
	}

	protected static function shipping_status_2074($order) {
		$order->update_status('ry-out-cvs');
	}

	protected static function shipping_status_3020($order) {
		$order->update_status('ry-out-cvs');
	}

	protected static function shipping_status_3022($order) {
		if( 'yes' == RY_WT::get_option('ecpay_shipping_auto_completed', 'yes') ) {
			$order->update_status('completed');
		}
	}
}
