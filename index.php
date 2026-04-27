<?php
$success = isset($_GET['success']);
$error   = isset($_GET['error']) ? htmlspecialchars(urldecode($_GET['error']), ENT_QUOTES, 'UTF-8') : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ausgabenbestätigung – Buana e.V.</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
</head>
<body>

<div class="container">

    <header class="form-header">
        <div class="header-logo">Buana e.V.</div>
        <h1>Formular zur Bestätigung von Ausgaben</h1>
        <p class="info-text">
            Dieses Formular bitte verwenden, wenn auf der Rechnung nicht eine zu Buana&nbsp;e.V.
            gehörende Adresse vermerkt ist, sondern z.B. eine Privatadresse,
            oder wenn es sich um eine Auslage handelt.
        </p>
    </header>

    <?php if ($success): ?>
    <div class="alert alert-success">
        ✓ Die Ausgabenbestätigung wurde erfolgreich per E-Mail versendet.
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error">
        ✗ Fehler beim E-Mail-Versand: <?= $error ?>
    </div>
    <?php endif; ?>

    <form id="ausgaben-form" action="process.php" method="POST" enctype="multipart/form-data" novalidate>

        <!-- ── Persönliche Angaben ─────────────────────────────────────── -->
        <section class="card">
            <h2>Angaben zur Person</h2>
            <div class="field">
                <label for="name">Name <span class="req">*</span></label>
                <input type="text" id="name" name="name" required autocomplete="name"
                       placeholder="Vollständiger Name">
            </div>
        </section>

        <!-- ── Ausgaben ───────────────────────────────────────────────── -->
        <section class="card">
            <h2>Getätigte Ausgaben</h2>
            <p class="hint">Entsprechende Belege bitte beifügen.</p>

            <div class="ausgaben-table-wrap">
                <div class="ausgaben-header">
                    <span class="col-nr">Nr.</span>
                    <span class="col-desc">Beschreibung</span>
                    <span class="col-price">Preis (€)</span>
                    <span class="col-del"></span>
                </div>
                <div id="ausgaben-rows">
                    <div class="ausgaben-row">
                        <span class="row-nr">1.</span>
                        <input type="text" name="ausgaben_beschreibung[]"
                               placeholder="Beschreibung der Ausgabe" required>
                        <input type="number" name="ausgaben_preis[]"
                               placeholder="0.00" step="0.01" min="0" class="price-input" required>
                        <button type="button" class="btn-row-del" onclick="removeRow(this)"
                                aria-label="Zeile entfernen">×</button>
                    </div>
                </div>
            </div>

            <button type="button" class="btn-add-row" onclick="addRow()">
                + Weitere Ausgabe hinzufügen
            </button>

            <div class="gesamt-row">
                <span>Gesamt:</span>
                <strong id="gesamt-display">0,00 €</strong>
            </div>
        </section>

        <!-- ── Zahlungsart ────────────────────────────────────────────── -->
        <section class="card">
            <h2>Zahlungsart <span class="req">*</span></h2>
            <div class="radio-group">
                <label class="radio-label">
                    <input type="radio" name="zahlungsart" value="handkasse" required>
                    <span>Der Rechnungsbetrag wird über die Handkasse erstattet</span>
                </label>
                <label class="radio-label">
                    <input type="radio" name="zahlungsart" value="ueberweisung_rechnungssteller">
                    <span>Der Rechnungsbetrag muss noch an den Rechnungssteller überwiesen werden</span>
                </label>
                <label class="radio-label">
                    <input type="radio" name="zahlungsart" value="rueckueberweisung" checked>
                    <span>Ich habe den Rechnungsbetrag privat ausgelegt und bitte um Rücküberweisung</span>
                </label>
            </div>
        </section>

        <!-- ── Kontoverbindung (nur bei Rücküberweisung) ──────────────── -->
        <section class="card" id="konto-section">
            <h2>Kontoverbindung zur Erstattung</h2>
            <div class="field-row three-cols">
                <div class="field">
                    <label for="kontoinhaber">Kontoinhaber <span class="req">*</span></label>
                    <input type="text" id="kontoinhaber" name="kontoinhaber"
                           placeholder="Name des Kontoinhabers">
                </div>
                <div class="field">
                    <label for="iban">IBAN <span class="req">*</span></label>
                    <input type="text" id="iban" name="iban"
                           placeholder="DE00 0000 0000 0000 0000 00" maxlength="34">
                </div>
                <div class="field">
                    <label for="bank">Bank <span class="req">*</span></label>
                    <input type="text" id="bank" name="bank"
                           placeholder="Name der Bank">
                </div>
            </div>
        </section>

        <!-- ── Datum & Unterschrift ───────────────────────────────────── -->
        <section class="card">
            <h2>Datum & Unterschrift</h2>
            <div class="field-row two-cols sig-row">
                <div class="field">
                    <label for="datum">Datum <span class="req">*</span></label>
                    <input type="date" id="datum" name="datum"
                           value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="field">
                    <label>Unterschrift <span class="req">*</span></label>
                    <div class="signature-wrapper" id="sig-wrapper">
                        <canvas id="signature-canvas"></canvas>
                        <button type="button" class="btn-clear-sig" id="clear-signature"
                                title="Unterschrift löschen">Löschen</button>
                        <span class="sig-placeholder" id="sig-placeholder">
                            Mit Maus oder Finger unterschreiben
                        </span>
                    </div>
                    <input type="hidden" name="signature" id="signature-data">
                    <p class="hint" id="sig-error" style="color:#c0392b;display:none">
                        Bitte Unterschrift hinterlassen.
                    </p>
                </div>
            </div>
        </section>

        <!-- ── Belege ─────────────────────────────────────────────────── -->
        <section class="card">
            <h2>Belege anhängen</h2>
            <p class="hint">
                Fotos oder Dateien von Rechnungen (JPG, PNG, PDF).
                Mehrere Dateien möglich, max. 10&nbsp;MB pro Datei.
            </p>
            <div class="upload-area" id="upload-area" role="button" tabindex="0"
                 aria-label="Dateien auswählen oder hierher ziehen">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="1.5"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <p>Dateien hier ablegen oder <span class="upload-link">klicken zum Auswählen</span></p>
                <p class="hint">JPG, PNG oder PDF</p>
            </div>
            <input type="file" id="file-input" name="belege[]" multiple
                   accept="image/jpeg,image/png,application/pdf" class="hidden">
            <div id="file-previews" class="file-previews"></div>
        </section>

        <!-- ── Aktionen ───────────────────────────────────────────────── -->
        <input type="hidden" name="download_token" id="download-token">
        <div class="form-actions">
            <button type="submit" name="action" value="download" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                PDF herunterladen
            </button>
            <button type="submit" name="action" value="email" class="btn btn-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                    <polyline points="22,6 12,13 2,6"/>
                </svg>
                Per E-Mail senden
            </button>
        </div>

    </form>
</div>

<!-- Loading Overlay -->
<div id="loading-overlay" class="loading-overlay" hidden>
    <div class="loading-box">
        <div class="spinner"></div>
        <p id="loading-text">PDF wird erstellt…</p>
    </div>
</div>

<script src="assets/app.js"></script>
</body>
</html>
