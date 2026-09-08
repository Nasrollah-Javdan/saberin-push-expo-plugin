<?php
/**
 * Plugin Name: Saberin Push
 * Description: اتصال اپ Saberin به WordPress و ارسال Push Notification از طریق Expo.
 * Version: 1.1.0
 * Author: Saberin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

/**
 * Create database table when plugin is activated.
 */
function saberin_push_activate() {

    global $wpdb;

    $table_name = $wpdb->prefix . 'saberin_push_tokens';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        token VARCHAR(255) NOT NULL,
        platform VARCHAR(20) NOT NULL DEFAULT 'android',
        device_id VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY token_unique (token)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( $sql );
}

register_activation_hook( __FILE__, 'saberin_push_activate' );


/*
|--------------------------------------------------------------------------
| REST API
|--------------------------------------------------------------------------
*/

/**
 * Register REST API routes.
 */
function saberin_push_register_routes() {

    register_rest_route(
        'saberin/v1',
        '/register',
        array(
            'methods'             => 'POST',
            'callback'            => 'saberin_push_register_token',
            'permission_callback' => '__return_true',

            'args' => array(

                'token' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),

                'platform' => array(
                    'required'          => false,
                    'default'           => 'android',
                    'sanitize_callback' => 'sanitize_text_field',
                ),

                'device_id' => array(
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),

            ),
        )
    );
}

add_action( 'rest_api_init', 'saberin_push_register_routes' );


/**
 * Register Expo Push Token.
 */
function saberin_push_register_token( WP_REST_Request $request ) {

    global $wpdb;

    $table_name = $wpdb->prefix . 'saberin_push_tokens';

    $token      = $request->get_param( 'token' );
    $platform   = $request->get_param( 'platform' );
    $device_id  = $request->get_param( 'device_id' );

    if ( empty( $token ) ) {

        return new WP_Error(
            'missing_token',
            'Push token is required.',
            array(
                'status' => 400,
            )
        );
    }

    /*
     * Check if token already exists.
     */
    $existing = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM $table_name WHERE token = %s LIMIT 1",
            $token
        )
    );

    if ( $existing ) {

        $wpdb->update(
            $table_name,
            array(
                'platform'  => $platform,
                'device_id'  => $device_id,
                'updated_at' => current_time( 'mysql' ),
            ),
            array(
                'id' => $existing->id,
            )
        );

        return array(
            'success' => true,
            'message' => 'Push token updated.',
            'id'      => (int) $existing->id,
        );
    }

    /*
     * Insert new token.
     */
    $wpdb->insert(
        $table_name,
        array(
            'token'      => $token,
            'platform'   => $platform,
            'device_id'  => $device_id,
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        )
    );

    return array(
        'success' => true,
        'message' => 'Push token registered.',
        'id'      => (int) $wpdb->insert_id,
    );
}


/*
|--------------------------------------------------------------------------
| WordPress Admin Menu
|--------------------------------------------------------------------------
*/

/**
 * Add Saberin Push admin menu.
 */
function saberin_push_admin_menu() {

    add_menu_page(
        'Saberin Push',
        'Saberin Push',
        'manage_options',
        'saberin-push',
        'saberin_push_admin_page',
        'dashicons-bell',
        30
    );
}

add_action( 'admin_menu', 'saberin_push_admin_menu' );


/*
|--------------------------------------------------------------------------
| Send Notification
|--------------------------------------------------------------------------
*/

/**
 * Send notification to Expo Push Service.
 */
