<?php
/**
 * Plugin Name:       Multe GDPR – Enforcement Tracker
 * Plugin URI:        https://www.eticarium.net/
 * Description:       Mostra una tabella interattiva delle sanzioni GDPR europee, con dati da enforcementtracker.com (CC BY-NC-SA 4.0). Usa lo shortcode [gdpr_fines_table].
 * Version:           1.0.0
 * Author:            Eticarium
 * Author URI:        https://www.eticarium.net/
 * License:           GPL-2.0+
 * Text Domain:       multe-gdpr
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MULTE_GDPR_VERSION', '1.0.0' );
define( 'MULTE_GDPR_DIR', plugin_dir_path( __FILE__ ) );
define( 'MULTE_GDPR_URL', plugin_dir_url( __FILE__ ) );

require_once MULTE_GDPR_DIR . 'includes/class-gdpr-fines-table.php';

/**
 * Inizializza il plugin.
 */
function multe_gdpr_init() {
    $table = new Multe_GDPR_Fines_Table();
    $table->register();
}
add_action( 'init', 'multe_gdpr_init' );

/**
 * Registra la pagina impostazioni nel menu admin di WordPress.
 */
function multe_gdpr_admin_menu() {
    add_options_page(
        __( 'Multe GDPR', 'multe-gdpr' ),
        __( 'Multe GDPR', 'multe-gdpr' ),
        'manage_options',
        'multe-gdpr-settings',
        'multe_gdpr_settings_page'
    );
}
add_action( 'admin_menu', 'multe_gdpr_admin_menu' );

/**
 * Registra le impostazioni del plugin.
 */
function multe_gdpr_register_settings() {
    register_setting( 'multe_gdpr_options', 'multe_gdpr_json_url', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ] );
    register_setting( 'multe_gdpr_options', 'multe_gdpr_cache_hours', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 6,
    ] );
}
add_action( 'admin_init', 'multe_gdpr_register_settings' );

/**
 * Renderizza la pagina impostazioni.
 */
function multe_gdpr_settings_page() {
    $json_url    = get_option( 'multe_gdpr_json_url', '' );
    $cache_hours = get_option( 'multe_gdpr_cache_hours', 6 );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Multe GDPR – Impostazioni', 'multe-gdpr' ); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'multe_gdpr_options' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="multe_gdpr_json_url">
                            <?php esc_html_e( 'URL del JSON (GitHub raw)', 'multe-gdpr' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="url"
                               id="multe_gdpr_json_url"
                               name="multe_gdpr_json_url"
                               value="<?php echo esc_attr( $json_url ); ?>"
                               class="regular-text"
                               placeholder="https://raw.githubusercontent.com/OWNER/multe-gdpr/main/data/gdpr_fines.json" />
                        <p class="description">
                            <?php esc_html_e(
                                'URL raw del file gdpr_fines.json dal repository GitHub.',
                                'multe-gdpr'
                            ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="multe_gdpr_cache_hours">
                            <?php esc_html_e( 'Cache (ore)', 'multe-gdpr' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="number"
                               id="multe_gdpr_cache_hours"
                               name="multe_gdpr_cache_hours"
                               value="<?php echo esc_attr( $cache_hours ); ?>"
                               min="1"
                               max="48"
                               step="1" />
                        <p class="description">
                            <?php esc_html_e(
                                'Per quante ore mantenere in cache locale il JSON (default: 6).',
                                'multe-gdpr'
                            ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
