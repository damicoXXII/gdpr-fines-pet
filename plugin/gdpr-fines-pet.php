<?php
/**
 * Plugin Name:       GDPR Fines PET
 * Plugin URI:        https://www.eticarium.net/
 * Description:       Mostra una tabella interattiva delle sanzioni GDPR europee, con dati da enforcementtracker.com (CC BY-NC-SA 4.0). Usa lo shortcode [gdpr_fines_table].
 * Version:           1.0.0
 * Author:            Eticarium
 * Author URI:        https://www.eticarium.net/
 * License:           GPL-2.0+
 * Text Domain:       gdpr-fines-pet
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GDPR_FINES_PET_VERSION', '1.0.0' );
define( 'GDPR_FINES_PET_DIR', plugin_dir_path( __FILE__ ) );
define( 'GDPR_FINES_PET_URL', plugin_dir_url( __FILE__ ) );

require_once GDPR_FINES_PET_DIR . 'includes/class-gdpr-fines-table.php';

/**
 * Inizializza il plugin.
 */
function gdpr_fines_pet_init() {
    $table = new GDPR_Fines_PET_Fines_Table();
    $table->register();
}
add_action( 'init', 'gdpr_fines_pet_init' );

/**
 * Registra la pagina impostazioni nel menu admin di WordPress.
 */
function gdpr_fines_pet_admin_menu() {
    add_options_page(
        __( 'GDPR Fines PET', 'gdpr-fines-pet' ),
        __( 'GDPR Fines PET', 'gdpr-fines-pet' ),
        'manage_options',
        'gdpr-fines-pet-settings',
        'gdpr_fines_pet_settings_page'
    );
}
add_action( 'admin_menu', 'gdpr_fines_pet_admin_menu' );

/**
 * Registra le impostazioni del plugin.
 */
function gdpr_fines_pet_register_settings() {
    register_setting( 'gdpr_fines_pet_options', 'gdpr_fines_pet_json_url', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ] );
    register_setting( 'gdpr_fines_pet_options', 'gdpr_fines_pet_cache_hours', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 6,
    ] );
}
add_action( 'admin_init', 'gdpr_fines_pet_register_settings' );

/**
 * Renderizza la pagina impostazioni.
 */
function gdpr_fines_pet_settings_page() {
    $json_url    = get_option( 'gdpr_fines_pet_json_url', '' );
    $cache_hours = get_option( 'gdpr_fines_pet_cache_hours', 6 );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'GDPR Fines PET – Impostazioni', 'gdpr-fines-pet' ); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'gdpr_fines_pet_options' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="gdpr_fines_pet_json_url">
                            <?php esc_html_e( 'URL del JSON (GitHub raw)', 'gdpr-fines-pet' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="url"
                               id="gdpr_fines_pet_json_url"
                               name="gdpr_fines_pet_json_url"
                               value="<?php echo esc_attr( $json_url ); ?>"
                               class="regular-text"
                               placeholder="https://raw.githubusercontent.com/OWNER/gdpr-fines-pet/main/data/gdpr_fines.json" />
                        <p class="description">
                            <?php esc_html_e(
                                'URL raw del file gdpr_fines.json dal repository GitHub.',
                                'gdpr-fines-pet'
                            ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="gdpr_fines_pet_cache_hours">
                            <?php esc_html_e( 'Cache (ore)', 'gdpr-fines-pet' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="number"
                               id="gdpr_fines_pet_cache_hours"
                               name="gdpr_fines_pet_cache_hours"
                               value="<?php echo esc_attr( $cache_hours ); ?>"
                               min="1"
                               max="48"
                               step="1" />
                        <p class="description">
                            <?php esc_html_e(
                                'Per quante ore mantenere in cache locale il JSON (default: 6).',
                                'gdpr-fines-pet'
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
