<?php
/**
 * Plugin Name: Cebelca BIZ
 * Plugin URI:
 * Description: Connects WooCommerce to Cebelca.biz for invoicing and optionally inventory
 * Version: 0.0.91
 * Author: JankoM
 * Author URI: http://refaktorlabs.com
 * Developer: Janko M.
 * Developer URI: http://refaktorlabs.com
 * Text Domain: woocommerce-cebelcabiz
 * Domain Path: /languages
 *
 * Copyright: © 2009-2015 WooThemes.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

// Status: Alpha

if ( ! class_exists( 'WC_Cebelcabiz' ) ) {

//	ini_set('display_errors', 1);
	error_reporting(E_ALL ^ E_NOTICE);
    ini_set("log_errors", 1);
    
	require_once( dirname( __FILE__ ) . '/lib/invfoxapi.php' );
	require_once( dirname( __FILE__ ) . '/lib/strpcapi.php' );
    
	$woocomm_invfox__debug = true;

	function woocomm_invfox__trace( $x, $y = "" ) {
      //if ($woocomm_invfox__debug) {
        error_log( "WC_Cebelcabiz: " . ( $y ? $y . " " : "" ) . print_r( $x, true ) );
      //}
	}

	$conf = null;

	class WC_Cebelcabiz {

		/**
		 * Construct the plugin.
		 */
		public function __construct() {
            
			$this->conf = get_option( 'woocommerce_cebelcabiz_settings' );

//             error_log($this->conf);
            
			add_action( 'plugins_loaded', array( $this, 'init' ) );

			// this sets callbacks on order status changes
			add_action( 'woocommerce_order_status_processing', array( $this, '_woocommerce_order_status_processing' ) );
			add_action( 'woocommerce_order_status_completed', array( $this, '_woocommerce_order_status_completed' ) );
            
			// collects attachment on order complete email sent
			add_filter( 'woocommerce_email_attachments', array( $this, '_attach_invoice_pdf_to_email' ), 10, 3 );

			add_action( 'admin_notices', array( $this, 'admin_notices' ) );

			if ( $this->conf && $this->conf['order_actions_enabled'] ) {
				add_action( 'woocommerce_order_actions', array( $this, 'add_order_meta_box_actions' ) );
				// process the custom order meta box order action
				add_action( 'woocommerce_order_action_cebelcabiz_create_invoice', array(
					$this,
					'process_custom_order_action_invoice'
				) );
				add_action( 'woocommerce_order_action_cebelcabiz_create_proforma', array(
					$this,
					'process_custom_order_action_proforma'
				) );
				add_action( 'woocommerce_order_action_cebelcabiz_create_advance', array(
					$this,
					'process_custom_order_action_advance'
				) );
				add_action( 'woocommerce_order_action_cebelcabiz_create_invt_sale', array(
					$this,
					'process_custom_order_action_invt_sale'
				) );
				add_action( 'woocommerce_order_action_cebelcabiz_check_invt_items', array(
					$this,
					'process_custom_order_action_check_invt_items'
				) );
				/* add_action( 'woocommerce_order_action_cebelcabiz_mark_invoice_paid', array(
					$this,
					'process_custom_order_action_mark_invoice_paid'
                    ) );*/
			}

			$translArr = array(
				0 => "invoice",
				1 => "proforma",
				2 => "inventory"
			);
		}

		/**
		 * Initialize the plugin.
		 */
		public function init() {
			// Checks if WooCommerce is installed.
			if ( class_exists( 'WC_Cebelcabiz' ) ) {

				// Include our integration class.
				include_once 'includes/class-wc-integration-cebelcabiz.php';

				// Register the integration.
				add_filter( 'woocommerce_integrations', array( $this, 'add_integration' ) );

			} else {
				// throw an admin error if you like
			}
		}

		/*
		 * Show admin notices from stack
		 */
		function admin_notices() {
			if ( $notices = get_option( 'cebelcabiz_deferred_admin_notices' ) ) {
				foreach ( $notices as $notice ) {
					echo "<div class='updated'><p>$notice</p></div>";
				}
				delete_option( 'cebelcabiz_deferred_admin_notices' );
			}
		}

		/**
		 * Add our items for order actions box
		 */
		function add_order_meta_box_actions( $actions ) {
			$actions['cebelcabiz_create_invoice']    = __( $this->conf['app_name'] . ': Make invoice', 'woocom-invfox' );
			$actions['cebelcabiz_mark_invoice_paid'] = __( $this->conf['app_name'] . ': Mark invoice paid', 'woocom-invfox' );
			$actions['cebelcabiz_create_proforma']   = __( $this->conf['app_name'] . ': Make proforma', 'woocom-invfox' );
			$actions['cebelcabiz_create_advance']    = __( $this->conf['app_name'] . ': Make advance', 'woocom-invfox' );

			//if ( $this->conf['use_inventory'] == "yes" ) {
            $actions['cebelcabiz_check_invt_items'] = __( $this->conf['app_name'] . ': Check inventory', 'woocom-invfox' );
            $actions['cebelcabiz_create_invt_sale'] = __( $this->conf['app_name'] . ': Make invent. sale', 'woocom-invfox' );
                //}

			return $actions;
		}

		/**
		 * Add a new integration to WooCommerce.
		 */
		public function add_integration( $integrations ) {
			$integrations[] = 'WC_Integration_Cebelcabiz';

			return $integrations;
		}

		function process_custom_order_action_invoice( $order ) {
			$this->_make_document_in_invoicefox( $order, "invoice_draft" );
		}

		function process_custom_order_action_proforma( $order ) {
			$this->_make_document_in_invoicefox( $order, "proforma" );
		}
		
		function process_custom_order_action_advance( $order ) {
			$this->_make_document_in_invoicefox( $order, "advance_draft" );
		}

		function process_custom_order_action_invt_sale( $order ) {
			$this->_make_document_in_invoicefox( $order, "inventory" );
		}

		function process_custom_order_action_full_invoice( $order ) {
			$this->_make_document_in_invoicefox( $order, "invoice_complete" );
		}

		function process_invoice_download( $order ) {
			$this->_woocommerce_order_invoice_pdf( $order );
		}

		function process_custom_order_action_mark_invoice_paid( $order ) {
			$api = new InvfoxAPI( $this->conf['api_key'], $this->conf['api_domain'], true );
			$api->setDebugHook( "woocomm_invfox__trace" );
			$api->markInvoicePaid( $order->get_id() );

			$notices   = get_option( 'cebelcabiz_deferred_admin_notices', array() );
			$notices[] = "Marked paid";

			update_option( 'cebelcabiz_deferred_admin_notices', $notices );
		}

		function process_custom_order_action_check_invt_items( $order ) {
			$items = array();

			foreach ( $order->get_items() as $item ) {
				if ( 'line_item' == $item['type'] ) {
					$product = $item->get_product(); // $order->get_product_from_item( $item );
					$items[] = array(
						'code' => $product->get_sku(),
						'qty'  => $item['qty']
					);
				}
			}

			$api = new InvfoxAPI( $this->conf['api_key'], $this->conf['api_domain'], true );
			$api->setDebugHook( "woocomm_invfox__trace" );

			$resp = $api->checkInvtItems( $items, $this->conf['from_warehouse_id'], $api->_toSIDate( date( 'd.m.Y' ) ) );
			$msg  = "";

			if ( $resp ) {
				$first = true;
				foreach ( $resp as $item ) {
					$msg   .= ( $first ? "" : ", " ) . $item['code'] . ": " . ( $item['result'] == null ? "item code not found" : ( $item['result'] >= 0 ? "OK, on inventory (+ {$item['result']})" : "less inventory ({$item['result']})" ) );
					$first = false;
				}
			}

			$notices   = get_option( 'cebelcabiz_deferred_admin_notices', array() );
			$notices[] = "Inventory items checked: " . $msg;

			update_option( 'cebelcabiz_deferred_admin_notices', $notices );
		}

		function _woocommerce_order_status_processing( $order ) {
			if ( $this->conf['on_order_processing'] == "create_invoice_draft" ) {
				$this->_make_document_in_invoicefox( $order, 'invoice_draft' );
			}
		}


		function _woocommerce_order_status_completed( $order ) {
            error_log(" !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!! CALLED ON COMPLETED !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!");

            if ( $this->conf['on_order_completed'] == "create_invoice_draft" ) {
				$this->_make_document_in_invoicefox( $order, 'invoice_draft' );
			} else if ( strpos($this->conf['on_order_completed'], "create_invoice_complete") !== false) {
              
              woocomm_invfox__trace( "================ BEFORE BEFORE ===============" );
              woocomm_invfox__trace( $this->conf['on_order_completed'] );
              woocomm_invfox__trace(strpos($this->conf['on_order_completed'], "_paid_") !== false);
              woocomm_invfox__trace(strpos($this->conf['on_order_completed'], "_inventory_") !== false );

              
              $this->_make_document_in_invoicefox( $order, 
                                                   'invoice_complete',
                                                   strpos($this->conf['on_order_completed'], "_paid_") !== false, 
                                                   strpos($this->conf['on_order_completed'], "_inventory_") !== false );
			}
		}

		/**
		 * function that collects data and calls invfoxapi functions to create document
		 */
		function _make_document_in_invoicefox( $order_id, $document_to_make = "", $markPaid = 0, $decreaseInventory = 0 ) {

			$order = wc_get_order( $order_id );

			if ( $document_to_make ) {
				$this->conf['document_to_make'] = $document_to_make;
			}

			woocomm_invfox__trace( "================ BEGIN MAKING DOC ===============" );
			woocomm_invfox__trace(             $this->conf['document_to_make'] );

			$api = new InvfoxAPI( $this->conf['api_key'], $this->conf['api_domain'], true );
//			$api->setDebugHook( "woocomm_invfox__trace" );

			//$vatNum = get_post_meta( $order->get_id(), 'VAT Number', true );

			$vatNumber = get_post_meta( $order->get_id(), 'billing_davcna_st', true );
			
			$vatBound = 0;

			if(get_post_meta( $order->get_id(), 'billing_davcni_zavezanec', true ) == "1"){
				$vatBound = 1;
			}

			$r = $api->assurePartner( array(
				'name'           => $order->get_billing_first_name() . " " . $order->get_billing_last_name() . ( $order->get_billing_company() ? ", " : "" ) . $order->get_billing_company(),
				'street'         => $order->get_billing_address_1() . "\n" . $order->get_billing_address_2(),
				'postal'         => $order->get_billing_postcode(),
				'city'           => $order->get_billing_city(),
				'country'        => $order->get_billing_country(),
				'vatid'          => $vatNumber, // TODO -- find where the data is
				'phone'          => $order->get_billing_phone(), //$c->phone.($c->phone_mobile?", ":"").$c->phone_mobile,
				'website'        => "",
				'email'          => $order->get_billing_email(),
				'notes'          => '',
				'vatbound'       => $vatBound, //!!$c->vat_number, TODO -- after (2)
				'custaddr'       => '',
				'payment_period' => $this->conf['customer_general_payment_period'],
				'street2'        => ''
			) );

			if ( $r->isOk() ) {

              woocomm_invfox__trace( "================ BEGIN MAKING DOC 1 ===============" );

				$clientIdA = $r->getData();
				$clientId  = $clientIdA[0]['id'];
				$date1     = $api->_toSIDate( date( 'Y-m-d' ) ); //// TODO LONGTERM ... figure out what we do with different Dates on api side (maybe date optionally accepts dbdate format)
				$invid     = $this->conf['use_shop_id_for_docnum'] ? str_pad( $order->get_id(), 5, "0", STR_PAD_LEFT ) : "";
				$body2     = array();

				foreach ( $order->get_items() as $item ) {
					if ( 'line_item' == $item['type'] ) {
						$product        = $item->get_product(); // $order->get_product_from_item( $item );
						$attributes_str = woocomm_invfox_get_item_attributes( $item );
						$body2[]        = array(
							'code'     => $product->get_sku(),
							'title'    => 
                            ($this->conf['add_sku_to_line'] == "yes" && $this->conf['document_to_make'] != 'inventory' ? $product->get_sku(). ": " : "" ).
                            $product->get_title() . ( $attributes_str ? "\n" . $attributes_str : "" ), // ( $this->conf['add_post_content_in_item_descr'] == "yes" ? "\n" . $product->get_content : "" ),
							'qty'      => $item['qty'],
							'mu'       => '',
							'price'    => round( $item['line_total'] / $item['qty'], $this->conf['round_calculated_netprice_to'] ),
							'vat'      => calculatePreciseSloVAT($item['line_total'], $item['line_tax']),
							'discount' => 0
						);
					}
				}

				if ( $this->conf['document_to_make'] != 'inventory' && $order->get_shipping_total() > 0 ) {
					woocomm_invfox__trace( "adding shipping" );

					if ( $this->conf['partial_sum_label'] ) {
						$body2[] = array(
							'title'    => "= " . $this->conf['partial_sum_label'],
							'qty'      => 1,
							'mu'       => '',
							'price'    => 0,
							'vat'      => 0,
							'discount' => 0
						);
					}

					$body2[] = array(
						'title'    => $order->get_shipping_method(),
						'qty'      => 1,
						'mu'       => '',
						'price'    => $order->get_shipping_total(),
						'vat'      => calculatePreciseSloVAT($order->get_shipping_total(), $order->get_shipping_tax()),
						'discount' => 0
					);
				}

				if ( $this->conf['document_to_make'] == 'invoice_draft' || $this->conf['document_to_make'] == 'advance_draft' ) {
					woocomm_invfox__trace( "before create invoice call" );

					$r2 = $api->createInvoice( array(
						'title'           => $invid,
						'date_sent'       => $date1,
						'date_to_pay'     => $date1,
						'date_served'     => $date1, // MAY NOT BE NULL IN SOME VERSIONS OF USER DBS
						'id_partner'      => $clientId,
						'taxnum'          => '-',
						'doctype'         => $this->conf['document_to_make'] == 'advance_draft' ? 1 : 0,
						'id_document_ext' => $order->get_id(),
						'pub_notes'       => $this->conf['order_num_label'] . ' #' . $order->get_order_number()
					), $body2 );

					if ( $r2->isOk() ) {
						$invA = $r2->getData();
						$order->add_order_note( "Invoice No. {$invA[0]['title']} was created at {$this->conf['app_name']}." );
					}

				} elseif ( $this->conf['document_to_make'] == 'invoice_complete' ) {
					$r2 = $api->createInvoice( array(
						'title'           => $invid,
						'date_sent'       => $date1,
						'date_to_pay'     => $date1,
						'date_served'     => $date1, // MAY NOT BE NULL IN SOME VERSIONS OF USER DBS
						'id_partner'      => $clientId,
						'taxnum'          => '-',
						'doctype'         => 0,
						'id_document_ext' => $order->get_id(),
						'pub_notes'       => $this->conf['order_num_label'] . ' #' . $order->get_order_number()
					), $body2 );

					if ( $r2->isOk() ) {
						woocomm_invfox__trace( "--- BEFORE FINALIZE ----" );

                        woocomm_invfox__trace( $this->conf['fiscal_mode'] );
						$invA = $r2->getData();
						if ( $this->conf['fiscal_mode'] == "no" ) {
                          woocomm_invfox__trace( "--- non fiscal ----" );
                          $r3 = $api->finalizeInvoiceNonFiscal( array(
                                                                      'id'      => $invA[0]['id'],
                                                                      'title'   => "",
                                                                      'doctype' => 0
                                                                      ) );
						} else {
                          woocomm_invfox__trace( "--- fiscal ----" );
                          if ( $this->conf['fiscal_id_location'] && 
                               $this->conf['fiscal_op_tax_id'] && 
                               $this->conf['fiscal_op_name']
                               ) {
							$r3 = $api->finalizeInvoice( array(
                                                               'id'          => $invA[0]['id'],
                                                               'id_location' => $this->conf['fiscal_id_location'],
                                                               'fiscalize'   => 1,
                                                               'op-tax-id'   => $this->conf['fiscal_op_tax_id'],
                                                               'op-name'     => $this->conf['fiscal_op_name'],
                                                               'test_mode'   => $this->conf['fiscal_test_mode'] == "yes" ? 1 : 0
                                                               ) );
                          } else {
                            woocomm_invfox__trace( "ERROR: MISSING ID_LOCATION, OP TAX ID, OP NAME" );

                          }
						}

						woocomm_invfox__trace( "--- BEFORE PAID  ----" );

                        if ($markPaid) {
                          woocomm_invfox__trace( "--- BEFORE PAID 2  ----" );
                          $api->markInvoicePaid2( $invA[0]['id'] );
                          $notices   = get_option( 'cebelcabiz_deferred_admin_notices', array() );
                          $notices[] = "Marked paid";
                          update_option( 'cebelcabiz_deferred_admin_notices', $notices );
                        }
                        
						woocomm_invfox__trace( "--- BEFORE INVT  ----" );
                        
                        if ($decreaseInventory) {
                          woocomm_invfox__trace( "--- BEFORE INVT 2  ----" );
                          $api->makeInventoryDocOutOfInvoice(  $invA[0]['id'], $this->conf['from_warehouse_id'], $clientId );
                          $notices   = get_option( 'cebelcabiz_deferred_admin_notices', array() );
                          $notices[] = "Inventory doc created";
                          update_option( 'cebelcabiz_deferred_admin_notices', $notices );
                        }

						$uploads = wp_upload_dir();
						$upload_path    = $uploads['basedir'] . "/invoices";
                        
						//$filename = $api->downloadInvoicePDF( $order->id, $path );
						$filename = $api->downloadPDF( 0, $order->get_id(), $upload_path, 'invoice-sent', '' );

						/*
						$filetype = wp_check_filetype( basename( $filename ), null );

						// Prepare an array of post data for the attachment.
						$attachment = array(
							'guid'           => $uploads['url'] . '/' . basename( $filename ),
							'post_mime_type' => $filetype['type'],
							'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $filename ) ),
							'post_content'   => '',
							'post_status'    => 'inherit'
						);

						// Insert the attachment.
						$attach_id = wp_insert_attachment( $attachment, $filename, $order->id );

						// Make sure that this file is included, as wp_generate_attachment_metadata() depends on it.
						require_once( ABSPATH . 'wp-admin/includes/image.php' );

						// Generate the metadata for the attachment, and update the database record.
						$attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
						wp_update_attachment_metadata( $attach_id, $attach_data );

						set_post_thumbnail( $order->id, $attach_id );
						*/

						add_post_meta( $order->get_id(), 'invoicefox_attached_pdf', $filename );
						$order->save();
						$order->add_order_note( "Invoice No. {$r3[0]['new_title']} was created at {$this->conf['app_name']}." );

					}

				} elseif ( $this->conf['document_to_make'] == 'proforma' ) {
					woocomm_invfox__trace( "before create proforma call" );

					$r2 = $api->createProFormaInvoice( array(
						'title'      => "",
						'date_sent'  => $date1,
						'days_valid' => $this->conf['proforma_days_valid'],
						'id_partner' => $clientId,
						'taxnum'     => '-',
						'pub_notes'  => $this->conf['order_num_label'] . ' #' . $order->get_id()
					), $body2 );

					if ( $r2->isOk() ) {
						$invA = $r2->getData();
						$order->add_order_note( "ProForma Invoice No. {$invA[0]['title']} was created at {$this->conf['app_name']}." );
					}

				} elseif ( $this->conf['document_to_make'] == 'inventory' ) {
					woocomm_invfox__trace( "before create inventory sale call" );

					$r2 = $api->createInventorySale( array(
						'title'           => $invid,
						'date_created'    => $date1,
						'id_contact_to'   => $clientId,
						'id_contact_from' => $this->conf['from_warehouse_id'],
						'taxnum'          => '-',
						'doctype'         => 1,
						'pub_notes'       => $this->conf['order_num_label'] . ' #' . $order->get_id()
					), $body2 );

					if ( $r2->isOk() ) {
						$invA = $r2->getData();
						$order->add_order_note( "Inventory sales document No. {$invA[0]['title']} was created at {$this->conf['app_name']}." );
					}

				}

				// woocomm_invfox__trace( $r2 );
				woocomm_invfox__trace( "after create invoice" );
			}

			return true;
		}

		/**
		 * function that downloads pdf
		 */
		function _woocommerce_order_invoice_pdf( $order ) {
			woocomm_invfox__trace( "================ BEGIN PDF DOWNLOAD ===============" );

			$api = new InvfoxAPI( $this->conf['api_key'], $this->conf['api_domain'], true );
			$api->setDebugHook( "woocomm_invfox__trace" );

			$uploads     = wp_upload_dir();
			$upload_path = $uploads['basedir'] . '/invoices';

			$file = $api->downloadPDF( 0, $order->get_id(), $upload_path, 'invoice-sent', '' );

			woocomm_invfox__trace( "================ END PDF DOWNLOAD ===============" );

			return $file;
		}

		function _attach_invoice_pdf_to_email( $attachments, $status, $order ) {

			if ( $this->conf['on_order_completed'] == "create_invoice_complete_email" ||
			     $this->conf['on_order_completed'] == "create_invoice_complete_paid_email" ||
			     $this->conf['on_order_completed'] == "create_invoice_complete_paid_inventory_email" ||
			     $this->conf['on_order_completed'] == "email" ) {

                woocomm_invfox__trace( "================ ATTACH FILTER CALLED  ===============" );

				$allowed_statuses = array( 'customer_completed_order' );

				if ( isset( $status ) && in_array( $status, $allowed_statuses ) ) {
                    woocomm_invfox__trace( "================ ATTACH PDF TO EMAIL ===============" );
					$path = $this->_woocommerce_order_invoice_pdf( $order );
					$attachments[] = $path;
                    woocomm_invfox__trace( $attachments );
				}

				return $attachments;
			} else {
				return array();
			}
		}
	}

	$WC_Cebelcabiz = new WC_Cebelcabiz( __FILE__ );
}


