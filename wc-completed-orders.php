<?php

/*
Plugin Name: WooCommerce Completed Orders
Description: Monthly completed orders with export.
Author: FirstTracks Marketing
Author URI: https://firsttracksmarketing.com/
Version: 1.0.0
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ftwcc_is_xlsx_export_request')) {
    function ftwcc_is_xlsx_export_request() {
        return isset($_GET['action'], $_GET['page']) &&
            $_GET['action'] === 'ftwcc_export_xlsx' &&
            $_GET['page'] === 'wc-completed-orders';
    }
}

if (!function_exists('ftwcc_write_export_error_log')) {
    function ftwcc_write_export_error_log($message) {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error($message, array('source' => 'wc-completed-orders'));
        }
    }
}

if (ftwcc_is_xlsx_export_request()) {
    register_shutdown_function(function () {
        $error = error_get_last();
        if (!$error) {
            return;
        }

        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
        if (!in_array($error['type'], $fatal_types, true)) {
            return;
        }

        ftwcc_write_export_error_log(
            $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
        );
    });
}

class FT_WC_Completed_Orders_Report {

    private const PAGE_SIZE = 50;
    private const EXPORT_BATCH_SIZE = 500;
    private $monthly_orders_cache = array();

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_filter('custom_menu_order', array($this, 'enable_custom_menu_order'), PHP_INT_MAX);
        add_filter('menu_order', array($this, 'place_incomplete_orders_after_completed'), PHP_INT_MAX);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'handle_export'));
        add_action('wp_ajax_ftwcc_save_column_prefs', array($this, 'save_column_preferences'));
    }

    /**
     * Add admin menu item
     */
    public function add_admin_menu() {
        add_menu_page(
            'Completed Orders',
            'Completed Orders',
            'manage_woocommerce',
            'wc-completed-orders',
            array($this, 'display_orders_page'),
            'dashicons-clipboard',
            56
        );
    }

    /**
     * Enable WordPress's menu_order filter while preserving the existing order.
     */
    public function enable_custom_menu_order() {
        return true;
    }

    /**
     * Keep the two order reports together in the top-level admin menu.
     */
    public function place_incomplete_orders_after_completed($menu_order) {
        global $menu;

        if (!is_array($menu_order) || !is_array($menu)) {
            return $menu_order;
        }

        $incomplete_orders_slug = null;

        foreach ($menu as $menu_item) {
            if (!isset($menu_item[0], $menu_item[2])) {
                continue;
            }

            $menu_title = trim(wp_strip_all_tags($menu_item[0]));
            if (stripos($menu_title, 'Incomplete Orders') === 0) {
                $incomplete_orders_slug = $menu_item[2];
                break;
            }
        }

        $completed_orders_slug = 'wc-completed-orders';
        $completed_position = array_search($completed_orders_slug, $menu_order, true);
        $incomplete_position = array_search($incomplete_orders_slug, $menu_order, true);

        if ($completed_position === false || $incomplete_position === false) {
            return $menu_order;
        }

        array_splice($menu_order, $incomplete_position, 1);
        $completed_position = array_search($completed_orders_slug, $menu_order, true);
        array_splice($menu_order, $completed_position + 1, 0, array($incomplete_orders_slug));

        return $menu_order;
    }

    /**
     * Enqueue admin styles and scripts
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_wc-completed-orders') {
            return;
        }

        wp_enqueue_style(
            'ftwcc-admin',
            plugin_dir_url(__FILE__) . 'admin-style.css',
            array(),
            '2.0.7'
        );

        wp_enqueue_script(
            'ftwcc-admin',
            plugin_dir_url(__FILE__) . 'admin-script.js',
            array('jquery'),
            '2.0.1',
            true
        );

        wp_localize_script('ftwcc-admin', 'ftwccData', array(
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('ftwcc_column_prefs'),
            'column_prefs' => $this->get_column_preferences(),
        ));
    }

    /**
     * Get column visibility preferences for the current user
     */
    public function get_column_preferences() {
        $user_id  = get_current_user_id();
        $prefs    = get_user_meta($user_id, 'ftwcc_column_visibility', true);

        $defaults = array(
            'id'       => true,
            'date'     => true,
            'customer' => true,
            'status'   => true,
            'items'    => true,
            'renewal'  => true,
            'total'    => true,
            'invoice'  => true,
            'payment'  => true,
            'last4'    => true,
            'reference' => true,
        );

        if (empty($prefs) || !is_array($prefs)) {
            return $defaults;
        }

        return array_intersect_key(wp_parse_args($prefs, $defaults), $defaults);
    }

    /**
     * Save column visibility preferences via AJAX
     */
    public function save_column_preferences() {
        check_ajax_referer('ftwcc_column_prefs', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }

        $column  = isset($_POST['column']) && is_string($_POST['column']) ? sanitize_key(wp_unslash($_POST['column'])) : '';
        if (!array_key_exists($column, $this->get_column_preferences())) {
            wp_send_json_error('Invalid column');
        }
        $visible = filter_var($_POST['visible'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $user_id = get_current_user_id();
        $prefs   = $this->get_column_preferences();
        $prefs[$column] = $visible;

        update_user_meta($user_id, 'ftwcc_column_visibility', $prefs);

        wp_send_json_success();
    }

    /** Selected calendar month in the WordPress site timezone. */
    public function get_selected_month() {
        if (isset($_GET['ftwcc_month_number'], $_GET['ftwcc_year'])) {
            $month_number = is_scalar($_GET['ftwcc_month_number']) ? absint($_GET['ftwcc_month_number']) : 0;
            $year = is_scalar($_GET['ftwcc_year']) ? absint($_GET['ftwcc_year']) : 0;

            if ($year < 1000 || $year > 9999 || $month_number < 1 || $month_number > 12) {
                wp_die('Invalid report month or year.', '', array('response' => 400));
            }

            return sprintf('%04d-%02d', $year, $month_number);
        }

        if (!isset($_GET['ftwcc_month'])) {
            return current_datetime()->format('Y-m');
        }
        $month = is_string($_GET['ftwcc_month']) ? wp_unslash($_GET['ftwcc_month']) : '';
        if (!preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $month)) {
            wp_die('Invalid report month. Use YYYY-MM.', '', array('response' => 400));
        }
        return $month;
    }

    /** Timestamp bounds include the entire month, including DST transitions. */
    public function get_month_range($month) {
        $start = new DateTimeImmutable($month . '-01 00:00:00', wp_timezone());
        return $start->getTimestamp() . '...' . ($start->modify('+1 month')->getTimestamp() - 1);
    }

    /** Whether Authorize.Net explicitly records that the payment was never captured. */
    private function is_uncaptured_authorization($order) {
        $gateway = $order->get_payment_method();
        if (!in_array($gateway, array('authorize_net_cim_credit_card', 'authorize_net_aim'), true)) {
            return false;
        }

        $captured = $order->get_meta('_wc_' . $gateway . '_charge_captured', true);
        return strtolower((string) $captured) === 'no';
    }

    /** Find a recorded transition to Completed within the report period. */
    private function get_completion_note_timestamp($order_id, $range_start, $range_end) {
        if (!function_exists('wc_get_order_notes')) {
            return 0;
        }

        $notes = wc_get_order_notes(array(
            'order_id' => $order_id,
            'type' => 'internal',
            'orderby' => 'date_created',
            'order' => 'DESC',
        ));

        foreach ($notes as $note) {
            $content = is_object($note) && method_exists($note, 'get_content')
                ? $note->get_content()
                : (is_object($note) && isset($note->content) ? $note->content : '');
            if (!preg_match('/\bOrder status changed from .+ to Completed\.?/i', (string) $content)) {
                continue;
            }

            $date_created = is_object($note) && method_exists($note, 'get_date_created')
                ? $note->get_date_created()
                : (is_object($note) && isset($note->date_created) ? $note->date_created : null);
            if ($date_created instanceof DateTimeInterface) {
                $timestamp = $date_created->getTimestamp();
            } elseif (is_string($date_created)) {
                $timestamp = strtotime($date_created);
            } else {
                $timestamp = false;
            }

            if (!$timestamp) {
                continue;
            }

            if ($timestamp >= $range_start && $timestamp <= $range_end) {
                return $timestamp;
            }
        }

        return 0;
    }

    public function get_completed_orders($page = 1, $limit = self::PAGE_SIZE) {
        $month = $this->get_selected_month();

        if (!isset($this->monthly_orders_cache[$month])) {
            $date_range = $this->get_month_range($month);
            list($range_start, $range_end) = array_map('intval', explode('...', $date_range, 2));
            $batch_size = 200;
            $events = array();
            $completed_event_order_ids = array();

            // A completed order keeps its completion event even if its current
            // status later changes to refunded, cancelled, or another status.
            $query_page = 1;
            do {
                $completed_result = wc_get_orders(array(
                    'type' => 'shop_order',
                    'status' => array_keys(wc_get_order_statuses()),
                    'date_completed' => $date_range,
                    'limit' => $batch_size,
                    'paged' => $query_page,
                    'paginate' => true,
                ));
                $completed_max_pages = (int) $completed_result->max_num_pages;

                foreach ($completed_result->orders as $order) {
                    if ($this->is_uncaptured_authorization($order)) {
                        continue;
                    }

                    $rounded_total = (float) wc_format_decimal($order->get_total(), wc_get_price_decimals());
                    $completed_date = $order->get_date_completed();
                    if ($rounded_total !== 0.0 && $completed_date) {
                        $positive_event_date = $order->get_date_paid() ?: $completed_date;
                        $events[] = array(
                            'order_id' => $order->get_id(),
                            'status' => 'completed',
                            'timestamp' => $positive_event_date->getTimestamp(),
                            'total' => $rounded_total,
                        );
                        $completed_event_order_ids[$order->get_id()] = true;
                    }
                }

                unset($completed_result);
                $query_page++;
            } while ($query_page <= $completed_max_pages);

            // A later cancellation/refund can leave a previously charged order
            // without a completion date. Recover its positive event from the paid
            // date so a captured payment and its refund are both represented.
            $query_page = 1;
            do {
                $paid_result = wc_get_orders(array(
                    'type' => 'shop_order',
                    'status' => array('wc-refunded'),
                    'date_paid' => $date_range,
                    'limit' => $batch_size,
                    'paged' => $query_page,
                    'paginate' => true,
                ));
                $paid_max_pages = (int) $paid_result->max_num_pages;

                foreach ($paid_result->orders as $order) {
                    $order_id = $order->get_id();
                    if (
                        isset($completed_event_order_ids[$order_id]) ||
                        $order->get_date_completed() ||
                        $this->is_uncaptured_authorization($order)
                    ) {
                        continue;
                    }

                    $paid_date = $order->get_date_paid();
                    $rounded_total = (float) wc_format_decimal($order->get_total(), wc_get_price_decimals());
                    if ($rounded_total !== 0.0 && $paid_date) {
                        $events[] = array(
                            'order_id' => $order_id,
                            'status' => 'completed',
                            'timestamp' => $paid_date->getTimestamp(),
                            'total' => $rounded_total,
                        );
                        $completed_event_order_ids[$order_id] = true;
                    }
                }

                unset($paid_result);
                $query_page++;
            } while ($query_page <= $paid_max_pages);

            // Fetch refunds separately and store only their lightweight event
            // data. Parent orders are loaded later, one report batch at a time.
            $query_page = 1;
            do {
                $refund_result = wc_get_orders(array(
                    'type' => 'shop_order_refund',
                    'date_created' => $date_range,
                    'limit' => $batch_size,
                    'paged' => $query_page,
                    'paginate' => true,
                ));
                $refund_max_pages = (int) $refund_result->max_num_pages;

                foreach ($refund_result->orders as $refund) {
                    $parent_id = $refund->get_parent_id();
                    $refund_date = $refund->get_date_created();
                    if (!$parent_id || !$refund_date) {
                        continue;
                    }

                    $parent_order = wc_get_order($parent_id);
                    if (!$parent_order || $this->is_uncaptured_authorization($parent_order)) {
                        continue;
                    }

                    if (!isset($completed_event_order_ids[$parent_id])) {
                        $completion_timestamp = $this->get_completion_note_timestamp(
                            $parent_id,
                            $range_start,
                            $range_end
                        );
                        $completed_total = (float) wc_format_decimal(
                            $parent_order->get_total(),
                            wc_get_price_decimals()
                        );

                        if ($completion_timestamp && $completed_total !== 0.0) {
                            $paid_date = $parent_order->get_date_paid();
                            $events[] = array(
                                'order_id' => $parent_id,
                                'status' => 'completed',
                                'timestamp' => $paid_date ? $paid_date->getTimestamp() : $completion_timestamp,
                                'total' => $completed_total,
                            );
                            $completed_event_order_ids[$parent_id] = true;
                        }
                    }

                    $events[] = array(
                        'order_id' => $parent_id,
                        'status' => 'refunded',
                        'timestamp' => $refund_date->getTimestamp(),
                        'total' => abs((float) wc_format_decimal(
                            $refund->get_total(),
                            wc_get_price_decimals()
                        )),
                    );
                }

                unset($refund_result);
                $query_page++;
            } while ($query_page <= $refund_max_pages);

            usort($events, function ($first_event, $second_event) {
                $date_comparison = $second_event['timestamp'] <=> $first_event['timestamp'];
                return $date_comparison !== 0
                    ? $date_comparison
                    : $second_event['order_id'] <=> $first_event['order_id'];
            });

            $this->monthly_orders_cache[$month] = $events;
        }

        $events = $this->monthly_orders_cache[$month];
        $total = count($events);
        $limit = max(1, (int) $limit);
        $max_num_pages = max(1, (int) ceil($total / $limit));
        $page = min(max(1, (int) $page), $max_num_pages);

        $page_events = array_slice($events, ($page - 1) * $limit, $limit);
        $order_ids = array_values(array_unique(array_column($page_events, 'order_id')));
        $orders_by_id = array();

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order instanceof WC_Order) {
                $orders_by_id[$order->get_id()] = $order;
            }
        }

        $hydrated_events = array();
        foreach ($page_events as $event) {
            if (!isset($orders_by_id[$event['order_id']])) {
                continue;
            }
            $event['order'] = $orders_by_id[$event['order_id']];
            unset($event['order_id']);
            $hydrated_events[] = $event;
        }

        return (object) array(
            'orders' => $hydrated_events,
            'total' => $total,
            'max_num_pages' => $max_num_pages,
            'page' => $page,
        );
    }

    /** Only accept four stored digits; never infer them from transaction IDs. */
    private function last_four($value) {
        return is_scalar($value) && preg_match('/^[0-9]{4}$/D', (string) $value) ? (string) $value : '';
    }

    public function get_payment_details($order) {
        $recorded = $order->get_meta('_wac_recorded_payment_method', true);
        if (in_array($recorded, array('ach', 'check'), true)) {
            $reference = $order->get_meta('_wac_payment_reference', true);
            return array(
                'payment' => $recorded === 'ach' ? 'ACH' : 'Check',
                'last4' => '',
                'reference' => is_scalar($reference) ? (string) $reference : '',
            );
        }

        $gateway = $order->get_payment_method();
        $details = array(
            'payment' => $order->get_payment_method_title() ?: ($gateway ?: 'Not recorded'),
            'last4' => '',
            'reference' => '',
        );
        // SkyVerge stores order-specific account_four for both cards and eChecks.
        // Restrict this lookup to card gateways so bank digits are never labeled card digits.
        if (in_array($gateway, array('authorize_net_cim_credit_card', 'authorize_net_aim'), true)) {
            $details['last4'] = $this->last_four($order->get_meta('_wc_' . $gateway . '_account_four', true));
            if ($details['last4'] === '') {
                $details['last4'] = 'Not recorded';
            }
        }
        return $details;
    }

    /** Whether this is a subscription renewal or contains a renewal product. */
    private function is_subscription_renewal($order) {
        if (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order)) {
            return true;
        }

        foreach ($order->get_items() as $item) {
            if (stripos((string) $item->get_name(), 'renewal') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the structured data array used by the table and the export.
     *
     * @param  array[] $orders Completion/refund events from get_completed_orders().
     * @return array
     */
    private function build_order_rows(array $orders) {
        $rows = array();

        foreach ($orders as $event) {
            $order = $event['order'];
            $order_status = $event['status'];
            $order_total = $event['total'];

            $order_id    = $order->get_id();
            $customer_id = $order->get_customer_id();

            // ── Customer account and ACF user fields ───────────────────────────────
            $user              = $customer_id ? get_userdata($customer_id) : false;
            if ($user) {
                $customer = trim((string) $user->first_name . ' ' . (string) $user->last_name);
                if ($customer === '') {
                    $customer = trim(
                        (string) $order->get_billing_first_name() . ' ' .
                        (string) $order->get_billing_last_name()
                    );
                }
                $email = (string) $user->user_email;
                if ($email === '') {
                    $email = (string) $order->get_billing_email();
                }
            } else {
                $customer = trim(
                    (string) $order->get_billing_first_name() . ' ' .
                    (string) $order->get_billing_last_name()
                );
                $customer = $customer !== '' ? $customer . ' (Guest)' : '(Guest)';
                $email = (string) $order->get_billing_email();
            }

            if ($customer === '') {
                if ($user) {
                    $customer = trim((string) $user->display_name);
                    if ($customer === '') {
                        $customer = $email !== '' ? $email : 'Customer #' . $customer_id;
                    }
                } else {
                    $customer = '(Guest)';
                }
            }
            $additional_emails = $customer_id
                ? (string) get_user_meta($customer_id, 'wc_additional_email_addresses', true)
                : '';

            $user_fields = array(
                'group_number' => '',
                'local_board'  => '',
                'phone'        => '',
                'mobile'       => '',
                'sales_staff'  => '',
            );

            if ($customer_id) {
                foreach (array_keys($user_fields) as $field_name) {
                    $user_fields[$field_name] = (string) get_user_meta($customer_id, $field_name, true);
                }
            }

            // ── Order items ───────────────────────────────────────────────
            $item_names = array();
            foreach ($order->get_items() as $item) {
                /** @var WC_Order_Item_Product $item */
                $qty          = $item->get_quantity();
                $item_names[] = $item->get_name() . ($qty > 1 ? ' ×' . $qty : '');
            }
            $item_names = array_values(array_unique($item_names));

            // ── Invoice number & PDF link ──────────────────────────
            // Webtoffee PDF Invoices plugin stores:
            //   wf_invoice_number  — the invoice number (may include prefix, e.g. "WC095510")
            $invoice_number = $this->get_invoice_number($order);
            $invoice_url    = $invoice_number ? $this->get_invoice_url($order_id) : '';

            $payment = $this->get_payment_details($order);
            $report_date_timestamp = $event['timestamp'];
            $rows[] = array(
                'payment' => $payment['payment'],
                'last4' => $payment['last4'],
                'reference' => $payment['reference'],
                'edit_url' => $order->get_edit_order_url(),
                'completed' => $report_date_timestamp ? wp_date('F j, Y', $report_date_timestamp, wp_timezone()) : '',
                'order_id'       => $order_id,
                'date'           => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
                'customer'       => $customer,
                'customer_id'    => $customer_id,
                'status'         => $order_status,
                'items'          => $item_names,
                'renewal'        => $this->is_subscription_renewal($order) ? 'Yes' : 'No',
                'total'          => $order_total,
                'currency'       => $order->get_currency(),
                'invoice_number' => $invoice_number,
                'invoice_url'    => $invoice_url,
                'email'          => $email,
                'extra_emails'   => $additional_emails,
                'group_number'   => $user_fields['group_number'],
                'local_board'    => $user_fields['local_board'],
                'phone'          => $user_fields['phone'],
                'mobile'         => $user_fields['mobile'],
                'sales_staff'    => $user_fields['sales_staff'],
            );
        }

        return $rows;
    }

    /**
     * Retrieve the invoice number stored by the Webtoffee PDF Invoices plugin.
     *
     * Confirmed meta key from official Webtoffee documentation:
     *   wf_invoice_number  (no leading underscore)
     *
     * This key stores the full invoice number as displayed in the plugin metabox,
     * including any configured prefix/suffix (e.g. "WC095510").
     *
     * @param  WC_Order $order
     * @return string   Invoice number, or empty string if not yet generated.
     */
    private function get_invoice_number(WC_Order $order) {
        $value = $order->get_meta('wf_invoice_number', true);
        if ($value !== '' && $value !== null && $value !== false) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Build the admin URL to download a PDF invoice via the WebToffee plugin.
     *
     * WebToffee's admin helper builds:
     *   wp-admin/?print_packinglist=true&post={order_id}&type=download_invoice&_wpnonce={nonce}
     * with nonce action WF_PKLIST_PLUGIN_NAME.
     *
     * @param  int $order_id
     * @return string
     */
    private function get_invoice_url($order_id) {
        $order_id = absint($order_id);
        if ($order_id <= 0) {
            return '';
        }

        if (
            class_exists('Wf_Woocommerce_Packing_List_Admin') &&
            method_exists('Wf_Woocommerce_Packing_List_Admin', 'get_print_url')
        ) {
            return Wf_Woocommerce_Packing_List_Admin::get_print_url($order_id, 'download_invoice');
        }

        $nonce_action = defined('WF_PKLIST_PLUGIN_NAME')
            ? WF_PKLIST_PLUGIN_NAME
            : 'print-invoices-packing-slip-labels-for-woocommerce';

        return wp_nonce_url(
            admin_url('?print_packinglist=true&post=' . $order_id . '&type=download_invoice'),
            $nonce_action
        );
    }

    /**
     * Return a human-readable, CSS-class-friendly status label.
     *
     * @param  string $raw_status  e.g. "pending", "on-hold"
     * @return array{ label: string, class: string }
     */
    private function status_meta($raw_status) {
        $labels = array(
            'completed'      => array('label' => 'Completed',       'class' => 'status-completed'),
            'pending'        => array('label' => 'Pending Payment', 'class' => 'status-pending'),
            'processing'     => array('label' => 'Processing',      'class' => 'status-processing'),
            'on-hold'        => array('label' => 'On Hold',         'class' => 'status-on-hold'),
            'cancelled'      => array('label' => 'Cancelled',       'class' => 'status-cancelled'),
            'refunded'       => array('label' => 'Refunded',        'class' => 'status-refunded'),
            'failed'         => array('label' => 'Failed',          'class' => 'status-failed'),
            'checkout-draft' => array('label' => 'Draft',           'class' => 'status-draft'),
        );

        if (isset($labels[$raw_status])) {
            return $labels[$raw_status];
        }

        return array(
            'label' => ucwords(str_replace('-', ' ', $raw_status)),
            'class' => 'status-custom',
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // EXPORT
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Handle export requests triggered by query string
     */
    public function handle_export() {
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $page   = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        if (
            $page !== 'wc-completed-orders' ||
            !in_array($action, array('ftwcc_export_csv', 'ftwcc_export_xlsx'), true)
        ) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        check_admin_referer('ftwcc_export');

        if (!function_exists('wc_get_orders')) {
            wp_die('WooCommerce is not active.');
        }
        $rows = $this->export_rows();

        if ($action === 'ftwcc_export_csv') {
            $this->export_csv($rows);
        }

        $this->export_xlsx($rows);
    }

    /** Fetch the complete export in bounded server-side batches. */
    private function export_rows() {
        $batch = 1;
        do {
            $result = $this->get_completed_orders($batch, self::EXPORT_BATCH_SIZE);
            $max_batches = $result->max_num_pages;
            foreach ($this->build_order_rows($result->orders) as $row) {
                yield $row;
            }
            unset($result);
            gc_collect_cycles();
            $batch++;
        } while ($batch <= $max_batches);
    }

    /**
     * Export column headers.
     *
     * @return string[]
     */
    private function get_export_headers() {
        return array(
            'Order ID',
            'Date Invoiced',
            'Event Date',
            'Customer',
            'Status',
            'Product',
            'Membership Renewal',
            'Total',
            'Invoice #',
            'Email',
            'Additional Emails',
            'Group Number',
            'Local Board',
            'Phone',
            'Mobile',
            'Sales Staff',
            'Payment Method',
            'Credit Card Last 4 Digits',
            'ACH / Check Reference',
            'Currency',
        );
    }

    /**
     * Format one row for CSV export.
     *
     * @param array $row
     * @return array
     */
    private function get_export_row(array $row) {
        $date_invoiced_timestamp = $row['date'] ? strtotime($row['date']) : false;
        $formatted_total = html_entity_decode(
            wp_strip_all_tags(wc_price($row['total'], array('currency' => $row['currency']))),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $product_names = array_map(
            function ($item_name) {
                return html_entity_decode(
                    wp_strip_all_tags((string) $item_name),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
            },
            $row['items']
        );

        return array(
            $row['order_id'],
            $date_invoiced_timestamp ? date('Y-m-d', $date_invoiced_timestamp) : '',
            $row['completed'],
            $row['customer'],
            ucwords(str_replace('-', ' ', $row['status'])),
            implode(', ', $product_names),
            $row['renewal'],
            $formatted_total,
            $row['invoice_number'],
            $row['email'],
            $row['extra_emails'],
            $row['group_number'],
            $row['local_board'],
            $row['phone'],
            $row['mobile'],
            $row['sales_staff'],
            $row['payment'],
            $row['last4'],
            $row['reference'],
            $row['currency'],
        );
    }

    /** Return the columns included in the XLSX export. */
    private function get_xlsx_headers() {
        return array(
            'Invoice #',
            'Order ID',
            'Event Date',
            'Customer',
            'Status',
            'Product',
            'Membership Renewal',
            'Total',
            'Payment Method',
            'Credit Card Last 4 Digits',
            'ACH / Check Reference',
            'Email',
        );
    }

    /** Format one row using the XLSX column order. */
    private function get_xlsx_row(array $row) {
        $product_names = array_map(
            function ($item_name) {
                return html_entity_decode(
                    wp_strip_all_tags((string) $item_name),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
            },
            $row['items']
        );

        return array(
            $row['invoice_number'],
            $row['order_id'],
            $row['completed'],
            $row['customer'],
            ucwords(str_replace('-', ' ', $row['status'])),
            implode(', ', $product_names),
            $row['renewal'],
            $row['total'],
            $row['payment'],
            $row['last4'],
            $row['reference'],
            $row['email'],
        );
    }

    /** Build a unique export filename for the current site and report month. */
    private function get_export_filename($extension) {
        $site_domain = wp_parse_url(home_url(), PHP_URL_HOST);
        $site_domain = $site_domain ? sanitize_file_name($site_domain) : 'wordpress-site';
        $unique_id = gmdate('YmdHis') . wp_rand(1000, 9999);

        return sprintf(
            '%s-wc-order-report-%s-%s.%s',
            $site_domain,
            $this->get_selected_month(),
            $unique_id,
            $extension
        );
    }

    /**
     * Export order rows to CSV.
     *
     * @param array $rows
     */
    private function export_csv(iterable $rows) {
        $filename = $this->get_export_filename('csv');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $output = fopen('php://output', 'w');
        if ($output === false) {
            wp_die('Unable to create CSV export.');
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $this->get_export_headers());

        foreach ($rows as $row) {
            fputcsv($output, array_map(function ($value) {
                $value = (string) $value;
                return preg_match('/^[\x00-\x20]*[=+@-]/', $value) ? "'" . $value : $value;
            }, $this->get_export_row($row)));
        }

        fclose($output);
        exit;
    }

    /**
     * Return whether any PhpSpreadsheet class/interface/trait is already loaded.
     *
     * Loading a second unprefixed PhpSpreadsheet copy in WordPress can mix class
     * versions with other plugins and cause fatal method signature errors.
     *
     * @return bool
     */
    private function has_loaded_phpspreadsheet_symbols() {
        $symbols = array_merge(get_declared_classes(), get_declared_interfaces());

        if (function_exists('get_declared_traits')) {
            $symbols = array_merge($symbols, get_declared_traits());
        }

        foreach ($symbols as $symbol) {
            if (strpos($symbol, 'PhpOffice\\PhpSpreadsheet\\') === 0) {
                return true;
            }
        }

        return false;
    }

    /** Reuse WP All Export's library; never load a second bundled copy. */
    private function load_spreadsheet_library() {
        if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet') && class_exists('PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx')) {
            return;
        }
        if ($this->has_loaded_phpspreadsheet_symbols()) {
            throw new \RuntimeException('An incomplete PhpSpreadsheet library is already loaded. Use CSV or update the plugin providing the spreadsheet library.');
        }
        if (!class_exists('PMXE_Plugin', false) || !defined('PMXE_Plugin::ROOT_DIR')) {
            throw new \RuntimeException('Activate WP All Export Pro with its PhpSpreadsheet library to export XLSX, or use CSV.');
        }
        $autoload = rtrim(\PMXE_Plugin::ROOT_DIR, '/\\') . '/vendor/autoload.php';
        if (!is_readable($autoload)) {
            throw new \RuntimeException('This WP All Export installation does not expose vendor/autoload.php. Use CSV or a WP All Export version that includes PhpSpreadsheet.');
        }
        require_once $autoload;
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet') || !class_exists('PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx')) {
            throw new \RuntimeException('This WP All Export version does not provide a compatible PhpSpreadsheet XLSX writer. Use CSV.');
        }
    }

    /**
     * Export order rows to XLSX via PhpSpreadsheet.
     *
     * @param array $rows
     */
    private function export_xlsx(iterable $rows) {
        $temp_file = '';

        try {
            $this->load_spreadsheet_library();

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet       = $spreadsheet->getActiveSheet();

            // Headers
            $headers = $this->get_xlsx_headers();
            $columns = range('A', 'L');

            foreach ($headers as $index => $header) {
                $sheet->setCellValue($columns[$index] . '1', $header);
            }

            $header_style = array(
                'font' => array('bold' => true),
                'fill' => array(
                    'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => array('rgb' => 'E8E8E8'),
                ),
            );
            $sheet->getStyle('A1:L1')->applyFromArray($header_style);

            // Data rows
            $row = 2;
            foreach ($rows as $r) {
                foreach ($this->get_xlsx_row($r) as $index => $value) {
                    $cell = $columns[$index] . $row;

                    if ($index === 7) {
                        $xlsx_total = $r['status'] === 'refunded'
                            ? -abs((float) $r['total'])
                            : (float) $r['total'];
                        $sheet->getCell($cell)->setValueExplicit(
                            $xlsx_total,
                            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                        );
                        continue;
                    }

                    if ($index === 1) {
                        $sheet->getCell($cell)->setValueExplicit(
                            (int) $value,
                            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                        );
                        continue;
                    }

                    if ($index === 9 && preg_match('/^[0-9]{4}$/D', (string) $value)) {
                        $sheet->getCell($cell)->setValueExplicit(
                            (int) $value,
                            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                        );
                        continue;
                    }

                    $sheet->getCell($cell)->setValueExplicit((string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
                $row++;
            }

            if ($row > 2) {
                $sheet->getStyle('H2:H' . ($row - 1))
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00;(#,##0.00)');
                $sheet->getStyle('J2:J' . ($row - 1))
                    ->getNumberFormat()
                    ->setFormatCode('0000');
            }

            foreach ($columns as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            $filename = $this->get_export_filename('xlsx');
            $temp_file = wp_tempnam($filename);

            if (!$temp_file) {
                throw new \RuntimeException('Unable to create XLSX export file.');
            }

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($temp_file);

            $file_size = filesize($temp_file);
            if ($file_size === false || $file_size === 0) {
                throw new \RuntimeException('Unable to finalize XLSX export file.');
            }

            while (ob_get_level() > 0) {
                if (!@ob_end_clean()) {
                    break;
                }
            }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . $file_size);
            header('Cache-Control: max-age=0');

            readfile($temp_file);
            @unlink($temp_file);
            exit;
        } catch (\Throwable $e) {
            if ($temp_file && file_exists($temp_file)) {
                @unlink($temp_file);
            }

            ftwcc_write_export_error_log(
                $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
            );

            $message = '<p>Unable to generate XLSX export.</p>' .
                '<p><strong>Error:</strong> ' . esc_html($e->getMessage()) . '</p>';

            wp_die($message, 'XLSX Export Error', array('response' => 500));
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DISPLAY
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Main admin page callback
     */
    public function display_orders_page() {
        if (!function_exists('wc_get_orders')) {
            echo '<div class="notice notice-error"><p>WooCommerce is not active.</p></div>';
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }
        $column_prefs = $this->get_column_preferences();
        $month = $this->get_selected_month();
        $selected_year = (int) substr($month, 0, 4);
        $selected_month_number = (int) substr($month, 5, 2);
        $current_year = (int) current_datetime()->format('Y');
        $first_year = max($current_year + 1, $selected_year);
        $last_year = min($current_year - 10, $selected_year);
        $page = isset($_GET['ftwcc_paged']) && is_scalar($_GET['ftwcc_paged']) ? max(1, absint($_GET['ftwcc_paged'])) : 1;
        $result = $this->get_completed_orders($page);
        $page = $result->page;
        $rows = $this->build_order_rows($result->orders);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form class="wco-order-filters wco-order-filters--top" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="wc-completed-orders">
                <label for="ftwcc-month-number">Month:</label>
                <select id="ftwcc-month-number" name="ftwcc_month_number" required>
                    <?php for ($month_number = 1; $month_number <= 12; $month_number++) : ?>
                        <option value="<?php echo esc_attr($month_number); ?>" <?php selected($selected_month_number, $month_number); ?>>
                            <?php echo esc_html(wp_date('F', gmmktime(12, 0, 0, $month_number, 1, 2000), new DateTimeZone('UTC'))); ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <label for="ftwcc-year">Year:</label>
                <select id="ftwcc-year" name="ftwcc_year" required>
                    <?php for ($year = $first_year; $year >= $last_year; $year--) : ?>
                        <option value="<?php echo esc_attr($year); ?>" <?php selected($selected_year, $year); ?>>
                            <?php echo esc_html($year); ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <button type="submit" class="button button-primary">Filter</button>
            </form>

            <!-- Controls bar -->
            <div class="usr-controls">
                <div class="usr-export-buttons">
                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                        <input type="hidden" name="page" value="wc-completed-orders">
                        <input type="hidden" name="ftwcc_month" value="<?php echo esc_attr($month); ?>">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ftwcc_export')); ?>">
                        <button type="submit" name="action" value="ftwcc_export_csv" class="button button-secondary">
                        <span class="dashicons dashicons-download"></span> Export CSV
                        </button>
                        <button type="submit" name="action" value="ftwcc_export_xlsx" class="button button-secondary">
                        <span class="dashicons dashicons-download"></span> Export XLSX
                        </button>
                    </form>
                </div>

                <div class="usr-column-controls">
                    <div class="usr-column-toggles">
                        <strong>Show/Hide Columns:</strong>
                        <?php
                        $toggles = array(
                            'id'       => 'Order ID',
                            'date'     => 'Event Date',
                            'payment'  => 'Payment Method',
                            'last4'    => 'Credit Card Last 4 Digits',
                            'reference' => 'ACH / Check Reference',
                            'customer' => 'Customer',
                            'status'   => 'Status',
                            'items'    => 'Product',
                            'renewal'  => 'Membership Renewal',
                            'total'    => 'Total',
                            'invoice'  => 'Invoice #',
                        );
                        foreach ($toggles as $col_key => $col_label) :
                        ?>
                            <label>
                                <input
                                    type="checkbox"
                                    class="usr-column-toggle"
                                    data-column="<?php echo esc_attr($col_key); ?>"
                                    <?php checked($column_prefs[$col_key]); ?>
                                >
                                <?php echo esc_html($col_label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                </div>
            </div>

            <div id="wco-table-wrap">
                <?php if (empty($rows)) : ?>
                    <div class="notice notice-info"><p>No completed orders found on this page for the selected month.</p></div>
                <?php else : ?>
                    <?php $this->render_table($rows, $column_prefs); ?>
                <?php endif; ?>
            </div>
            <?php if ($result->max_num_pages > 1) : ?>
                <div class="tablenav wco-pagination"><div class="tablenav-pages">
                <?php echo wp_kses_post(paginate_links(array(
                    'base' => str_replace('999999999', '%#%', add_query_arg(array('page' => 'wc-completed-orders', 'ftwcc_month' => $month, 'ftwcc_paged' => 999999999), admin_url('admin.php'))),
                    'format' => '', 'current' => $page, 'total' => $result->max_num_pages,
                ))); ?>
                </div></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the orders table
     *
     * @param array $rows         Rows from build_order_rows()
     * @param array $column_prefs Visibility preferences
     */
    private function render_table(array $rows, array $column_prefs) {
        // Helper: inline style to hide a column when pref is false
        $hide = function($key) use ($column_prefs) {
            return !$column_prefs[$key] ? ' style="display:none;"' : '';
        };
        ?>
        <table class="wp-list-table widefat fixed striped usr-renewals-table">
            <thead>
                <tr>
                    <th class="usr-col-id"<?php echo $hide('id'); ?>>
                        Order
                    </th>
                    <th class="usr-col-date"<?php echo $hide('date'); ?>>
                        Event Date
                    </th>
                    <th class="usr-col-customer"<?php echo $hide('customer'); ?>>
                        Customer
                    </th>
                    <th class="usr-col-status"<?php echo $hide('status'); ?>>
                        Status
                    </th>
                    <th class="usr-col-items"<?php echo $hide('items'); ?>>
                        Product
                    </th>
                    <th class="usr-col-renewal"<?php echo $hide('renewal'); ?>>
                        Membership Renewal
                    </th>
                    <th class="usr-col-total"<?php echo $hide('total'); ?>>
                        Total
                    </th>
                    <?php foreach (array('payment' => 'Payment Method', 'last4' => 'Credit Card Last 4 Digits', 'reference' => 'ACH / Check Reference') as $key => $label) : ?>
                        <th class="usr-col-<?php echo esc_attr($key); ?>"<?php echo $hide($key); ?>><?php echo esc_html($label); ?></th>
                    <?php endforeach; ?>
                    <th class="usr-col-invoice"<?php echo $hide('invoice'); ?>>
                        Invoice #
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) :
                    $status_meta = $this->status_meta($row['status']);
                ?>
                    <tr>
                        <!-- Order ID -->
                        <td class="usr-col-id"<?php echo $hide('id'); ?>>
                            <a href="<?php echo esc_url($row['edit_url']); ?>">
                                #<?php echo absint($row['order_id']); ?>
                            </a>
                        </td>

                        <!-- Date -->
                        <td class="usr-col-date"<?php echo $hide('date'); ?>>
                            <?php echo $row['completed'] ? esc_html($row['completed']) : '—'; ?>
                        </td>

                        <!-- Customer -->
                        <td class="usr-col-customer"<?php echo $hide('customer'); ?>>
                            <?php if ($row['customer_id']) : ?>
                                <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $row['customer_id'])); ?>">
                                    <?php echo esc_html($row['customer']); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html($row['customer']); ?>
                            <?php endif; ?>
                        </td>

                        <!-- Status badge -->
                        <td class="usr-col-status"<?php echo $hide('status'); ?>>
                            <span class="wco-status-badge <?php echo esc_attr($status_meta['class']); ?>">
                                <?php echo esc_html($status_meta['label']); ?>
                            </span>
                        </td>

                        <!-- Product -->
                        <td class="usr-col-items"<?php echo $hide('items'); ?>>
                            <?php if (!empty($row['items'])) : ?>
                                <ul class="wco-item-list">
                                    <?php foreach ($row['items'] as $item_name) : ?>
                                        <li><?php echo esc_html($item_name); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <em>No product</em>
                            <?php endif; ?>
                        </td>

                        <!-- Subscription renewal -->
                        <td class="usr-col-renewal"<?php echo $hide('renewal'); ?>>
                            <?php echo esc_html($row['renewal']); ?>
                        </td>

                        <!-- Total -->
                        <td class="usr-col-total"<?php echo $hide('total'); ?>>
                            <?php echo wp_kses_post(wc_price($row['total'], array('currency' => $row['currency']))); ?>
                        </td>

                        <?php foreach (array('payment', 'last4', 'reference') as $key) : ?>
                            <td class="usr-col-<?php echo esc_attr($key); ?>"<?php echo $hide($key); ?>><?php echo esc_html($row[$key] !== '' ? $row[$key] : '—'); ?></td>
                        <?php endforeach; ?>
                        <!-- Invoice -->
                        <td class="usr-col-invoice"<?php echo $hide('invoice'); ?>>
                            <?php if ($row['invoice_number'] && $row['invoice_url']) : ?>
                                <a
                                    href="<?php echo esc_url($row['invoice_url']); ?>"
                                    target="_blank"
                                    rel="noopener"
                                    class="wco-invoice-link"
                                    title="Download PDF invoice"
                                >
                                    <span class="dashicons dashicons-pdf"></span>
                                    <span class="wco-invoice-number"><?php echo esc_html($row['invoice_number']); ?></span>
                                </a>
                            <?php elseif ($row['invoice_number']) : ?>
                                <?php echo esc_html($row['invoice_number']); ?>
                            <?php else : ?>
                                <span class="wco-no-invoice">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="11">
                        <strong>Showing <?php echo count($rows); ?> orders</strong>
                    </td>
                </tr>
            </tfoot>
        </table>
        <?php
    }
}

// Initialise
function ft_wc_completed_orders_init() {
    new FT_WC_Completed_Orders_Report();
}
add_action('plugins_loaded', 'ft_wc_completed_orders_init');

add_action('before_woocommerce_init', function () {
    if (class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
