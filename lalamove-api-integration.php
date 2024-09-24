<?php
/*
Plugin Name: WooCommerce Lalamove API Integration
Description: Seamlessly integrate Lalamove delivery services with your WooCommerce store, allowing customers to choose Lalamove as their preferred delivery option at checkout. This plugin automates the process of calculating delivery fees and placing orders through the Lalamove API.
Version: 1.2
Author: Loqman
Plugin URI: https://api.whatsapp.com/send?phone=60102282505&text=Hello%20Loqman,%20I%20would%20like%20to%20provide%20feedback%20on%20your%20WooCommerce%20Lalamove%20API%20Integration%20plugin.
*/




// Add the custom fields to the checkout page
add_action('woocommerce_after_order_notes', 'custom_address_and_map_field');

function custom_address_and_map_field($checkout)
{
    echo '<div id="custom_address_field"><h3>' . __('Enter your address') . '</h3>';

    // Address input
    woocommerce_form_field('custom_address', array(
        'type' => 'text',
        'class' => array('custom-address-class form-row-wide'),
        'label' => __('Enter your address'),
        'placeholder' => __('1234 Main St'),
        'required' => true,
    ), $checkout->get_value('custom_address'));

    // Google Maps input
    echo '<h3>' . __('Pin Your Location') . '</h3>';
    echo '<div id="map" style="width:100%; height:400px;"></div>';
    echo '<input type="hidden" name="custom_latitude" id="custom_latitude" />';
    echo '<input type="hidden" name="custom_longitude" id="custom_longitude" />';
    echo '<button type="button" style="margin-top: 2rem;" id="get_location_button" class="button">' . __('Get Lalamove Price') . '</button>';
    echo '<div id="location_status"></div>';

    echo '</div>';
}

add_action('wp_enqueue_scripts', 'enqueue_google_maps_api');

function enqueue_google_maps_api()
{
    if (is_checkout()) {
        wp_enqueue_script('custom-map-script', plugins_url('/js/custom-map.js', __FILE__), array('jquery'), null, true);

        // Pass the API key and AJAX URL to the script
        wp_localize_script('custom-map-script', 'wc_map_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'google_maps_api_key' => ''
        ));
    }
}

add_action('wp_ajax_save_location_data', 'save_location_data');
add_action('wp_ajax_nopriv_save_location_data', 'save_location_data');