function saberin_push_send_notification( $title, $body ) {

    global $wpdb;

    $table_name = $wpdb->prefix . 'saberin_push_tokens';

    $tokens = $wpdb->get_col(
        "SELECT token FROM $table_name ORDER BY id ASC"
    );

    if ( empty( $tokens ) ) {

        return array(
            'success' => false,
            'message' => 'No registered devices found.',
        );
    }

    $messages = array();

    foreach ( $tokens as $token ) {

        $messages[] = array(
            'to'    => $token,
            'title' => $title,
            'body'  => $body,
            'sound' => 'default',
            'data'  => array(
                'type' => 'notification',
            ),
        );
    }

    /*
     * Expo accepts up to 100 messages per request.
     */
    $chunks = array_chunk( $messages, 100 );

    $results = array();

    foreach ( $chunks as $chunk ) {

        $response = wp_remote_post(
            'https://exp.host/--/api/v2/push/send',
            array(
                'timeout' => 30,

                'headers' => array(
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ),

                'body' => wp_json_encode( $chunk ),
            )
        );

        if ( is_wp_error( $response ) ) {

            $results[] = array(
                'success' => false,
                'error'   => $response->get_error_message(),
            );

            continue;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        $response_body = wp_remote_retrieve_body( $response );

        $decoded = json_decode( $response_body, true );

        $results[] = array(
            'success' => ( $status_code >= 200 && $status_code < 300 ),
            'status'  => $status_code,
            'data'    => $decoded,
        );
    }

    return array(
        'success' => true,
        'count'   => count( $tokens ),
        'results' => $results,
    );
}


/*
|--------------------------------------------------------------------------
| Admin Page
|--------------------------------------------------------------------------
*/

/**
 * Display Saberin Push admin page.
 */
function saberin_push_admin_page() {

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;

    $table_name = $wpdb->prefix . 'saberin_push_tokens';

    $device_count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM $table_name"
    );

    $message = null;

    /*
     * Handle notification form.
     */
    if (
        isset( $_POST['saberin_push_send'] )
        && check_admin_referer(
            'saberin_push_send_notification',
            'saberin_push_nonce'
        )
    ) {

        $title = isset( $_POST['notification_title'] )
            ? sanitize_text_field( wp_unslash( $_POST['notification_title'] ) )
            : '';

        $body = isset( $_POST['notification_body'] )
            ? sanitize_textarea_field( wp_unslash( $_POST['notification_body'] ) )
            : '';

        if ( empty( $title ) || empty( $body ) ) {

            $message = array(
                'type' => 'error',
                'text' => 'عنوان و متن اعلان الزامی است.',
            );

        } else {

            $result = saberin_push_send_notification(
                $title,
                $body
            );

            if ( $result['success'] ) {

                $message = array(
                    'type' => 'success',
                    'text' => 'اعلان ارسال شد. تعداد دستگاه‌ها: ' . $result['count'],
                );

            } else {

                $message = array(
                    'type' => 'error',
                    'text' => $result['message'],
                );
            }
        }
    }

    ?>

    <div class="wrap">

        <h1>🔔 Saberin Push</h1>

        <p>
            ارسال اعلان به اپلیکیشن Saberin
        </p>

        <hr>

        <div
            style="
                background:#fff;
                border:1px solid #ddd;
                padding:20px;
                max-width:800px;
                margin-top:20px;
            "
        >

            <h2>وضعیت دستگاه‌ها</h2>

            <p style="font-size:18px;">
                دستگاه‌های ثبت‌شده:
                <strong>
                    <?php echo esc_html( $device_count ); ?>
                </strong>
            </p>

        </div>

        <?php if ( $message ) : ?>

            <div
                class="notice notice-<?php echo esc_attr( $message['type'] ); ?> is-dismissible"
                style="margin-top:20px;"
            >
                <p>
                    <?php echo esc_html( $message['text'] ); ?>
                </p>
            </div>

        <?php endif; ?>

        <div
            style="
                background:#fff;
                border:1px solid #ddd;
                padding:20px;
                max-width:800px;
                margin-top:20px;
            "
        >

            <h2>ارسال اعلان</h2>

            <form method="post">

                <?php
                wp_nonce_field(
                    'saberin_push_send_notification',
                    'saberin_push_nonce'
                );
                ?>

                <table class="form-table">

                    <tr>

                        <th>
                            <label for="notification_title">
                                عنوان اعلان
                            </label>
                        </th>

                        <td>

                            <input
                                type="text"
                                id="notification_title"
                                name="notification_title"
                                class="regular-text"
                                placeholder="مثلاً: اطلاعیه جدید مدرسه"
                                required
                            >

                        </td>

                    </tr>

                    <tr>

                        <th>
                            <label for="notification_body">
                                متن اعلان
                            </label>
                        </th>

                        <td>

                            <textarea
                                id="notification_body"
                                name="notification_body"
                                rows="6"
                                class="large-text"
                                placeholder="متن اعلان را وارد کنید..."
                                required
                            ></textarea>

                        </td>

                    </tr>

                </table>

                <p>

                    <button
                        type="submit"
                        name="saberin_push_send"
                        class="button button-primary button-large"
                    >
                        🔔 ارسال اعلان
                    </button>

                </p>

            </form>

        </div>

    </div>

    <?php
}