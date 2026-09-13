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

class GDPR_Fines_PET_Fines_Table {

    /** Transient key per la cache dei dati */
    const CACHE_KEY = 'gdpr_fines_pet_fines_data';

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

        $json_url = get_option( 'gdpr_fines_pet_json_url', '' );
        if ( empty( $json_url ) ) {
            return null;
        }

        $response = wp_remote_get( $json_url, [
            'timeout'    => 30,
            'user-agent' => 'EticariumGDPRPlugin/' . GDPR_FINES_PET_VERSION,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[GDPR Fines PET] Fetch error: ' . $response->get_error_message() );
            return null;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status !== 200 ) {
            error_log( '[GDPR Fines PET] Fetch returned HTTP ' . $status );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || ! isset( $data['fines'] ) ) {
            error_log( '[GDPR Fines PET] Invalid JSON structure' );
            return null;
        }

        // Salva in cache
        $cache_hours = (int) get_option( 'gdpr_fines_pet_cache_hours', 6 );
        set_transient( self::CACHE_KEY, $data, $cache_hours * HOUR_IN_SECONDS );

        return $data;
    }

    /**
     * Renderizza lo shortcode.
     */
    public function render_shortcode( $atts ): string {
        // Carica CSS e JS solo quando lo shortcode viene usato
        wp_enqueue_style(
            'gdpr-fines-pet-style',
            GDPR_FINES_PET_URL . 'assets/style.css',
            [],
            GDPR_FINES_PET_VERSION
        );

        wp_enqueue_script(
            'gdpr-fines-pet-app',
            GDPR_FINES_PET_URL . 'assets/app.js',
            [],
            GDPR_FINES_PET_VERSION,
            true
        );

        // Recupera i dati
        $data = $this->get_fines_data();

        // Passa i dati al JS tramite variabile globale
        wp_localize_script( 'gdpr-fines-pet-app', 'gdprFinesPetData', [
            'fines'    => $data['fines'] ?? [],
            'metadata' => $data['metadata'] ?? [],
            'error'    => $data === null,
        ] );

        // Renderizza il contenitore HTML
        ob_start();
        ?>
        <div id="gdpr-fines-pet-root" class="gdpr-fines-pet">

            <!-- Header: contatore e data aggiornamento -->
            <div class="gdpr-fines-pet__header">
                <div class="gdpr-fines-pet__stats">
                    <span class="gdpr-fines-pet__count">
                        <?php esc_html_e( 'Totale sanzioni tracciate:', 'gdpr-fines-pet' ); ?>
                        <strong id="gdpr-fines-pet-total">--</strong>
                    </span>
                    <span class="gdpr-fines-pet__updated">
                        <?php esc_html_e( 'Ultimo aggiornamento:', 'gdpr-fines-pet' ); ?>
                        <em id="gdpr-fines-pet-updated">--</em>
                    </span>
                </div>
            </div>

            <!-- Filtri -->
            <div class="gdpr-fines-pet__filters">
                <div class="gdpr-fines-pet__filter-row">
                    <input type="text"
                           id="gdpr-fines-pet-search"
                           class="gdpr-fines-pet__input"
                           placeholder="<?php esc_attr_e( 'Cerca per paese, autorit&agrave;, settore...', 'gdpr-fines-pet' ); ?>" />

                    <select id="gdpr-fines-pet-country" class="gdpr-fines-pet__select">
                        <option value=""><?php esc_html_e( 'Tutti i paesi', 'gdpr-fines-pet' ); ?></option>
                    </select>
                </div>
                <div class="gdpr-fines-pet__filter-row">
                    <input type="number"
                           id="gdpr-fines-pet-fine-min"
                           class="gdpr-fines-pet__input gdpr-fines-pet__input--short"
                           placeholder="<?php esc_attr_e( 'Importo min (EUR)', 'gdpr-fines-pet' ); ?>"
                           min="0" />
                    <input type="number"
                           id="gdpr-fines-pet-fine-max"
                           class="gdpr-fines-pet__input gdpr-fines-pet__input--short"
                           placeholder="<?php esc_attr_e( 'Importo max (EUR)', 'gdpr-fines-pet' ); ?>"
                           min="0" />
                    <button type="button"
                            id="gdpr-fines-pet-reset"
                            class="gdpr-fines-pet__btn">
                        <?php esc_html_e( 'Reset filtri', 'gdpr-fines-pet' ); ?>
                    </button>
                </div>
            </div>

            <!-- Tabella -->
            <div class="gdpr-fines-pet__table-wrap">
                <table class="gdpr-fines-pet__table" id="gdpr-fines-pet-table">
                    <thead>
                        <tr>
                            <th data-sort="date"><?php esc_html_e( 'Data', 'gdpr-fines-pet' ); ?></th>
                            <th data-sort="country"><?php esc_html_e( 'Paese', 'gdpr-fines-pet' ); ?></th>
                            <th data-sort="authority"><?php esc_html_e( 'Autorit&agrave;', 'gdpr-fines-pet' ); ?></th>
                            <th data-sort="entity"><?php esc_html_e( 'Soggetto', 'gdpr-fines-pet' ); ?></th>
                            <th data-sort="fine"><?php esc_html_e( 'Importo (EUR)', 'gdpr-fines-pet' ); ?></th>
                            <th data-sort="sector"><?php esc_html_e( 'Settore', 'gdpr-fines-pet' ); ?></th>
                            <th data-sort="articles"><?php esc_html_e( 'Articoli GDPR', 'gdpr-fines-pet' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="gdpr-fines-pet-tbody">
                        <tr>
                            <td colspan="7" class="gdpr-fines-pet__loading">
                                <?php esc_html_e( 'Caricamento dati...', 'gdpr-fines-pet' ); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginazione -->
            <div class="gdpr-fines-pet__pagination" id="gdpr-fines-pet-pagination"></div>

            <!-- Footer attribuzione obbligatoria CC BY-NC-SA 4.0 -->
            <div class="gdpr-fines-pet__footer">
                <p>
                    <?php
                    printf(
                        /* translators: %1$s: link to enforcementtracker.com, %2$s: link to CC license */
                        esc_html__(
                            'Dati forniti da %1$s, rilasciati sotto licenza %2$s.',
                            'gdpr-fines-pet'
                        ),
                        '<a href="https://www.enforcementtracker.com/" target="_blank" rel="noopener noreferrer">enforcementtracker.com (CMS.Law)</a>',
                        '<a href="https://creativecommons.org/licenses/by-nc-sa/4.0/" target="_blank" rel="noopener noreferrer">CC BY-NC-SA 4.0</a>'
                    );
                    ?>
                </p>
                <p class="gdpr-fines-pet__footer-note">
                    <?php esc_html_e(
                        'I nomi dei soggetti sanzionati sono oscurati. Per visualizzare i dettagli completi, clicca sul link nella colonna "Soggetto".',
                        'gdpr-fines-pet'
                    ); ?>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