function woocomm_invfox_get_attributes( $product ) {

	$formatted_attributes = array();

	$attributes = $product->get_attributes();

	foreach ( $attributes as $attr => $attr_deets ) {

		$attribute_label = wc_attribute_label( $attr );

		if ( isset( $attributes[ $attr ] ) || isset( $attributes[ 'pa_' . $attr ] ) ) {

			$attribute = isset( $attributes[ $attr ] ) ? $attributes[ $attr ] : $attributes[ 'pa_' . $attr ];

			if ( $attribute['is_taxonomy'] ) {

				$formatted_attributes[ $attribute_label ] = implode( ', ', wc_get_product_terms( $product->id, $attribute['name'], array(
					'fields' => 'names'
				) ) );

			} else {

				$formatted_attributes[ $attribute_label ] = $attribute['value'];
			}

		}
	}

	return $formatted_attributes;
}

function woocomm_invfox_prettify_slug( $t ) {
	return str_replace( "-", " ", ucfirst( $t ) );
}

function woocomm_invfox_get_item_attributes( $item ) {

	$res = "";

	foreach ( $item as $key => $val ) {

		if ( strpos( $key, "pa_" ) !== false ) {

			$res .= ( $res === "" ? "" : "\n" ) . woocomm_invfox_prettify_slug( substr( $key, 3 ) ) . ": " . woocomm_invfox_prettify_slug( $val );

		}
	}

	return $res;
}

function calculatePreciseSloVAT($netPrice, $vatValue) {

	$vatLevels = array(0, 5, 9.5, 22);
	$vat1 = round( $vatValue / $netPrice * 100, 1);
	$vat = -1;
	foreach ($vatLevels as $vatLevel) {
		if (abs($vat1 - $vatLevel) < 2.5) {
			$vat = $vatLevel;
		}
	}
	
	return $vat;
}

?>
