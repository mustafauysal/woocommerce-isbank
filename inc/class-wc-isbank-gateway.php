<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Isbank_Gateway extends WC_Payment_Gateway {

	private $api_url = 'https://sanalpos.isbank.com.tr/fim/api';
	private $est3Dgate_url = 'https://sanalpos.isbank.com.tr/fim/est3Dgate';
	private $currency_codes = array(
		'TRY' => '949',
		'USD' => '840',
		'EUR' => '978',
		'GBP' => '826',
		'JPY' => '392',
	);

	public function __construct() {
		$this->id                 = 'isbank';
		$this->title              = __( 'Kredi Kartı', 'wc-isbank' );
		$this->method_title       = __( 'Türkiye İş Bankası - WooCommerce', 'wc-isbank' );
		$this->method_description = '';
		$this->supports           = array( 'products', 'refunds' );

		$this->form_fields = WC_Isbank_Gateway_Fields::init_fields();
		$this->init_settings();

		$test_mode = $this->get_option( 'test' );

		if ( $test_mode == 'yes' ) {
			$this->api_url       = 'https://entegrasyon.asseco-see.com.tr/fim/api';
			$this->est3Dgate_url = 'https://entegrasyon.asseco-see.com.tr/fim/est3Dgate';
		}

		add_action(
			'woocommerce_receipt_' . $this->id,
			array( $this, 'receipt_form' )
		);

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);

		add_action( 'woocommerce_api_wc_gateway_isbank', array( $this, 'api_response' ) );

		$this->enabled           = $this->get_option( 'enabled' );
		$this->client_id         = $this->get_option( 'client_id' );
		$this->store_key         = $this->get_option( 'store_key' );
		$this->api_user          = $this->get_option( 'api_user' );
		$this->api_user_password = $this->get_option( 'api_user_password' );
	}

	public function receipt_form( $order_id ) {

		// Sepet bos ise odeme formu olusturmak yerine hata mesaji goster
		if ( WC()->cart && WC()->cart->is_empty() ) {
			wc_add_notice( sprintf( __( 'Oturumunuzun süresi doldu. <a href="%s" class="wc-backward">Mağazaya geri dön</a>', 'wc-isbank' ), esc_url( wc_get_page_permalink( 'shop' ) ) ), 'error' );

			return;
		}

		$args = array(
			'form_id'    => $this->id,
			'client_id'  => $this->client_id,
			'store_key'  => $this->store_key,
			'action_url' => $this->est3Dgate_url,
			'order_id'   => $order_id,
		);

		echo WC_Isbank_Gateway_Form::init_form( $args );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true )
		);
	}

	public function api_response() {
		$posted = wp_unslash( $_POST );

		if ( empty( $posted ) ) {
			wp_die( esc_html__( 'Bu ödeme bağlantısı doğrudan açılamaz.', 'wc-isbank' ) );
		}

		$order_id = isset( $posted['oid'] ) ? absint( $posted['oid'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			wc_add_notice( __( 'Ödeme sonucundaki sipariş bilgisi okunamadı. Lütfen tekrar deneyin.', 'wc-isbank' ), 'error' );
			$this->redirect_to_checkout();
		}

		if ( ! $this->verify_callback_hash( $posted ) ) {
			$this->fail_order(
				$order,
				__( 'Ödeme doğrulaması güvenlik nedeniyle tamamlanamadı. Lütfen tekrar deneyin.', 'wc-isbank' ),
				__( 'İş Bankası callback hash doğrulaması başarısız.', 'wc-isbank' )
			);
		}

		if ( ! $this->is_expected_callback( $order, $posted ) ) {
			$this->fail_order(
				$order,
				__( 'Ödeme bilgileri siparişle eşleşmedi. Lütfen tekrar deneyin.', 'wc-isbank' ),
				__( 'İş Bankası callback tutar, para birimi veya üye işyeri numarası eşleşmedi.', 'wc-isbank' )
			);
		}

		$md_status = isset( $posted['mdStatus'] ) ? (string) wc_clean( $posted['mdStatus'] ) : '';

		if ( ! in_array( $md_status, array( '1', '2', '3', '4' ), true ) ) {
			$this->fail_order(
				$order,
				$this->get_customer_error_message( $posted, __( '3D doğrulama tamamlanamadı. Kart bilgilerinin doğruluğunu kontrol edip tekrar deneyin.', 'wc-isbank' ) ),
				sprintf( /* translators: %s: 3D status. */ __( 'İş Bankası 3D doğrulama başarısız. mdStatus: %s', 'wc-isbank' ), $md_status )
			);
		}

		$xml_data = array(
			'Name'                    => $this->api_user,
			'Password'                => $this->api_user_password,
			'ClientId'                => $this->client_id,
			'IPAddress'               => $this->get_customer_ip(),
			'OrderId'                 => $order->get_id(),
			'Type'                    => 'Auth',
			'Number'                  => isset( $posted['md'] ) ? wc_clean( $posted['md'] ) : '',
			'Amount'                  => $this->format_amount( $order->get_total() ),
			'Currency'                => $this->get_currency_code( $order->get_currency() ),
			'PayerTxnId'              => isset( $posted['xid'] ) ? wc_clean( $posted['xid'] ) : '',
			'PayerSecurityLevel'      => isset( $posted['eci'] ) ? wc_clean( $posted['eci'] ) : '',
			'PayerAuthenticationCode' => isset( $posted['cavv'] ) ? wc_clean( $posted['cavv'] ) : '',
		);

		$request = new WC_Isbank_Request( $this->api_url );
		$result  = $request->send( $xml_data );

		if ( is_wp_error( $result ) ) {
			$this->fail_order(
				$order,
				__( 'Banka ile bağlantı kurulamadı. Kartınızdan tahsilat yapılmadı; lütfen biraz sonra tekrar deneyin.', 'wc-isbank' ),
				sprintf( /* translators: %s: gateway error message. */ __( 'İş Bankası API bağlantı hatası: %s', 'wc-isbank' ), $result->get_error_message() )
			);
		}

		$response = isset( $result->Response ) ? (string) $result->Response : '';

		if ( 'Approved' === $response ) {
			$order->payment_complete( isset( $result->TransId ) ? (string) $result->TransId : '' );
			$order->add_order_note( __( 'İş Bankası ödemesi başarıyla tamamlandı.', 'wc-isbank' ) );

			if ( function_exists( 'WC' ) && WC()->cart ) {
				WC()->cart->empty_cart();
			}

			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		$this->fail_order(
			$order,
			$this->get_customer_error_message( $result, __( 'Ödeme banka tarafından onaylanmadı. Kart bilgilerini kontrol edip tekrar deneyin veya farklı bir kart kullanın.', 'wc-isbank' ) ),
			$this->get_gateway_note( $result )
		);
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		$xml_data = array(
			'Name'     => $this->api_user,
			'Password' => $this->api_user_password,
			'ClientId' => $this->client_id,
			'OrderId'  => $order_id,
			'Type'     => 'Credit',
			'Amount'   => $this->format_amount( $amount ),
			'Currency' => $order ? $this->get_currency_code( $order->get_currency() ) : '949'
		);

		$request = new WC_Isbank_Request( $this->api_url );
		$result  = $request->send( $xml_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = (string) $result->Response;

		if ( 'Approved' === $response ) {
			return true;
		}

		return false;
	}

	private function verify_callback_hash( $posted ) {
		$hash = $this->get_posted_value( $posted, 'HASH' );
		$hash = $hash ? $hash : $this->get_posted_value( $posted, 'hash' );

		if ( empty( $hash ) ) {
			return false;
		}

		if ( hash_equals( $hash, WC_Isbank_Gateway_Form::generate_hash( $posted, $this->store_key ) ) ) {
			return true;
		}

		return $this->verify_legacy_callback_hash( $posted, $hash );
	}

	private function verify_legacy_callback_hash( $posted, $hash ) {
		$hash_params = $this->get_posted_value( $posted, 'HASHPARAMS' );
		$hash_values = $this->get_posted_value( $posted, 'HASHPARAMSVAL' );

		if ( empty( $hash_params ) ) {
			return false;
		}

		$params_value = '';
		$keys         = explode( ':', $hash_params );

		foreach ( $keys as $key ) {
			if ( '' === $key ) {
				continue;
			}

			$params_value .= $this->get_posted_value( $posted, $key );
		}

		$calculated_hash = base64_encode( pack( 'H*', sha1( $params_value . $this->store_key ) ) );

		return hash_equals( (string) $hash_values, $params_value ) && hash_equals( $hash, $calculated_hash );
	}

	private function is_expected_callback( WC_Order $order, $posted ) {
		$client_id = $this->get_posted_value( $posted, 'clientid' );
		$amount    = $this->get_posted_value( $posted, 'amount' );
		$currency  = $this->get_posted_value( $posted, 'currency' );

		return hash_equals( (string) $this->client_id, (string) $client_id )
			&& hash_equals( $this->format_amount( $order->get_total() ), $this->format_amount( $amount ) )
			&& hash_equals( $this->get_currency_code( $order->get_currency() ), (string) $currency );
	}

	private function get_posted_value( $posted, $key ) {
		foreach ( $posted as $posted_key => $value ) {
			if ( strtolower( $posted_key ) === strtolower( $key ) ) {
				return is_scalar( $value ) ? wc_clean( (string) $value ) : '';
			}
		}

		return '';
	}

	private function fail_order( WC_Order $order, $customer_message, $order_note ) {
		wc_add_notice( $customer_message, 'error' );
		$order->update_status( 'failed', $order_note );
		$this->redirect_to_checkout();
	}

	private function redirect_to_checkout() {
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	private function get_customer_error_message( $source, $fallback ) {
		$error_message = '';

		if ( is_array( $source ) ) {
			$error_message = isset( $source['ErrMsg'] ) ? (string) $source['ErrMsg'] : '';
			$error_message = $error_message ? $error_message : ( isset( $source['mdErrorMsg'] ) ? (string) $source['mdErrorMsg'] : '' );
		} elseif ( is_object( $source ) ) {
			$error_message = isset( $source->ErrMsg ) ? (string) $source->ErrMsg : '';
		}

		if ( empty( $error_message ) ) {
			return $fallback;
		}

		return sprintf(
			/* translators: %s: bank error message. */
			__( 'Ödeme tamamlanamadı: %s', 'wc-isbank' ),
			wc_clean( $error_message )
		);
	}

	private function get_gateway_note( $result ) {
		$response = isset( $result->Response ) ? (string) $result->Response : '';
		$code     = isset( $result->ProcReturnCode ) ? (string) $result->ProcReturnCode : '';
		$message  = isset( $result->ErrMsg ) ? (string) $result->ErrMsg : '';

		return sprintf(
			/* translators: 1: gateway response, 2: return code, 3: error message. */
			__( 'İş Bankası ödeme reddi. Response: %1$s, ProcReturnCode: %2$s, ErrMsg: %3$s', 'wc-isbank' ),
			$response,
			$code,
			$message
		);
	}

	private function get_customer_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? wc_clean( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	private function get_currency_code( $currency ) {
		return isset( $this->currency_codes[ $currency ] ) ? $this->currency_codes[ $currency ] : '949';
	}

	private function format_amount( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}
}