function save_location_data()
{
    if (isset($_POST['address']) && isset($_POST['latitude']) && isset($_POST['longitude'])) {
        $address = sanitize_text_field($_POST['address']);
        $latitude = sanitize_text_field($_POST['latitude']);
        $longitude = sanitize_text_field($_POST['longitude']);
        $order_id = sanitize_text_field($_POST['order_id']);
        $phone = sanitize_text_field($_POST['phone']);
        $first_name = sanitize_text_field($_POST['firstName']);
        $last_name = sanitize_text_field($_POST['lastName']);
        $fullname = $first_name . ' ' . $last_name;
        $remarks = sanitize_text_field($_POST['remarks']);


        WC()->session->set('phoneNumber', $phone);
        WC()->session->set('fullName', $fullname);
        WC()->session->set('remarks', $remarks);
        WC()->session->set('orderId2', $order_id);



        $latitude = sanitize_text_field($_POST['latitude']);
        $longitude = sanitize_text_field($_POST['longitude']);

        // Round the latitude and longitude to 6 decimal places
        $latitude = round($latitude, 6);
        $longitude = round($longitude, 6);

        // Convert the latitude and longitude back to strings if needed
        $latitude = number_format($latitude, 6, '.', '');
        $longitude = number_format($longitude, 6, '.', '');

        // Save data to session or other method if needed
        session_start();
        $_SESSION['custom_address'] = $address;
        $_SESSION['custom_latitude'] = $latitude;
        $_SESSION['custom_longitude'] = $longitude;

        error_log($address);
        error_log($latitude);
        error_log($longitude);

        $apiKey = '';
        $apiSecret = '';
        $time = time() * 1000;

        $body = json_encode([
            'data' => [
                'serviceType' => 'MOTORCYCLE',
                'language' => 'en_MY',
                'stops' => [
                    ['coordinates' => ['lat' => '3.1271', 'lng' => '101.6670'], 'address' => '30, Lorong Bukit Pantai 8, Bukit Pantai, KL'],
                    ['coordinates' => ['lat' => $latitude, 'lng' => $longitude], 'address' => $address]
                ],
                'item' => [
                    'quantity' => '1',
                    'weight' => 'LESS_THAN_3_KG',
                    'categories' => ['FOOD_DELIVERY'],
                    'handlingInstructions' => ['KEEP_UPRIGHT']
                ],
            ]
        ]);


        $path = '/v3/quotations';
        $method = 'POST';
        $toSign = $time . "\r\n" . $method . "\r\n" . $path . "\r\n\r\n" . $body;

        $hash = strtolower(hash_hmac('sha256', $toSign, $apiSecret));

        $headers = [
            "Authorization: hmac {$apiKey}:{$time}:{$hash}",
            'Content-Type: application/json',
            'Market: MY',
            'Accept: application/json'
        ];

        $ch = curl_init('https://rest.sandbox.lalamove.com' . $path);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $data = json_decode($response, true);

        if (curl_errno($ch)) {
            error_log('Lalamove API Error:' . curl_error($ch));
        } else {
            if (isset($data['data'])) {
                $quotationId = $data['data']['quotationId'];
                $stops = $data['data']['stops'];
                $lalamovePrice = $data['data']['priceBreakdown']['total'];


                $expirationTime = time() + (5 * 60); // Set expiration time to 5 minutes from now

                // Store both price and expiration time in the session
                WC()->session->set('lalamove_price', array(
                    'price' => $lalamovePrice,
                    'expires' => $expirationTime,
                ));
                WC()->session->set('lalamove_quotation_id', $quotationId);
                WC()->session->set('lalamove_stops', $stops);

                // Add Lalamove price as a fee to the cart
                add_action('woocommerce_cart_calculate_fees', 'add_lalamove_fee_to_cart');



                // Log the received quotation ID and stops
                error_log('Received Quotation ID: ' . $quotationId);
                error_log('Stops: ' . print_r($stops, true));
                error_log('Price: ' . $lalamovePrice);
            } else {
                error_log('Failed to get valid data from Lalamove API response: ' . $response);
            }
        }

        function add_lalamove_fee_to_cart()
        {
            $lalamoveData = WC()->session->get('lalamove_price');

            if ($lalamoveData && is_array($lalamoveData)) {
                $lalamovePrice = isset($lalamoveData['price']) ? $lalamoveData['price'] : null;
            } else {
                $lalamovePrice = null; // Handle the case where the data is missing or not in the expected format
            }

            if ($lalamovePrice) {
                error_log('Adding Lalamove fee to cart: ' . $lalamovePrice);
                WC()->cart->add_fee('Lalamove Delivery Fee', $lalamovePrice, true, '');
            } else {
                error_log('Lalamove price not found in session.');
            }
        }

        wp_send_json_success(array(
            'message' => 'Location data received and processed.',
        ));

        curl_close($ch);

        wp_send_json_success('Location data saved successfully.');
    } else {
        wp_send_json_error('Failed to save location data.');
    }
}

add_action('wp_ajax_custom_process_location', 'custom_process_location_data');
add_action('wp_ajax_nopriv_custom_process_location', 'custom_process_location_data');


add_action('woocommerce_review_order_before_order_total', 'display_lalamove_price_in_checkout');

function display_lalamove_price_in_checkout()
{
    // Retrieve the Lalamove price from the session
    $lalamoveData = WC()->session->get('lalamove_price');

    if ($lalamoveData && is_array($lalamoveData)) {
        $lalamovePrice = isset($lalamoveData['price']) ? $lalamoveData['price'] : null;
    } else {
        $lalamovePrice = null; // Handle the case where the data is missing or not in the expected format
    }


    if ($lalamovePrice) {
?>

        <?php
    }
}


add_action('woocommerce_cart_calculate_fees', 'add_lalamove_price_to_total');

function add_lalamove_price_to_total()
{
    $lalamoveData = WC()->session->get('lalamove_price');

    if ($lalamoveData && is_array($lalamoveData)) {
        $lalamovePrice = isset($lalamoveData['price']) ? $lalamoveData['price'] : null;
    } else {
        $lalamovePrice = null; // Handle the case where the data is missing or not in the expected format
    }


    if ($lalamovePrice) {
        WC()->cart->add_fee(__('Lalamove Delivery', 'woocommerce'), $lalamovePrice);
    }
}


add_action('woocommerce_checkout_update_order_meta', 'save_lalamove_price_to_order_meta');

function save_lalamove_price_to_order_meta($order_id)
{
    $lalamoveData = WC()->session->get('lalamove_price');

    if ($lalamoveData && is_array($lalamoveData) && isset($lalamoveData['price'])) {
        update_post_meta($order_id, '_lalamove_price', $lalamoveData['price']);
    }
}



