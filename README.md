# GDPR Fines PET - Un plugin WP privacy-first per informare e formare sulle sanzioni GDPR su scala europea.

Tabella interattiva in chiave PET (Privacy Enhanced Technology - Tecnologia a Protezione della Privacy) delle sanzioni GDPR europee. 
Dati estratti da [enforcementtracker.com](https://www.enforcementtracker.com/) (CMS.Law).

## Caratteristiche

- **Scraper Python** che estrae le sanzioni GDPR in un singolo passaggio
- **Plugin WordPress** con shortcode `[gdpr_fines_table]` per integrazione nativa
- **Tabella filtrabile e ordinabile**: ricerca testuale, filtro per paese, range importo
- **Aggiornamento automatico** ogni 6 ore tramite GitHub Actions.
- **Responsive**: funziona su desktop, tablet e smartphone moderni.
- **Attribuzione CC BY-NC-SA 4.0** incorporata, conforme alla licenza della fonte dati menzionata sopra.

## Caratteristiche PET
Da codice:
- **Soggetti censurati**: i nomi delle entità sanzionate sono oscurati. Si può visualizzare i soggetti cliccando sopra la barra di censura.
- **Nessun tracker**: il plugin non raccoglie dati di utilizzo del sito web in cui è inserito o degli utenti finali.

Suggeriti lato WP:
- **Doppio controllo di visualizzazione**: se si clicca un soggetto oscurato, una modale (o tecnologie affini) come check di reinvio al sito originale ("https://www.enforcementtracker.com/[IDSANZIONE]")
- **Privacy by design e default**: principi applicati a tutto il sito, non solo per finalità di compliance :)

## Struttura del progetto

```
gdpr-fines-pet/
├── scraper/
│   ├── scrape.py              # Scraper Python principale
│   ├── validate.py            # Validazione e confronto dati
│   └── requirements.txt       # Dipendenze Python
├── data/
│   └── gdpr_fines.json        # Dati estratti (generato dallo scraper)
├── plugin/
│   ├── gdpr-fines-pet.php         # Plugin WordPress principale
│   ├── includes/
│   │   └── class-gdpr-fines-table.php  # Classe rendering tabella
│   └── assets/
│       ├── style.css           # Stili (scoped, no conflitti)
│       └── app.js              # Frontend vanilla JS
├── .github/
│   └── workflows/
│       └── update-fines.yml    # Automazione GitHub Actions
├── logs/
│   └── .gitkeep
├── LICENSE                     # CC BY-NC-SA 4.0 (per i dati)
└── README.md
```

## Installazione

### 1. Requisiti

- Python 3.11+
- WordPress 5.0+ (per il plugin)
- Un repository GitHub (per l'automazione)

### 2. Setup dello scraper

```bash
# Clona il repository
git clone https://github.com/TUO-UTENTE/gdpr-fines-pet.git
cd gdpr-fines-pet

# Installa le dipendenze Python
pip install -r scraper/requirements.txt

# Esegui lo scraper manualmente
python scraper/scrape.py

# Valida il JSON generato
python scraper/validate.py
```

### 3. Installazione del plugin WordPress

1. Copia l'intera cartella `plugin/` dentro `wp-content/plugins/` del tuo WordPress, rinominandola `gdpr-fines-pet`:
   ```
   wp-content/plugins/gdpr-fines-pet/
   ├── gdpr-fines-pet.php
   ├── includes/
   │   └── class-gdpr-fines-table.php
   └── assets/
       ├── style.css
       └── app.js
   ```
2. Accedi al pannello di amministrazione WordPress
3. Vai su **Plugin > Plugin installati** e attiva **GDPR Fines PET**
4. Vai su **Impostazioni > GDPR Fines PET** e inserisci l'URL raw del JSON:
   ```
   https://raw.githubusercontent.com/TUO-UTENTE/gdpr-fines-pet/main/data/gdpr_fines.json
   ```
5. Crea o modifica una pagina e inserisci lo shortcode:
   ```
   [gdpr_fines_table]
   ```

### 4. Configurazione GitHub Actions

Per l'aggiornamento automatico ogni 6 ore:

1. Vai nelle **Settings** del tuo repository GitHub
2. Naviga su **Actions > General > Workflow permissions**
3. Seleziona **Read and write permissions**
4. Salva

Il workflow partirà automaticamente ogni 6 ore. Puoi anche triggerarlo manualmente dalla tab **Actions > Update GDPR Fines Data > Run workflow**.

### 5. (Alternativa) Cron job locale

Se non vuoi usare GitHub Actions, puoi impostare un cron job:

```bash
# Esegui ogni 6 ore
0 */6 * * * cd /path/to/gdpr-fines-pet && /usr/bin/python3 scraper/scrape.py >> logs/scraper.log 2>&1
```

## Servire la pagina in locale (solo per sviluppo/test)

Se vuoi testare senza WordPress, puoi servire i file localmente dopo aver generato il JSON:

```bash
# Genera i dati
python scraper/scrape.py

# Avvia un server HTTP locale nella cartella plugin
cd plugin
python -m http.server 8000
```

Nota: in modalità standalone il JSON non viene caricato da `wp_localize_script`, quindi la tabella mostrerà un errore. Per test standalone, è necessario un adattamento del JS.

## Embed in WordPress (via iframe) – Sconsigliato

Se per qualche motivo preferisci un iframe anziché il plugin:

```html
<iframe
  src="https://TUO-UTENTE.github.io/gdpr-fines-pet/plugin/"
  width="100%"
  height="800"
  style="border: none; max-width: 100%;"
  title="Tabella sanzioni GDPR"
  loading="lazy">
</iframe>
```

**Nota**: raccomandiamo l'uso del plugin WordPress per una migliore integrazione.

## Licenza e attribuzione

### Dati

I dati sulle sanzioni GDPR sono forniti da [enforcementtracker.com](https://www.enforcementtracker.com/) (CMS Hasche Sigle, cms.law) e rilasciati sotto licenza **[Creative Commons BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)**.

> **enforcementtracker.com, provided by CMS**

Qualsiasi riutilizzo dei dati deve:
- Essere **non commerciale**
- **Attribuire** esplicitamente la fonte
- Applicare la **stessa licenza CC BY-NC-SA 4.0**

### Codice sorgente

Il codice sorgente (scraper, plugin, JavaScript) è rilasciato sotto licenza **GPL-2.0+**.

## Note tecniche

- Lo scraper fa **una sola richiesta HTTP** alla homepage del tracker, che contiene tutti i dati in un blob JSON embeddato (`<script id="et-cases">`)
- Il rate limiting è impostato a **1 richiesta ogni 3 secondi** (anche se ne serve solo una)
- Lo scraping avviene al massimo **ogni 6 ore** (il sito sorgente si aggiorna ogni ora)
- I nomi dei soggetti sanzionati sono **hashed (SHA-256 troncato)** nel JSON e mostrati come **barre nere cliccabili** nella tabella
- Il plugin WordPress usa **transient API** per cacheare il JSON e non sovraccaricare GitHub

## Limitazioni note

- Se il sito attiva protezioni Cloudflare aggressive (challenge, CAPTCHA), lo scraper fallirà con errore 403. In questo caso, controlla i log e considera un import manuale dei dati.
- La struttura HTML del sito potrebbe cambiare. Lo scraper ha fallback multipli per trovare il blob JSON, ma in caso di modifica radicale del markup, sarà necessario un aggiornamento.
- Il campo `fine_eur` può essere `null` per sanzioni con importo non specificato.
- Il campo `date` può contenere solo l'anno quando la data esatta non è pubblica.
