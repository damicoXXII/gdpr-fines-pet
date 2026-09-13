/**
 * Multe GDPR – Frontend Application
 *
 * Legge i dati passati da WordPress (via wp_localize_script) e popola
 * la tabella con filtri, ordinamento e paginazione.
 *
 * Nessuna dipendenza esterna: vanilla JS puro.
 */
(function () {
    "use strict";

    /* ==================================================================
       Configurazione
       ================================================================== */
    var ROWS_PER_PAGE = 25;
    var REDACTED_CHAR = "\u2588"; // Full block character
    var REDACTED_TEXT = REDACTED_CHAR.repeat(10);

    /* ==================================================================
       Stato globale
       ================================================================== */
    var allFines = [];
    var filteredFines = [];
    var currentPage = 1;
    var sortField = "date";
    var sortDir = "desc";

    /* ==================================================================
       DOM refs (populated on init)
       ================================================================== */
    var dom = {};

    /* ==================================================================
       Inizializzazione
       ================================================================== */
    function init() {
        dom.root       = document.getElementById("multe-gdpr-root");
        dom.total      = document.getElementById("multe-gdpr-total");
        dom.updated    = document.getElementById("multe-gdpr-updated");
        dom.search     = document.getElementById("multe-gdpr-search");
        dom.country    = document.getElementById("multe-gdpr-country");
        dom.fineMin    = document.getElementById("multe-gdpr-fine-min");
        dom.fineMax    = document.getElementById("multe-gdpr-fine-max");
        dom.resetBtn   = document.getElementById("multe-gdpr-reset");
        dom.tbody      = document.getElementById("multe-gdpr-tbody");
        dom.pagination = document.getElementById("multe-gdpr-pagination");
        dom.table      = document.getElementById("multe-gdpr-table");

        if (!dom.root) return;

        // Dati iniettati da WordPress
        if (typeof multeGdprData === "undefined") {
            showError("Dati non disponibili. Verificare la configurazione del plugin.");
            return;
        }

        if (multeGdprData.error) {
            showError(
                "Impossibile caricare i dati. Verificare l'URL del JSON " +
                "nelle impostazioni del plugin (Impostazioni > Multe GDPR)."
            );
            return;
        }

        allFines = multeGdprData.fines || [];
        var metadata = multeGdprData.metadata || {};

        // Popola header
        dom.total.textContent = formatNumber(metadata.total_records || allFines.length);
        dom.updated.textContent = formatDate(metadata.last_updated || "");

        // Popola dropdown paesi
        populateCountryDropdown();

        // Applica filtri iniziali e renderizza
        applyFilters();

        // Collega eventi
        bindEvents();
    }

    /* ==================================================================
       Popola il dropdown dei paesi
       ================================================================== */
    function populateCountryDropdown() {
        var countries = {};
        for (var i = 0; i < allFines.length; i++) {
            var c = allFines[i].country;
            if (c) countries[c] = true;
        }
        var sorted = Object.keys(countries).sort();
        for (var j = 0; j < sorted.length; j++) {
            var opt = document.createElement("option");
            opt.value = sorted[j];
            opt.textContent = sorted[j];
            dom.country.appendChild(opt);
        }
    }

    /* ==================================================================
       Binding eventi
       ================================================================== */
    function bindEvents() {
        var debounced = debounce(function () {
            currentPage = 1;
            applyFilters();
        }, 300);

        dom.search.addEventListener("input", debounced);
        dom.country.addEventListener("change", function () {
            currentPage = 1;
            applyFilters();
        });
        dom.fineMin.addEventListener("input", debounced);
        dom.fineMax.addEventListener("input", debounced);

        dom.resetBtn.addEventListener("click", function () {
            dom.search.value = "";
            dom.country.value = "";
            dom.fineMin.value = "";
            dom.fineMax.value = "";
            currentPage = 1;
            applyFilters();
        });

        // Ordinamento colonne
        var ths = dom.table.querySelectorAll("thead th[data-sort]");
        for (var i = 0; i < ths.length; i++) {
            ths[i].addEventListener("click", handleSort);
        }
    }

    /* ==================================================================
       Ordinamento
       ================================================================== */
    function handleSort(e) {
        var field = e.currentTarget.getAttribute("data-sort");
        if (sortField === field) {
            sortDir = sortDir === "asc" ? "desc" : "asc";
        } else {
            sortField = field;
            sortDir = field === "fine" ? "desc" : "asc";
        }
        currentPage = 1;
        applyFilters();
        updateSortIndicators();
    }

    function updateSortIndicators() {
        var ths = dom.table.querySelectorAll("thead th[data-sort]");
        for (var i = 0; i < ths.length; i++) {
            ths[i].classList.remove("multe-gdpr--sort-asc", "multe-gdpr--sort-desc");
            if (ths[i].getAttribute("data-sort") === sortField) {
                ths[i].classList.add(
                    sortDir === "asc" ? "multe-gdpr--sort-asc" : "multe-gdpr--sort-desc"
                );
            }
        }
    }

    function sortFines(arr) {
        var dir = sortDir === "asc" ? 1 : -1;
        return arr.slice().sort(function (a, b) {
            var va, vb;
            switch (sortField) {
                case "date":
                    va = a.date || "0000";
                    vb = b.date || "0000";
                    return va < vb ? -dir : va > vb ? dir : 0;
                case "country":
                    va = (a.country || "").toLowerCase();
                    vb = (b.country || "").toLowerCase();
                    return va < vb ? -dir : va > vb ? dir : 0;
                case "authority":
                    va = (a.authority || "").toLowerCase();
                    vb = (b.authority || "").toLowerCase();
                    return va < vb ? -dir : va > vb ? dir : 0;
                case "fine":
                    va = a.fine_eur || 0;
                    vb = b.fine_eur || 0;
                    return (va - vb) * dir;
                case "sector":
                    va = (a.sector || "").toLowerCase();
                    vb = (b.sector || "").toLowerCase();
                    return va < vb ? -dir : va > vb ? dir : 0;
                case "articles":
                    va = (a.gdpr_articles || "").toLowerCase();
                    vb = (b.gdpr_articles || "").toLowerCase();
                    return va < vb ? -dir : va > vb ? dir : 0;
                case "entity":
                    // Redacted, sort by etid as fallback
                    return (a.etid - b.etid) * dir;
                default:
                    return 0;
            }
        });
    }

    /* ==================================================================
       Filtri
       ================================================================== */
    function applyFilters() {
        var searchText = (dom.search.value || "").toLowerCase().trim();
        var countryFilter = dom.country.value;
        var fineMin = parseFloat(dom.fineMin.value) || 0;
        var fineMax = parseFloat(dom.fineMax.value) || Infinity;

        filteredFines = [];

        for (var i = 0; i < allFines.length; i++) {
            var r = allFines[i];

            // Filtro paese
            if (countryFilter && r.country !== countryFilter) continue;

            // Filtro importo
            var amount = r.fine_eur || 0;
            if (amount < fineMin) continue;
            if (fineMax !== Infinity && amount > fineMax) continue;

            // Filtro testo libero
            if (searchText) {
                var haystack = [
                    r.country || "",
                    r.authority || "",
                    r.sector || "",
                    r.gdpr_articles || "",
                    r.violation_type || "",
                    r.country_code || "",
                ].join(" ").toLowerCase();

                if (haystack.indexOf(searchText) === -1) continue;
            }

            filteredFines.push(r);
        }

        // Ordina
        filteredFines = sortFines(filteredFines);

        // Aggiorna indicatori di ordinamento
        updateSortIndicators();

        // Renderizza
        renderTable();
        renderPagination();
    }

    /* ==================================================================
       Rendering tabella
       ================================================================== */
    function renderTable() {
        var start = (currentPage - 1) * ROWS_PER_PAGE;
        var end = start + ROWS_PER_PAGE;
        var page = filteredFines.slice(start, end);

        if (page.length === 0) {
            dom.tbody.innerHTML =
                '<tr><td colspan="7" class="multe-gdpr__empty">' +
                "Nessuna sanzione trovata con i filtri selezionati." +
                "</td></tr>";
            return;
        }

        var html = "";
        for (var i = 0; i < page.length; i++) {
            var r = page[i];
            html += "<tr>";
            html += "<td>" + escHtml(r.date || "N/D") + "</td>";
            html += "<td>" + escHtml(r.country || "N/D") + "</td>";
            html += "<td>" + escHtml(r.authority || "N/D") + "</td>";
            html +=
                '<td><a href="' + escAttr(r.detail_url || "#") + '" ' +
                'target="_blank" rel="noopener noreferrer" ' +
                'class="multe-gdpr__redacted" ' +
                'title="Visualizza dettagli su enforcementtracker.com">' +
                REDACTED_TEXT +
                '<span class="multe-gdpr__sr-only">Visualizza dettagli della sanzione ETid-' +
                escHtml(String(r.etid || "")) + "</span>" +
                "</a></td>";
            html +=
                '<td class="multe-gdpr__fine">' +
                formatCurrency(r.fine_eur) +
                "</td>";
            html += "<td>" + escHtml(r.sector || "N/D") + "</td>";
            html += "<td>" + escHtml(r.gdpr_articles || "N/D") + "</td>";
            html += "</tr>";
        }

        dom.tbody.innerHTML = html;
    }

    /* ==================================================================
       Paginazione
       ================================================================== */
    function renderPagination() {
        var totalPages = Math.ceil(filteredFines.length / ROWS_PER_PAGE);
        if (totalPages <= 1) {
            dom.pagination.innerHTML =
                '<span class="multe-gdpr__page-info">' +
                filteredFines.length + " risultat" +
                (filteredFines.length === 1 ? "o" : "i") + "</span>";
            return;
        }

        var html = "";

        // Prev button
        html +=
            '<button class="multe-gdpr__page-btn' +
            (currentPage === 1 ? " multe-gdpr__page-btn--disabled" : "") +
            '" data-page="' + (currentPage - 1) + '">&laquo;</button>';

        // Page numbers (show max 7 around current)
        var pages = getPageRange(currentPage, totalPages, 7);
        for (var i = 0; i < pages.length; i++) {
            var p = pages[i];
            if (p === "...") {
                html += '<span class="multe-gdpr__page-info">&hellip;</span>';
            } else {
                html +=
                    '<button class="multe-gdpr__page-btn' +
                    (p === currentPage ? " multe-gdpr__page-btn--active" : "") +
                    '" data-page="' + p + '">' + p + "</button>";
            }
        }

        // Next button
        html +=
            '<button class="multe-gdpr__page-btn' +
            (currentPage === totalPages ? " multe-gdpr__page-btn--disabled" : "") +
            '" data-page="' + (currentPage + 1) + '">&raquo;</button>';

        // Info
        var start = (currentPage - 1) * ROWS_PER_PAGE + 1;
        var end = Math.min(currentPage * ROWS_PER_PAGE, filteredFines.length);
        html +=
            '<span class="multe-gdpr__page-info">' +
            start + "-" + end + " di " + formatNumber(filteredFines.length) +
            "</span>";

        dom.pagination.innerHTML = html;

        // Bind page clicks
        var btns = dom.pagination.querySelectorAll(".multe-gdpr__page-btn");
        for (var j = 0; j < btns.length; j++) {
            btns[j].addEventListener("click", function (e) {
                var page = parseInt(e.currentTarget.getAttribute("data-page"), 10);
                var maxPage = Math.ceil(filteredFines.length / ROWS_PER_PAGE);
                if (page >= 1 && page <= maxPage) {
                    currentPage = page;
                    renderTable();
                    renderPagination();
                    // Scroll to table top
                    dom.root.scrollIntoView({ behavior: "smooth", block: "start" });
                }
            });
        }
    }

    function getPageRange(current, total, maxVisible) {
        if (total <= maxVisible) {
            var arr = [];
            for (var i = 1; i <= total; i++) arr.push(i);
            return arr;
        }

        var half = Math.floor(maxVisible / 2);
        var start = Math.max(2, current - half);
        var end = Math.min(total - 1, current + half);

        // Adjust if near boundaries
        if (current - half < 2) end = Math.min(total - 1, maxVisible - 1);
        if (current + half > total - 1) start = Math.max(2, total - maxVisible + 2);

        var result = [1];
        if (start > 2) result.push("...");
        for (var j = start; j <= end; j++) result.push(j);
        if (end < total - 1) result.push("...");
        result.push(total);
        return result;
    }

    /* ==================================================================
       Utilità
       ================================================================== */
    function formatCurrency(amount) {
        if (amount === null || amount === undefined) return "N/D";
        // Format with thousand separators (using dot as separator, comma for decimals)
        return (
            "\u20AC " +
            Number(amount)
                .toFixed(0)
                .replace(/\B(?=(\d{3})+(?!\d))/g, ".")
        );
    }

    function formatNumber(n) {
        if (n === null || n === undefined) return "--";
        return Number(n)
            .toString()
            .replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    }

    function formatDate(isoStr) {
        if (!isoStr) return "--";
        try {
            var d = new Date(isoStr);
            if (isNaN(d.getTime())) return isoStr;
            return d.toLocaleDateString("it-IT", {
                year: "numeric",
                month: "2-digit",
                day: "2-digit",
                hour: "2-digit",
                minute: "2-digit",
            });
        } catch (e) {
            return isoStr;
        }
    }

    function escHtml(str) {
        var div = document.createElement("div");
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escAttr(str) {
        return str
            .replace(/&/g, "&amp;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");
    }

    function showError(msg) {
        var tbody = document.getElementById("multe-gdpr-tbody");
        if (tbody) {
            tbody.innerHTML =
                '<tr><td colspan="7" class="multe-gdpr__error">' +
                escHtml(msg) +
                "</td></tr>";
        }
    }

    function debounce(fn, delay) {
        var timer;
        return function () {
            var ctx = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(ctx, args);
            }, delay);
        };
    }

    /* ==================================================================
       Boot
       ================================================================== */
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
