<?php
/**
 * Classe per il rendering della tabella sanzioni GDPR.
 *
 * Registra lo shortcode [gdpr_fines_table] e carica gli asset CSS/JS.
 * I dati vengono passati al frontend via wp_localize_script, usando
 * un transient WP come cache per evitare richieste eccessive a GitHub.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Multe_GDPR_Fines_Table {

    /** Transient key per la cache dei dati */
    const CACHE_KEY = 'multe_gdpr_fines_data';

    /**
     * Registra shortcode e hook.
     */
    public function register(): void {
        add_shortcode( 'gdpr_fines_table', [ $this, 'render_shortcode' ] );
    }

    /**
     * Recupera i dati JSON, con caching via transient WP.
     */
    private function get_fines_data(): ?array {
        // Prova dalla cache
        $cached = get_transient( self::CACHE_KEY );
        if ( $cached !== false ) {
            return $cached;
        }

        $json_url = get_option( 'multe_gdpr_json_url', '' );
        if ( empty( $json_url ) ) {
            return null;
        }

        $response = wp_remote_get( $json_url, [
            'timeout'    => 30,
            'user-agent' => 'EticariumGDPRPlugin/' . MULTE_GDPR_VERSION,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[Multe GDPR] Fetch error: ' . $response->get_error_message() );
            return null;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status !== 200 ) {
            error_log( '[Multe GDPR] Fetch returned HTTP ' . $status );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || ! isset( $data['fines'] ) ) {
            error_log( '[Multe GDPR] Invalid JSON structure' );
            return null;
        }

        // Salva in cache
        $cache_hours = (int) get_option( 'multe_gdpr_cache_hours', 6 );
        set_transient( self::CACHE_KEY, $data, $cache_hours * HOUR_IN_SECONDS );

        return $data;
    }

    /**
     * Renderizza lo shortcode.
     */
    public function render_shortcode( $atts ): string {
        // Carica CSS e JS solo quando lo shortcode viene usato
        wp_enqueue_style(
            'multe-gdpr-style',
            MULTE_GDPR_URL . 'assets/style.css',
            [],
            MULTE_GDPR_VERSION
        );

        wp_enqueue_script(
            'multe-gdpr-app',
            MULTE_GDPR_URL . 'assets/app.js',
            [],
            MULTE_GDPR_VERSION,
            true
        );

        // Recupera i dati
        $data = $this->get_fines_data();

        // Passa i dati al JS tramite variabile globale
        wp_localize_script( 'multe-gdpr-app', 'multeGdprData', [
            'fines'    => $data['fines'] ?? [],
            'metadata' => $data['metadata'] ?? [],
            'error'    => $data === null,
        ] );

        // Renderizza il contenitore HTML
        ob_start();
        ?>
        <div id="multe-gdpr-root" class="multe-gdpr">

            <!-- Header: contatore e data aggiornamento -->
            <div class="multe-gdpr__header">
                <div class="multe-gdpr__stats">
                    <span class="multe-gdpr__count">
                        <?php esc_html_e( 'Totale sanzioni tracciate:', 'multe-gdpr' ); ?>
                        <strong id="multe-gdpr-total">--</strong>
                    </span>
                    <span class="multe-gdpr__updated">
                        <?php esc_html_e( 'Ultimo aggiornamento:', 'multe-gdpr' ); ?>
                        <em id="multe-gdpr-updated">--</em>
                    </span>
                </div>
            </div>

            <!-- Filtri -->
            <div class="multe-gdpr__filters">
                <div class="multe-gdpr__filter-row">
                    <input type="text"
                           id="multe-gdpr-search"
                           class="multe-gdpr__input"
                           placeholder="<?php esc_attr_e( 'Cerca per paese, autorit&agrave;, settore...', 'multe-gdpr' ); ?>" />

                    <select id="multe-gdpr-country" class="multe-gdpr__select">
                        <option value=""><?php esc_html_e( 'Tutti i paesi', 'multe-gdpr' ); ?></option>
                    </select>
                </div>
                <div class="multe-gdpr__filter-row">
                    <input type="number"
                           id="multe-gdpr-fine-min"
                           class="multe-gdpr__input multe-gdpr__input--short"
                           placeholder="<?php esc_attr_e( 'Importo min (EUR)', 'multe-gdpr' ); ?>"
                           min="0" />
                    <input type="number"
                           id="multe-gdpr-fine-max"
                           class="multe-gdpr__input multe-gdpr__input--short"
                           placeholder="<?php esc_attr_e( 'Importo max (EUR)', 'multe-gdpr' ); ?>"
                           min="0" />
                    <button type="button"
                            id="multe-gdpr-reset"
                            class="multe-gdpr__btn">
                        <?php esc_html_e( 'Reset filtri', 'multe-gdpr' ); ?>
                    </button>
                </div>
            </div>

            <!-- Tabella -->
            <div class="multe-gdpr__table-wrap">
                <table class="multe-gdpr__table" id="multe-gdpr-table">
                    <thead>
                        <tr>
                            <th data-sort="date"><?php esc_html_e( 'Data', 'multe-gdpr' ); ?></th>
                            <th data-sort="country"><?php esc_html_e( 'Paese', 'multe-gdpr' ); ?></th>
                            <th data-sort="authority"><?php esc_html_e( 'Autorit&agrave;', 'multe-gdpr' ); ?></th>
                            <th data-sort="entity"><?php esc_html_e( 'Soggetto', 'multe-gdpr' ); ?></th>
                            <th data-sort="fine"><?php esc_html_e( 'Importo (EUR)', 'multe-gdpr' ); ?></th>
                            <th data-sort="sector"><?php esc_html_e( 'Settore', 'multe-gdpr' ); ?></th>
                            <th data-sort="articles"><?php esc_html_e( 'Articoli GDPR', 'multe-gdpr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="multe-gdpr-tbody">
                        <tr>
                            <td colspan="7" class="multe-gdpr__loading">
                                <?php esc_html_e( 'Caricamento dati...', 'multe-gdpr' ); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginazione -->
            <div class="multe-gdpr__pagination" id="multe-gdpr-pagination"></div>

            <!-- Footer attribuzione obbligatoria CC BY-NC-SA 4.0 -->
            <div class="multe-gdpr__footer">
                <p>
                    <?php
                    printf(
                        /* translators: %1$s: link to enforcementtracker.com, %2$s: link to CC license */
                        esc_html__(
                            'Dati forniti da %1$s, rilasciati sotto licenza %2$s.',
                            'multe-gdpr'
                        ),
                        '<a href="https://www.enforcementtracker.com/" target="_blank" rel="noopener noreferrer">enforcementtracker.com (CMS.Law)</a>',
                        '<a href="https://creativecommons.org/licenses/by-nc-sa/4.0/" target="_blank" rel="noopener noreferrer">CC BY-NC-SA 4.0</a>'
                    );
                    ?>
                </p>
                <p class="multe-gdpr__footer-note">
                    <?php esc_html_e(
                        'I nomi dei soggetti sanzionati sono oscurati. Per visualizzare i dettagli completi, clicca sul link nella colonna "Soggetto".',
                        'multe-gdpr'
                    ); ?>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