add_action('woocommerce_admin_order_totals_after_total', 'display_lalamove_price_in_order_totals');
add_action('woocommerce_order_details_after_order_table', 'display_lalamove_price_in_order_totals');

function display_lalamove_price_in_order_totals($order)
{
    if (is_numeric($order)) {
        $order = wc_get_order($order); // In case $order is passed as order ID
    }

    if ($order instanceof WC_Order) {
        $lalamovePrice = $order->get_meta('_lalamove_price');
        if ($lalamovePrice) {
        ?>
            <tr>
                <td class="label"><?php _e('Lalamove Delivery:', 'woocommerce'); ?></td>
                <td width="1%"></td>
                <td class="total"><?php echo wc_price($lalamovePrice); ?></td>
            </tr>
<?php
        }
    }
}


// Save session data to order meta when checkout is processed
add_action('woocommerce_checkout_update_order_meta', 'save_custom_location_to_order');

function save_custom_location_to_order($order_id)
{
    session_start();
    if (isset($_SESSION['custom_address'])) {
        update_post_meta($order_id, 'custom_address', $_SESSION['custom_address']);
    }
    if (isset($_SESSION['custom_latitude'])) {
        update_post_meta($order_id, 'custom_latitude', $_SESSION['custom_latitude']);
    }
    if (isset($_SESSION['custom_longitude'])) {
        update_post_meta($order_id, 'custom_longitude', $_SESSION['custom_longitude']);
    }
    // Clear session data after saving
    unset($_SESSION['custom_address']);
    unset($_SESSION['custom_latitude']);
    unset($_SESSION['custom_longitude']);
}

// Display the custom fields in the admin order details
add_action('woocommerce_admin_order_data_after_billing_address', 'custom_address_and_map_field_display_admin_order_meta', 10, 1);

function custom_address_and_map_field_display_admin_order_meta($order)
{
    echo '<p><strong>' . __('Custom Address') . ':</strong> ' . get_post_meta($order->get_id(), 'custom_address', true) . '</p>';
    echo '<p><strong>' . __('Latitude') . ':</strong> ' . get_post_meta($order->get_id(), 'custom_latitude', true) . '</p>';
    echo '<p><strong>' . __('Longitude') . ':</strong> ' . get_post_meta($order->get_id(), 'custom_longitude', true) . '</p>';
}

// Function to retrieve latitude and longitude
function get_custom_latitude_longitude($order_id)
{
    $latitude = get_post_meta($order_id, 'custom_latitude', true);
    $longitude = get_post_meta($order_id, 'custom_longitude', true);
    return array('latitude' => $latitude, 'longitude' => $longitude);
}


add_action('woocommerce_payment_complete', 'place_lalamove_order_after_payment');

function place_lalamove_order_after_payment()
{
    $apiKey = 'pk_test_8eeaca1e20edac46dae03aa3a72cece7';
    $apiSecret = 'sk_test_DpjT16kM8LWyOocKrMRR31e7nb9z7ITVifD+e4GZVcIMM7nhHJARXroOL78zNGnz';
    $time = time() * 1000;

    // Retrieve the Lalamove details from the session
    $quotationId = WC()->session->get('lalamove_quotation_id');
    $stops = WC()->session->get('lalamove_stops');
    $fullname = WC()->session->get('fullName');
    $remarks = WC()->session->get('remarks');
    $phoneNumber = WC()->session->get('phoneNumber');
    $order_id = WC()->session->get('orderId2');


    //error log quotationid , stops, fullname, remarks, phonenumber, orderid
    error_log('Quotation ID: ' . $quotationId);
    error_log('Stops: ' . print_r($stops, true));
    error_log('Full Name: ' . $fullname);
    error_log('Remarks: ' . $remarks);
    error_log('Phone Number: ' . $phoneNumber);
    error_log('Order ID: ' . $order_id);








    if ($quotationId && $stops) {
        // Proceed with placing the Lalamove order
        place_lalamove_order($apiKey, $apiSecret, $time, $quotationId, $stops, $fullname, $phoneNumber, $remarks, $order_id);

        // Clear the session data after placing the order
        WC()->session->__unset('lalamove_quotation_id');
        WC()->session->__unset('lalamove_stops');
        WC()->session->__unset('lalamove_price');
    } else {
        error_log('Lalamove order details missing from session.');
    }
}

