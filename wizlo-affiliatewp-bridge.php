<?php
/**
 * Plugin Name: Wizlo → AffiliateWP Bridge
 * Plugin URI:  https://github.com/TBuitrago/wizlo-affiliatewp-bridge
 * Description: Receives webhooks from Wizlo and creates referrals in AffiliateWP. Supports forms.coupon_used (primary), order.updated (lifecycle), cookie-based attribution via forms.progress_saved + forms.completed, and provides a [wizlo_form] shortcode to embed Wizlo forms with automatic affwp_ref cookie propagation.
 * Version:     2.2.0
 * Author:      Tomas Buitrago
 * Author URI:  https://github.com/TBuitrago
 * Requires PHP: 7.4
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wizlo_AffiliateWP_Bridge {

    const REST_NAMESPACE = 'wizlo/v1';
    const REST_ROUTE     = '/conversion';
    const OPTION_SECRET  = 'wizlo_webhook_secret';
    const OPTION_LOG     = 'wizlo_webhook_log';
    const OPTION_ENV     = 'wizlo_environment';
    const REF_CONTEXT    = 'wizlo';
    const LOG_MAX        = 100;
    const SHORTCODE_TAG  = 'wizlo_form';

    /** @var bool Tracks if any [wizlo_form] shortcode rendered on this request. */
    private $shortcode_was_used = false;

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'init', array( $this, 'register_shortcode' ) );
        add_action( 'wp_footer', array( $this, 'print_iframe_script_once' ) );
    }

    /* =====================================================================
     * REST endpoint
     * ================================================================== */

    public function register_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_webhook' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function handle_webhook( WP_REST_Request $request ) {

        if ( ! function_exists( 'affwp_add_referral' ) ) {
            return new WP_REST_Response(
                array( 'error' => 'AffiliateWP is not active' ),
                500
            );
        }

        $raw_body  = $request->get_body();
        $signature = $request->get_header( 'X-Webhook-Signature' );

        // If Wizlo configured a different headerKey, also read it from other common headers.
        if ( empty( $signature ) ) {
            foreach ( array( 'X-Wizlo-Signature', 'X-Signature', 'Signature' ) as $h ) {
                $val = $request->get_header( $h );
                if ( ! empty( $val ) ) {
                    $signature = $val;
                    break;
                }
            }
        }

        if ( ! $this->verify_signature( $raw_body, $signature ) ) {
            $this->log_signature_failure( $raw_body, $signature );
            return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 401 );
        }

        $payload = json_decode( $raw_body, true );
        if ( ! is_array( $payload ) ) {
            return new WP_REST_Response( array( 'error' => 'Invalid JSON' ), 400 );
        }

        $event = strtolower(
            (string) (
                $payload['event']
                ?? $payload['eventType']
                ?? $payload['type']
                ?? ''
            )
        );

        $this->log(
            'info',
            'Webhook received',
            array(
                'event'   => $event,
                'payload' => $payload,
            )
        );

        $result = $this->dispatch( $event, $payload );
        return new WP_REST_Response( $result, 200 );
    }

    /* =====================================================================
     * Dispatch
     * ================================================================== */

    private function dispatch( $event, $payload ) {

        $normalized = str_replace( array( '.', '-' ), '_', $event );

        switch ( $normalized ) {

            case 'forms_progress_saved':
                return $this->handle_progress_saved( $payload );

            case 'forms_coupon_used':
                return $this->handle_coupon_used( $payload );

            case 'forms_completed':
                return $this->handle_forms_completed( $payload );

            case 'forms_product_selected':
                return $this->handle_product_selected( $payload );

            case 'updated':
            case 'order_updated':
            case 'orders_updated':
                return $this->handle_order_updated( $payload );

            default:
                return array( 'status' => 'ignored', 'event' => $event );
        }
    }

    /* =====================================================================
     * Event handlers
     * ================================================================== */

    /**
     * forms.progress_saved — captures affiliate_id from customFields/partial_form_data
     * and stores it keyed by session_id (transient, 30 days) so it can be looked up
     * when forms.completed arrives (which does NOT carry partial_form_data).
     */
    private function handle_progress_saved( $payload ) {

        $data = isset( $payload['data'] ) && is_array( $payload['data'] )
            ? $payload['data']
            : $payload;

        $session_id = $this->find( $data, array( 'session_id', 'sessionId' ) );
        if ( empty( $session_id ) ) {
            return array( 'status' => 'no_session_id' );
        }

        $partial = isset( $data['partial_form_data'] ) && is_array( $data['partial_form_data'] )
            ? $data['partial_form_data']
            : array();

        // First try partial_form_data (Wizlo flattens customFields here),
        // then fall back to any other location in the payload.
        $aff_raw = $this->find_affiliate_id_anywhere( $partial );
        if ( empty( $aff_raw ) ) {
            $aff_raw = $this->find_affiliate_id_anywhere( $payload );
        }

        if ( empty( $aff_raw ) ) {
            return array( 'status' => 'no_affiliate_id_in_progress' );
        }

        set_transient(
            'wizlo_session_aff_' . $session_id,
            (string) $aff_raw,
            30 * DAY_IN_SECONDS
        );

        $this->log( 'info', 'Session affiliate captured', array(
            'session_id'   => $session_id,
            'affiliate_id' => $aff_raw,
        ) );

        return array(
            'status'       => 'session_stored',
            'session_id'   => $session_id,
            'affiliate_id' => $aff_raw,
        );
    }

    /**
     * forms.coupon_used — PRIMARY attribution event.
     * Carries: coupon_code, patient_email, order_id, order_number, order_total, currency.
     */
    private function handle_coupon_used( $payload ) {

        $data = isset( $payload['data'] ) && is_array( $payload['data'] )
            ? $payload['data']
            : $payload;

        $coupon_code = $this->find( $data, array( 'coupon_code', 'couponCode' ) );
        $order_id    = $this->find( $data, array( 'order_id', 'orderId' ) );
        $order_no    = $this->find( $data, array( 'order_number', 'orderNumber', 'order_no', 'orderNo' ) );
        $amount      = (float) $this->find( $data, array( 'order_total', 'orderTotal', 'total', 'amount' ), 0 );
        $email       = $this->find( $data, array( 'patient_email', 'patientEmail', 'email' ), '' );

        if ( empty( $coupon_code ) || empty( $order_id ) ) {
            return array(
                'status' => 'missing_fields',
                'have'   => compact( 'coupon_code', 'order_id' ),
            );
        }

        $affiliate_id = $this->get_affiliate_by_coupon( $coupon_code );
        if ( ! $affiliate_id ) {
            $this->log( 'warn', 'Coupon not linked to any affiliate', array( 'coupon' => $coupon_code ) );
            return array( 'status' => 'no_affiliate', 'coupon' => $coupon_code );
        }

        return $this->create_pending_referral( array(
            'affiliate_id' => $affiliate_id,
            'order_id'     => $order_id,
            'order_no'     => $order_no,
            'amount'       => $amount,
            'email'        => $email,
            'source'       => 'coupon',
            'coupon'       => $coupon_code,
        ) );
    }

    /**
     * forms.completed — only creates a referral if affiliate_id is detected in customFields/metadata.
     * Does NOT carry an amount; it will be updated upon receiving order.updated with grand_total.
     */
    private function handle_forms_completed( $payload ) {

        $data = isset( $payload['data'] ) && is_array( $payload['data'] )
            ? $payload['data']
            : $payload;

        $affiliate_id_raw = $this->find_affiliate_id_anywhere( $payload );

        // Fallback: forms.completed does NOT carry partial_form_data — look up
        // the affiliate_id captured earlier from forms.progress_saved (by session_id).
        $source_label = 'forms_completed+customFields';
        if ( empty( $affiliate_id_raw ) ) {
            $session_id = $this->find( $data, array( 'session_id', 'sessionId' ) );
            if ( $session_id ) {
                $stored = get_transient( 'wizlo_session_aff_' . $session_id );
                if ( ! empty( $stored ) ) {
                    $affiliate_id_raw = $stored;
                    $source_label = 'forms_completed+session_lookup';
                }
            }
        }

        if ( ! $affiliate_id_raw ) {
            return array( 'status' => 'no_affiliate_id_in_payload' );
        }

        $order_id = $this->find( $data, array( 'order_id', 'orderId' ) );
        $email    = $this->find( $data, array( 'patient_email', 'patientEmail', 'email' ), '' );

        if ( empty( $order_id ) ) {
            return array( 'status' => 'no_order_id' );
        }

        $affiliate_id = $this->resolve_affiliate_id( $affiliate_id_raw );
        if ( ! $affiliate_id ) {
            $this->log( 'warn', 'affiliate_id found but no matching affiliate', array( 'value' => $affiliate_id_raw ) );
            return array( 'status' => 'no_affiliate', 'value' => $affiliate_id_raw );
        }

        return $this->create_pending_referral( array(
            'affiliate_id' => $affiliate_id,
            'order_id'     => $order_id,
            'amount'       => 0,
            'email'        => $email,
            'source'       => $source_label,
            'coupon'       => '',
        ) );
    }

    /**
     * forms.product_selected — emitted twice. Only processed when paid + affiliate_id is propagated.
     */
    private function handle_product_selected( $payload ) {

        $data = isset( $payload['data'] ) && is_array( $payload['data'] )
            ? $payload['data']
            : $payload;

        $order_status = strtolower( (string) $this->find( $data, array( 'order_status', 'orderStatus' ), '' ) );
        if ( $order_status !== 'paid' ) {
            return array( 'status' => 'ignored_not_paid', 'order_status' => $order_status );
        }

        return $this->handle_forms_completed( $payload );
    }

    /**
     * order.updated — updates the referral lifecycle.
     * camelCase payload with order_details[] (array, may carry multiple orders).
     */
    private function handle_order_updated( $payload ) {

        $orders = $payload['order_details'] ?? $payload['orderDetails'] ?? null;

        if ( empty( $orders ) && isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
            $orders = $payload['data']['order_details']
                ?? $payload['data']['orderDetails']
                ?? null;
        }

        if ( empty( $orders ) || ! is_array( $orders ) ) {
            return array( 'status' => 'no_order_details' );
        }

        $results = array();

        foreach ( $orders as $order ) {

            $order_id     = $order['id'] ?? $order['order_id'] ?? null;
            $order_status = strtolower( (string) ( $order['order_status'] ?? $order['orderStatus'] ?? '' ) );
            $grand_total  = isset( $order['grand_total'] ) ? (float) $order['grand_total']
                          : ( isset( $order['grandTotal'] ) ? (float) $order['grandTotal'] : null );

            if ( ! $order_id ) {
                $results[] = array( 'status' => 'no_order_id_in_item' );
                continue;
            }

            $referral = affiliate_wp()->referrals->get_by( 'reference', $order_id, self::REF_CONTEXT );
            if ( ! $referral ) {
                $results[] = array( 'status' => 'no_referral', 'order_id' => $order_id );
                continue;
            }

            // Backfill amount if created with 0. We recalc even when grand_total is 0
            // because a flat-rate affiliate should still earn their flat commission
            // on a 100%-coupon order. affwp_calc_referral_amount handles both rate types.
            if ( $referral->amount == 0 && $grand_total !== null ) {
                $new_amount = function_exists( 'affwp_calc_referral_amount' )
                    ? affwp_calc_referral_amount( $grand_total, $referral->affiliate_id, $referral->referral_id, '', self::REF_CONTEXT )
                    : $grand_total;
                if ( $new_amount > 0 ) {
                    affwp_update_referral( array(
                        'referral_id' => $referral->referral_id,
                        'amount'      => $new_amount,
                    ) );
                    $this->log( 'info', 'Referral amount backfilled', array(
                        'referral_id' => $referral->referral_id,
                        'amount'      => $new_amount,
                    ) );
                }
            }

            $map = array(
                'pending'        => 'pending',
                'partially_paid' => 'pending',
                'paid'           => 'unpaid',
                'fulfilled'      => 'unpaid',
                'cancelled'      => 'rejected',
                'canceled'       => 'rejected',
                'refunded'       => 'rejected',
                'failed'         => 'rejected',
            );

            if ( ! isset( $map[ $order_status ] ) ) {
                $results[] = array(
                    'status'       => 'ignored_status',
                    'order_id'     => $order_id,
                    'wizlo_status' => $order_status,
                );
                continue;
            }

            $target = $map[ $order_status ];

            if ( $referral->status === $target ) {
                $results[] = array( 'status' => 'unchanged', 'order_id' => $order_id );
                continue;
            }

            affwp_set_referral_status( $referral->referral_id, $target );

            $this->log( 'success', 'Referral status updated', array(
                'referral_id'  => $referral->referral_id,
                'from'         => $referral->status,
                'to'           => $target,
                'wizlo_status' => $order_status,
            ) );

            $results[] = array(
                'status'       => 'updated',
                'referral_id'  => $referral->referral_id,
                'order_id'     => $order_id,
                'affwp_status' => $target,
            );
        }

        return array( 'status' => 'processed', 'results' => $results );
    }

    /* =====================================================================
     * Shared referral creation
     * ================================================================== */

    private function create_pending_referral( $args ) {

        $order_id = $args['order_id'];

        $existing = affiliate_wp()->referrals->get_by( 'reference', $order_id, self::REF_CONTEXT );
        if ( $existing ) {
            return array(
                'status'      => 'duplicate',
                'referral_id' => $existing->referral_id,
                'order_id'    => $order_id,
            );
        }

        $amount = (float) $args['amount'];

        // Always call affwp_calc_referral_amount (when available) so flat-rate
        // affiliates earn their flat commission even on $0 orders (e.g., 100% coupons).
        $referral_amount = function_exists( 'affwp_calc_referral_amount' )
            ? affwp_calc_referral_amount( $amount, $args['affiliate_id'], 0, '', self::REF_CONTEXT )
            : $amount;

        $description = sprintf(
            'Wizlo order %s%s (via %s)',
            $order_id,
            ! empty( $args['order_no'] ) ? ' / ' . $args['order_no'] : '',
            $args['source']
        );

        $referral_id = affwp_add_referral( array(
            'affiliate_id' => $args['affiliate_id'],
            'amount'       => $referral_amount,
            'reference'    => $order_id,
            'description'  => $description,
            'status'       => 'pending',
            'context'      => self::REF_CONTEXT,
            'custom'       => $args['email'],
        ) );

        if ( ! $referral_id ) {
            $this->log( 'error', 'Failed to create referral', $args );
            return array( 'status' => 'create_failed' );
        }

        $this->log( 'success', 'Referral created', array(
            'referral_id'  => $referral_id,
            'affiliate_id' => $args['affiliate_id'],
            'order_id'     => $order_id,
            'amount'       => $referral_amount,
            'source'       => $args['source'],
        ) );

        return array(
            'status'      => 'created',
            'referral_id' => $referral_id,
            'source'      => $args['source'],
        );
    }

    /* =====================================================================
     * Signature verification (flexible: hex o base64)
     * ================================================================== */

    private function verify_signature( $body, $signature ) {

        if ( empty( $signature ) ) {
            return false;
        }
        $secret = $this->get_secret();
        if ( empty( $secret ) ) {
            return false;
        }

        $hex    = hash_hmac( 'sha256', $body, $secret );
        $base64 = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );

        $clean = preg_replace( '/^sha256=/i', '', $signature );

        return hash_equals( $hex, $clean ) || hash_equals( $base64, $clean );
    }

    private function log_signature_failure( $body, $received ) {
        $secret = $this->get_secret();
        $hex    = $secret ? hash_hmac( 'sha256', $body, $secret ) : '(no secret configured)';
        $base64 = $secret ? base64_encode( hash_hmac( 'sha256', $body, $secret, true ) ) : '(no secret configured)';

        $this->log( 'error', 'Signature mismatch — diagnostic', array(
            'received'        => $received,
            'expected_hex'    => $hex,
            'expected_base64' => $base64,
            'body_length'     => strlen( $body ),
            'hint'            => 'Compare received with expected_hex or expected_base64 to determine the format Wizlo is using.',
        ) );
    }

    private function get_secret() {
        if ( defined( 'WIZLO_WEBHOOK_SECRET' ) ) {
            return WIZLO_WEBHOOK_SECRET;
        }
        return (string) get_option( self::OPTION_SECRET, '' );
    }

    /* =====================================================================
     * Affiliate lookup
     * ================================================================== */

    private function resolve_affiliate_id( $value ) {

        if ( empty( $value ) ) {
            return 0;
        }

        if ( is_numeric( $value ) ) {
            $affiliate = affwp_get_affiliate( (int) $value );
            if ( $affiliate ) {
                return (int) $affiliate->affiliate_id;
            }
        }

        if ( is_email( $value ) ) {
            $user = get_user_by( 'email', $value );
            if ( $user ) {
                $id = affwp_get_affiliate_id( $user->ID );
                if ( $id ) {
                    return (int) $id;
                }
            }
        }

        $user = get_user_by( 'login', $value );
        if ( $user ) {
            $id = affwp_get_affiliate_id( $user->ID );
            if ( $id ) {
                return (int) $id;
            }
        }

        return 0;
    }

    private function get_affiliate_by_coupon( $code ) {

        $code = trim( $code );

        if ( function_exists( 'affiliate_wp' ) ) {
            $awp = affiliate_wp();
            if ( isset( $awp->affiliates->coupons )
                && method_exists( $awp->affiliates->coupons, 'get_affiliate_id_from_code' )
            ) {
                $id = $awp->affiliates->coupons->get_affiliate_id_from_code( $code );
                if ( $id ) {
                    return (int) $id;
                }
            }
        }

        if ( function_exists( 'affwp_get_affiliate_id_by_coupon_code' ) ) {
            $id = affwp_get_affiliate_id_by_coupon_code( $code );
            if ( $id ) {
                return (int) $id;
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'affiliate_wp_coupons';
        $id    = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT affiliate_id FROM {$table} WHERE coupon_code = %s LIMIT 1",
                $code
            )
        );

        return $id ? (int) $id : 0;
    }

    /* =====================================================================
     * Payload search helpers
     * ================================================================== */

    private function find( $data, $keys, $default = null ) {
        foreach ( (array) $keys as $key ) {
            $value = $this->dot_get( $data, $key );
            if ( $value !== null && $value !== '' ) {
                return $value;
            }
        }
        return $default;
    }

    private function dot_get( $array, $path ) {
        $segments = explode( '.', $path );
        $current  = $array;
        foreach ( $segments as $segment ) {
            if ( is_array( $current ) && array_key_exists( $segment, $current ) ) {
                $current = $current[ $segment ];
            } else {
                return null;
            }
        }
        return $current;
    }

    /**
     * Recursively searches the ENTIRE payload for any key that looks like affiliate_id.
     * Useful for detecting customFields/metadata propagated from an iframe embed without
     * knowing where exactly Wizlo places them.
     */
    private function find_affiliate_id_anywhere( $data ) {
        $candidates = array( 'affiliate_id', 'affiliateId', 'affiliate', 'aff_id', 'affId' );
        return $this->recursive_search( $data, $candidates );
    }

    private function recursive_search( $data, $keys ) {
        if ( ! is_array( $data ) ) {
            return null;
        }
        $keys_lower = array_map( 'strtolower', $keys );
        foreach ( $data as $k => $v ) {
            if ( in_array( strtolower( (string) $k ), $keys_lower, true ) ) {
                if ( ! is_array( $v ) && $v !== '' && $v !== null ) {
                    return $v;
                }
            }
            if ( is_array( $v ) ) {
                $found = $this->recursive_search( $v, $keys );
                if ( $found !== null ) {
                    return $found;
                }
            }
        }
        return null;
    }

    /* =====================================================================
     * Shortcode [wizlo_form]
     * ================================================================== */

    public function register_shortcode() {
        add_shortcode( self::SHORTCODE_TAG, array( $this, 'shortcode_handler' ) );
    }

    /**
     * Renders a Wizlo form iframe.
     *
     * Attributes:
     *  - token  (string, optional): bcrypt token from Wizlo. If empty, reads ?token= from URL.
     *  - height (int, optional):    initial iframe height in px. Auto-resizes via postMessage. Default 900.
     */
    public function shortcode_handler( $atts ) {

        $atts = shortcode_atts( array(
            'token'  => '',
            'height' => '900',
        ), $atts, self::SHORTCODE_TAG );

        // Token resolution: shortcode attribute first, then ?token= URL param.
        $token = (string) $atts['token'];
        if ( $token === '' && isset( $_GET['token'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_GET['token'] ) );
        }

        if ( ! $this->is_valid_token( $token ) ) {
            // Admins see a hint; everyone else sees a neutral message.
            if ( current_user_can( 'manage_options' ) ) {
                return '<div style="padding:16px;border:2px solid #d63638;background:#fcf0f1;color:#d63638;font-family:sans-serif;">'
                    . '<strong>Wizlo Bridge:</strong> missing or invalid token. '
                    . 'Use <code>[wizlo_form token="..."]</code> or pass <code>?token=...</code> in the URL.'
                    . '</div>';
            }
            return '<p>Form not available.</p>';
        }

        $origin    = $this->get_wizlo_origin();
        $form_url  = $origin . '/form-submission?token=' . $token;
        $iframe_id = 'wizlo-form-' . wp_generate_uuid4();
        $height    = (int) $atts['height'];
        if ( $height < 100 ) {
            $height = 900;
        }

        $this->shortcode_was_used = true;

        ob_start();
        ?>
        <iframe
            id="<?php echo esc_attr( $iframe_id ); ?>"
            class="wizlo-form-iframe"
            data-wizlo-origin="<?php echo esc_attr( $origin ); ?>"
            src="<?php echo esc_attr( $form_url ); ?>"
            style="width:100%;border:1px solid rgba(0,0,0,0.1);border-radius:6px;display:block;"
            height="<?php echo esc_attr( $height ); ?>"
            scrolling="no"
            allowfullscreen
        ></iframe>
        <?php
        return ob_get_clean();
    }

    /**
     * Validates that a string matches the bcrypt token format Wizlo emits.
     * Pattern: $2[abxy]$NN$<53 chars of [A-Za-z0-9./]>
     */
    private function is_valid_token( $token ) {
        if ( ! is_string( $token ) || $token === '' ) {
            return false;
        }
        return (bool) preg_match( '#^\$2[abxy]\$\d{2}\$[A-Za-z0-9./]{53}$#', $token );
    }

    /**
     * Returns the active Wizlo origin based on the environment option.
     */
    private function get_wizlo_origin() {
        $env = get_option( self::OPTION_ENV, 'production' );
        return $env === 'sandbox' ? 'https://uat.wizlo.com' : 'https://app.wizlo.com';
    }

    /**
     * Prints the shared postMessage script once at wp_footer, only if at least
     * one [wizlo_form] shortcode rendered during this request.
     */
    public function print_iframe_script_once() {

        if ( ! $this->shortcode_was_used ) {
            return;
        }
        ?>
        <script>
        (function () {
          var iframesMap = new Map();

          function getAffiliateId() {
            // Primary: AffiliateWP cookie set by /join/<id>/ landing pages.
            var m = document.cookie.match(/(?:^|;\s*)affwp_ref=([^;]+)/);
            if (m) return decodeURIComponent(m[1]);
            // Fallback: explicit URL parameter (useful for testing).
            var p = new URLSearchParams(window.location.search).get('affiliate_id');
            if (p) return p;
            return null;
          }

          function findIframeBySource(source) {
            var found = null;
            iframesMap.forEach(function (state, iframe) {
              if (iframe.contentWindow === source) {
                found = { iframe: iframe, state: state };
              }
            });
            return found;
          }

          function handleMessage(event) {
            if (!event.data || !event.data.type) return;

            var hit = findIframeBySource(event.source);
            if (!hit) return; // not one of our iframes

            var iframe = hit.iframe;
            var state  = hit.state;

            if (event.data.type === 'wizlo-form-resize' && typeof event.data.height === 'number') {
              iframe.style.height = event.data.height + 'px';
              return;
            }

            if (event.data.type === 'wizlo-form-ready' && !state.sent) {
              state.sent = true;
              var affId = getAffiliateId();
              iframe.contentWindow.postMessage({
                type: 'wizlo-form-init-patient',
                patient: {
                  email: '',
                  customFields: {
                    affiliate_id:     affId || '',
                    affiliate_source: 'arsynl_wp',
                    landing_url:      window.location.href
                  }
                }
              }, state.origin);
            }
          }

          window.addEventListener('message', handleMessage);

          function registerIframes() {
            var iframes = document.querySelectorAll('.wizlo-form-iframe');
            for (var i = 0; i < iframes.length; i++) {
              var f = iframes[i];
              if (iframesMap.has(f)) continue;
              iframesMap.set(f, {
                sent: false,
                origin: f.getAttribute('data-wizlo-origin') || 'https://app.wizlo.com'
              });
            }
          }

          if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', registerIframes);
          } else {
            registerIframes();
          }
        })();
        </script>
        <?php
    }

    /* =====================================================================
     * Logging
     * ================================================================== */

    private function log( $level, $message, $context = array() ) {
        $entry = array(
            'time'    => current_time( 'mysql' ),
            'level'   => $level,
            'message' => $message,
            'context' => $context,
        );

        error_log( '[Wizlo Bridge] ' . wp_json_encode( $entry ) );

        $log = get_option( self::OPTION_LOG, array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }
        array_unshift( $log, $entry );
        $log = array_slice( $log, 0, self::LOG_MAX );
        update_option( self::OPTION_LOG, $log, false );
    }

    /* =====================================================================
     * Admin page
     * ================================================================== */

    public function add_admin_menu() {
        add_options_page(
            'Wizlo Bridge',
            'Wizlo Bridge',
            'manage_options',
            'wizlo-bridge',
            array( $this, 'admin_page' )
        );
    }

    public function register_settings() {
        register_setting( 'wizlo_bridge', self::OPTION_SECRET );
        register_setting( 'wizlo_bridge', self::OPTION_ENV, array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_env' ),
            'default'           => 'production',
        ) );
    }

    public function sanitize_env( $value ) {
        return in_array( $value, array( 'production', 'sandbox' ), true ) ? $value : 'production';
    }

    public function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_GET['clear_log'] ) && check_admin_referer( 'wizlo_clear_log' ) ) {
            delete_option( self::OPTION_LOG );
            echo '<div class="notice notice-success"><p>Log cleared.</p></div>';
        }

        $webhook_url    = rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
        $log            = get_option( self::OPTION_LOG, array() );
        $secret_defined = defined( 'WIZLO_WEBHOOK_SECRET' );
        $clear_url      = wp_nonce_url(
            admin_url( 'options-general.php?page=wizlo-bridge&clear_log=1' ),
            'wizlo_clear_log'
        );
        ?>
        <div class="wrap">
            <h1>Wizlo → AffiliateWP Bridge <span style="font-size:13px;color:#666;">v2.2.0</span></h1>

            <h2>0. Environment</h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'wizlo_bridge' ); ?>
                <?php
                $current_env  = get_option( self::OPTION_ENV, 'production' );
                $current_host = $this->get_wizlo_origin();
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Wizlo Environment</th>
                        <td>
                            <label style="margin-right:18px;">
                                <input type="radio" name="<?php echo esc_attr( self::OPTION_ENV ); ?>" value="production" <?php checked( $current_env, 'production' ); ?>>
                                <strong>Production</strong> &nbsp;<code>https://app.wizlo.com</code>
                            </label>
                            <label>
                                <input type="radio" name="<?php echo esc_attr( self::OPTION_ENV ); ?>" value="sandbox" <?php checked( $current_env, 'sandbox' ); ?>>
                                <strong>Sandbox (UAT)</strong> &nbsp;<code>https://uat.wizlo.com</code>
                            </label>
                            <p class="description">
                                Currently active: <code><?php echo esc_html( $current_host ); ?></code>.
                                This determines which Wizlo domain the <code>[wizlo_form]</code> shortcode loads.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php
                // We render the secret field inside the SAME form so both options
                // save together. The secret block below is just decorative anchor.
                ?>

            <h2>1. Webhook URL</h2>
            <p>Register this URL in Wizlo (<code>POST /tenant/webhooks</code>):</p>
            <p><code style="padding:8px;background:#f0f0f1;display:inline-block;"><?php echo esc_html( $webhook_url ); ?></code></p>

            <h2>2. Recommended Events</h2>
            <ul style="list-style:disc;padding-left:20px;">
                <li><strong>forms.coupon_used</strong> — primary attribution (carries coupon, amount, order_id)</li>
                <li><strong>order.updated</strong> (orders module) — transitions pending → unpaid → rejected and amount backfill</li>
                <li><strong>forms.progress_saved</strong> — required for cookie-based attribution: captures affiliate_id from customFields/partial_form_data, indexed by session_id</li>
                <li><strong>forms.completed</strong> — pairs with progress_saved to create the pending referral on submit</li>
                <li><strong>forms.product_selected</strong> — optional, alternative without coupon in <code>paid</code> state</li>
            </ul>

            <h2>3. HMAC Secret</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Webhook Secret</th>
                        <td>
                            <input
                                type="text"
                                name="<?php echo esc_attr( self::OPTION_SECRET ); ?>"
                                value="<?php echo esc_attr( get_option( self::OPTION_SECRET, '' ) ); ?>"
                                class="regular-text"
                                <?php disabled( $secret_defined ); ?>
                            >
                            <?php if ( $secret_defined ) : ?>
                                <p class="description">Defined via <code>WIZLO_WEBHOOK_SECRET</code> in wp-config.php.</p>
                            <?php else : ?>
                                <p class="description">
                                    Recommended: <code>define( 'WIZLO_WEBHOOK_SECRET', 'your-secret' );</code> in <code>wp-config.php</code>.
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

            <h2>4. Form Shortcode</h2>
            <p>
                Embed the Wizlo intake form anywhere with the <code>[wizlo_form]</code> shortcode.
                It automatically reads the <code>affwp_ref</code> cookie set by AffiliateWP on
                <code>/join/&lt;id&gt;/</code> pages and forwards it to Wizlo via <code>customFields</code>,
                preserving affiliate attribution end-to-end.
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row">Dynamic URL</th>
                    <td>
                        <code>https://your-site.com/intake/?token=&lt;wizlo-bcrypt-token&gt;</code>
                        <p class="description">
                            Create a single page (e.g. <code>/intake/</code>) containing
                            <code>[wizlo_form]</code>. Point each WooCommerce product's external URL
                            to this page with the appropriate <code>?token=</code>. The shortcode
                            reads the token from the URL automatically.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Hardcoded Token</th>
                    <td>
                        <code>[wizlo_form token="$2b$12$..."]</code>
                        <p class="description">
                            Alternative: hardcode the token directly in the shortcode for a
                            dedicated page (no URL parameters needed).
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Custom Height</th>
                    <td>
                        <code>[wizlo_form height="1200"]</code>
                        <p class="description">
                            Initial height in pixels. The iframe auto-resizes as the user
                            advances through pages; this only controls the first paint.
                        </p>
                    </td>
                </tr>
            </table>

                <?php submit_button( 'Save Settings' ); ?>
            </form>

            <h2 style="display:flex;justify-content:space-between;align-items:center;">
                <span>4. Recent Activity (<?php echo count( $log ); ?>)</span>
                <?php if ( ! empty( $log ) ) : ?>
                    <a href="<?php echo esc_url( $clear_url ); ?>" class="button">Clear log</a>
                <?php endif; ?>
            </h2>
            <p class="description">
                Here you can see the raw payload. Useful to validate if <code>customFields</code> propagates from the iframe.
            </p>
            <?php if ( empty( $log ) ) : ?>
                <p>No webhooks received yet.</p>
            <?php else : ?>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th style="width:150px;">Time</th>
                            <th style="width:80px;">Level</th>
                            <th style="width:220px;">Message</th>
                            <th>Context</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $log as $entry ) : ?>
                        <tr>
                            <td><?php echo esc_html( $entry['time'] ); ?></td>
                            <td><?php echo esc_html( $entry['level'] ); ?></td>
                            <td><?php echo esc_html( $entry['message'] ); ?></td>
                            <td>
                                <pre style="margin:0;font-size:11px;max-height:300px;overflow:auto;background:#f6f7f7;padding:6px;"><?php
                                    echo esc_html( wp_json_encode( $entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                                ?></pre>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}

new Wizlo_AffiliateWP_Bridge();
