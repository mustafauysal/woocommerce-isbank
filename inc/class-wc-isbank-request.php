<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Isbank_Request {
	private $url;

	public function __construct( $url ) {
		$this->url = $url;
	}

	private function create_xml( $nodes ) {
		$dom  = new DOMDocument( '1.0', 'UTF-8' );
		$root = $dom->createElement( 'CC5Request' );

		foreach ( $nodes as $key => $value ) {
			$element = $dom->createElement( $key, $value );
			$root->appendChild( $element );
		}

		$dom->appendChild( $root );
		$xml = $dom->saveXML();

		return $xml;
	}

	public function send( $nodes ) {
		$request = $this->create_xml( $nodes );

		$response = wp_remote_post(
			$this->url,
			array(
				'timeout'   => 90,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/xml; charset=UTF-8',
				),
				'body'      => $request,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = wp_remote_retrieve_body( $response );

		if ( empty( $result ) ) {
			return new WP_Error( 'isbank_empty_response', __( 'Banka boş yanıt döndürdü.', 'wc-isbank' ) );
		}

		$xml = simplexml_load_string( $result );

		if ( false === $xml ) {
			return new WP_Error( 'isbank_invalid_response', __( 'Banka yanıtı okunamadı.', 'wc-isbank' ) );
		}

		return $xml;
	}
}