function formatPhoneNumber($phoneNumber, $defaultCountryCode = '60')
{
    // Step 1: Remove all non-numeric characters except for the leading +
    $phoneNumber = preg_replace('/[^\d+]/', '', $phoneNumber);

    // Step 2: Check if the phone number starts with a '+' followed by a valid country code
    if (preg_match('/^\+(\d{1,3})(\d+)$/', $phoneNumber, $matches)) {
        // Validate the country code and phone number
        $countryCode = $matches[1];
        $subscriberNumber = $matches[2];

        // Correct country code if necessary (e.g., handle common mistakes)
        if ($countryCode == '600') {
            $countryCode = '60';
        }

        // Return the correctly formatted number
        return "+$countryCode$subscriberNumber";
    }

    // Step 3: If the phone number does not start with '+', assume it's a local number
    if (preg_match('/^0(\d+)$/', $phoneNumber, $matches)) {
        $subscriberNumber = $matches[1];
        return "+$defaultCountryCode$subscriberNumber";
    }

    // Step 4: If the phone number starts with the country code directly
    if (preg_match('/^(\d{1,3})(\d+)$/', $phoneNumber, $matches)) {
        $countryCode = $matches[1];
        $subscriberNumber = $matches[2];
        return "+$countryCode$subscriberNumber";
    }

    // Return the phone number as is if it doesn't match any expected format
    return $phoneNumber;
}

function place_lalamove_order($apiKey, $apiSecret, $time, $quotationId, $stops, $fullname, $phoneNumber, $remarks, $order_id)
{
    $requestId = uniqid();

    $phoneNumber = formatPhoneNumber($phoneNumber);


    $body2 = json_encode([
        'data' => [
            'quotationId' => $quotationId,
            'sender' => [
                'stopId' => $stops[0]['stopId'] ?? '',
                'name' => 'Almost Famous Enterprise',
                'phone' => '+60123220632'
            ],
            'recipients' => [
                [
                    'stopId' => $stops[1]['stopId'] ?? '',
                    'name' => $fullname,
                    'phone' => $phoneNumber,
                    'remarks' => $remarks
                ]
            ],
            'isPODEnabled' => false,
            'metadata' => [
                'restaurantName' => 'Almost Famous Enterprise',
            ]
        ]
    ]);

    $path = '/v3/orders';
    $method = 'POST';
    $toSign = $time . "\r\n" . $method . "\r\n" . $path . "\r\n\r\n" . $body2;

    $hash = strtolower(hash_hmac('sha256', $toSign, $apiSecret));

    $headers = [
        "Authorization: hmac {$apiKey}:{$time}:{$hash}",
        'Content-Type: application/json',
        'Market: MY',
        'Request-ID: ' . $requestId,
        'Accept: application/json'
    ];

    $ch = curl_init('https://rest.sandbox.lalamove.com' . $path);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('Lalamove Order API Error:' . curl_error($ch));
    } else {
        error_log('Lalamove Order API Response: ' . $response);
    }

    curl_close($ch);
}


function validate_lalamove_price_before_payment()
{
    // Get current time in GMT+8 (Malaysia time)
    $timezone = new DateTimeZone('Asia/Kuala_Lumpur');
    $current_time = new DateTime('now', $timezone);
    $current_hour_minute = $current_time->format('H:i'); // Get the time in 'H:i' format
    $current_day = (int) $current_time->format('w'); // Get the day of the week (0 for Sunday, 6 for Saturday)

    // Check if the current day is Saturday or Sunday
    if ($current_day === 0 || $current_day === 6) {
        wc_add_notice('Orders are not accepted on Saturdays or Sundays. Please try again during weekdays.', 'error');
        return false; // This will prevent the checkout from proceeding
    }

    // Check if the current time is between 8:30 AM and 5:00 PM
    if ($current_hour_minute < '08:30' || $current_hour_minute >= '17:00') {
        wc_add_notice('Orders are only accepted between 8:30 AM and 5:00 PM (GMT+8). Please try again during this time.', 'error');
        return false; // This will prevent the checkout from proceeding
    }

    // Retrieve the Lalamove data from the session
    $lalamoveData = WC()->session->get('lalamove_price');

    // Check if the expiration time exists and is still valid
    if (!$lalamoveData || !isset($lalamoveData['expires']) || time() > $lalamoveData['expires']) {
        wc_add_notice('The Lalamove price has expired or not updated. Please refresh the checkout to get an updated price.', 'error');
        return false; // This will prevent the checkout from proceeding
    }
}


add_action('woocommerce_checkout_process', 'validate_lalamove_price_before_payment');
