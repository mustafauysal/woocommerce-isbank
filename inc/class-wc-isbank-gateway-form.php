<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Isbank_Gateway_Form {

	public static function init_form( $args ) {
		$form     = new WC_Payment_Gateway_CC();
		$form->id = $args['form_id'];

		$order = wc_get_order( $args['order_id'] );

		if ( ! $order ) {
			return '<div class="woocommerce-error">' . esc_html__( 'Ödeme başlatılamadı. Lütfen siparişi tekrar oluşturun.', 'wc-isbank' ) . '</div>';
		}

		$return_url = WC()->api_request_url( 'WC_Gateway_Isbank' );
		$amount     = number_format( (float) $order->get_total(), 2, '.', '' );
		$rnd        = wp_generate_password( 20, false, false );
		$params     = array(
			'clientid'      => $args['client_id'],
			'amount'        => $amount,
			'oid'           => $args['order_id'],
			'okUrl'         => $return_url,
			'failUrl'       => $return_url,
			'rnd'           => $rnd,
			'storetype'     => '3D',
			'lang'          => self::get_language_code(),
			'currency'      => self::get_currency_code( $order->get_currency() ),
			'islemtipi'     => 'Auth',
			'taksit'        => '',
			'hashAlgorithm' => 'ver3',
			'encoding'      => 'UTF-8',
		);
		$params['hash'] = self::generate_hash( $params, $args['store_key'] );

		$form_css = 'wc-isbank-checkout woocommerce-checkout';
		$form_css = apply_filters( 'woocoomerce_isbank_css', $form_css );

		wp_enqueue_script( 'wc-credit-card-form' );
		ob_start();
		?>
        <form action="<?php echo esc_url( $args['action_url'] ); ?>" class="<?php echo esc_attr( $form_css ); ?>" method="post" accept-charset="UTF-8">
            <div id="payment" class="woocommerce-checkout-payment">
                <ul class="wc_payment_methods payment_methods methods">
                    <li class="wc_payment_method payment_method_cod">
                        <div class="payment_box payment_method_isbank">
                            <fieldset id="wc-isbank-cc-form" class='wc-credit-card-form wc-payment-form'>

                                <p class="form-row form-row-wide">
                                    <label for="isbank-card-number">
                                        <?php echo __( 'Kart numarası', 'wc-isbank' ); ?>
                                        <span class="required">*</span>
                                    </label>

                                    <input id="isbank-card-number" class="input-text wc-credit-card-form-card-number"
                                           inputmode="numeric" autocomplete="cc-number" autocorrect="no"
                                           autocapitalize="no" spellcheck="no" type="tel"
                                           placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;"
                                           name="pan"/>
                                </p>

                                <p class="form-row form-row-first">
                                    <label for="isbank-card-expiry">
                                        <?php echo __( 'Son kullanma tarihi (AA / YYYY)', 'wc-isbank' ); ?>
                                        <span class="required">*</span>
                                    </label>

                                    <input id="isbank-card-expiry"
                                           class="input-text wc-credit-card-form-card-expiry"
                                           inputmode="numeric" autocomplete="cc-exp" autocorrect="no"
                                           autocapitalize="no" spellcheck="no" type="tel" placeholder="MM/YYYY"/>
                                </p>
                                <p class="form-row form-row-last">
                                    <label for="isbank-card-cvc">
                                        <?php echo __( 'Güvenlik Kodu', 'wc-isbank' ); ?>
                                        <span class="required">*</span>
                                    </label>

                                    <input id="isbank-card-cvc" class="input-text wc-credit-card-form-card-cvc"
                                           inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no"
                                           spellcheck="no" type="tel" maxlength="4" placeholder="CVC"
                                           name="cv2" style="width:75px"/>
                                </p>
                                <div class="clear"></div>

                                <?php if ( 'USD' === $order->get_currency() ) : ?>
                                    <p class="wc-isbank-card-note">
                                        <?php echo esc_html__( 'İş Bankası üzerinden USD ödemelerde American Express kart kullanılamaz. USD siparişler için lütfen Visa veya Mastercard kullanın.', 'wc-isbank' ); ?>
                                    </p>
                                <?php endif; ?>

                                <?php foreach ( $params as $key => $value ) : ?>
                                    <input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>"/>
                                <?php endforeach; ?>
                            </fieldset>
                        </div>
                    </li>
                    <input type="submit" class="button alt" value="<?php echo __( 'Siparişi onayla', 'wc-isbank' ); ?>"/>
                </ul>
            </div>
        </form>
		<?php
		$html = ob_get_clean();

		return $html;
	}

	public static function generate_hash( $params, $store_key ) {
		$hash_params = array();

		foreach ( $params as $key => $value ) {
			if ( in_array( strtolower( $key ), array( 'hash', 'encoding', 'countdown' ), true ) ) {
				continue;
			}

			$hash_params[ strtolower( $key ) ] = self::escape_hash_value( is_scalar( $value ) ? (string) $value : '' );
		}

		ksort( $hash_params, SORT_STRING );

		$hash_string = implode( '|', $hash_params ) . '|' . self::escape_hash_value( (string) $store_key );

		return base64_encode( hash( 'sha512', $hash_string, true ) );
	}

	private static function escape_hash_value( $value ) {
		return str_replace( '|', '\\|', str_replace( '\\', '\\\\', $value ) );
	}

	private static function get_currency_code( $currency ) {
		$currencies = array(
			'TRY' => '949',
			'USD' => '840',
			'EUR' => '978',
			'GBP' => '826',
			'JPY' => '392',
		);

		return isset( $currencies[ $currency ] ) ? $currencies[ $currency ] : '949';
	}

	private static function get_language_code() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		return 0 === strpos( strtolower( $locale ), 'en' ) ? 'en' : 'tr';
	}

	public static function validate_fields() {
		$pan         = isset( $_POST['pan'] ) ? preg_replace( '/\D+/', '', wc_clean( wp_unslash( $_POST['pan'] ) ) ) : '';
		$expiry_raw  = isset( $_POST['card_expiry'] ) ? wc_clean( wp_unslash( $_POST['card_expiry'] ) ) : '';
		$expiry_raw  = $expiry_raw ? $expiry_raw : ( isset( $_POST['card_expriy'] ) ? wc_clean( wp_unslash( $_POST['card_expriy'] ) ) : '' );
		$card_cvc    = isset( $_POST['card_cvc'] ) ? preg_replace( '/\D+/', '', wc_clean( wp_unslash( $_POST['card_cvc'] ) ) ) : '';
		$card_cvc    = $card_cvc ? $card_cvc : ( isset( $_POST['cv2'] ) ? preg_replace( '/\D+/', '', wc_clean( wp_unslash( $_POST['cv2'] ) ) ) : '' );
		$order_id    = isset( $_POST['oid'] ) ? absint( $_POST['oid'] ) : 0;
		$order       = $order_id ? wc_get_order( $order_id ) : false;
		$settings    = get_option( 'woocommerce_isbank_settings', array() );
		$client_id   = isset( $settings['client_id'] ) ? $settings['client_id'] : '';
		$store_key   = isset( $settings['store_key'] ) ? $settings['store_key'] : '';
		$lang        = isset( $_POST['lang'] ) ? wc_clean( wp_unslash( $_POST['lang'] ) ) : self::get_language_code();
		$lang        = in_array( $lang, array( 'tr', 'en' ), true ) ? $lang : self::get_language_code();

		if ( empty( $pan ) || empty( $expiry_raw ) || empty( $card_cvc ) ) {
			wp_send_json( array(
				'result' => 'failure',
				'msg'    => __( 'Tüm ödeme bilgi alanlarını doldurmalısın.', 'wc-isbank' )
			) );
		}

		if ( ! $order || empty( $client_id ) || empty( $store_key ) ) {
			wp_send_json( array(
				'result' => 'failure',
				'msg'    => __( 'Ödeme ayarları veya sipariş bilgisi okunamadı. Lütfen tekrar deneyin.', 'wc-isbank' )
			) );
		}

		if ( strlen( $pan ) < 12 || strlen( $pan ) > 19 ) {
			wp_send_json( array(
				'result' => 'failure',
				'msg'    => __( 'Kart numarası hatalı görünüyor.', 'wc-isbank' )
			) );
		}

		if ( 'USD' === $order->get_currency() && self::is_amex_card( $pan ) ) {
			wp_send_json( array(
				'result' => 'failure',
				'msg'    => __( 'İş Bankası USD ödemelerde American Express kart kabul etmiyor. Lütfen Visa veya Mastercard ile tekrar deneyin.', 'wc-isbank' )
			) );
		}

		if ( strlen( $card_cvc ) < 3 || strlen( $card_cvc ) > 4 ) {
			wp_send_json( array(
				'result' => 'failure',
				'msg'    => __( 'Güvenlik kodu hatalı görünüyor.', 'wc-isbank' )
			) );
		}

		if ( ! preg_match( '/^\s*(\d{1,2})\s*\/\s*(\d{2}|\d{4})\s*$/', $expiry_raw, $matches ) ) {
			wp_send_json( array(
				'result' => 'failure',
				'msg'    => __( 'Son kullanma tarihini AA / YYYY formatında girmelisin.', 'wc-isbank' )
			) );
		}

		$month = (int) $matches[1];
		$year  = (int) $matches[2];
		$year  = $year < 100 ? 2000 + $year : $year;

		if ( $month < 1 || $month > 12 ) {
			wp_send_json( array(
				'result' => 'failure',
				'msg'    => __( 'Kart son kullanma ayı hatalı.', 'wc-isbank' )
			) );
		}

		$expiry_time = mktime( 23, 59, 59, $month + 1, 0, $year );

		if ( false === $expiry_time || $expiry_time < current_time( 'timestamp' ) ) {
			wp_send_json( array(
				'result' => 'failure',
				'msg'    => __( 'Vadesi dolmuş kart ile ödeme yapamazsın.', 'wc-isbank' )
			) );
		}

		$year_short = substr( (string) $year, -2 );
		$rnd        = isset( $_POST['rnd'] ) ? wc_clean( wp_unslash( $_POST['rnd'] ) ) : wp_generate_password( 20, false, false );
		$params     = array(
			'clientid'                         => $client_id,
			'amount'                           => number_format( (float) $order->get_total(), 2, '.', '' ),
			'oid'                              => $order->get_id(),
			'okUrl'                            => WC()->api_request_url( 'WC_Gateway_Isbank' ),
			'failUrl'                          => WC()->api_request_url( 'WC_Gateway_Isbank' ),
			'rnd'                              => $rnd,
			'storetype'                        => '3D',
			'lang'                             => $lang,
			'currency'                         => self::get_currency_code( $order->get_currency() ),
			'islemtipi'                        => 'Auth',
			'taksit'                           => '',
			'hashAlgorithm'                    => 'ver3',
			'pan'                              => $pan,
			'cv2'                              => $card_cvc,
			'Ecom_Payment_Card_ExpDate_Month' => sprintf( '%02d', $month ),
			'Ecom_Payment_Card_ExpDate_Year'  => $year_short,
			'encoding'                         => 'UTF-8',
		);

		wp_send_json( array(
			'result' => 'success',
			'hash'   => self::generate_hash( $params, $store_key ),
			'month'  => sprintf( '%02d', $month ),
			'year'   => $year_short,
		) );
	}

	private static function is_amex_card( $pan ) {
		return 0 === strpos( $pan, '34' ) || 0 === strpos( $pan, '37' );
	}
}
