<?php
/**
 * Konfiguration – Ausgabenformular Buana e.V.
 *
 * SMTP-Zugangsdaten findest du im df.eu Kundencenter unter:
 * Hosting → E-Mail → Postfach → SMTP-Einstellungen
 */
return [
    // Empfänger-Adresse für den E-Mail-Versand
    'email_to'       => 'buchhaltung@buana-ev.de',
    'email_subject'  => 'Ausgabenbestätigung',

    // SMTP-Konfiguration – df.eu Postfach-Zugangsdaten eintragen
    'smtp_host'      => 'smtp.df.eu',        // df.eu Standard-SMTP
    'smtp_port'      => 587,                  // STARTTLS
    'smtp_user'      => 'noreply@kinderladen-buana.de', // das sendende Postfach
    'smtp_pass'      => '',                   // ← Postfach-Passwort eintragen
    'smtp_from'      => 'noreply@kinderladen-buana.de',
    'smtp_from_name' => 'Buana e.V. – Ausgabenformular',

    // Maximale Dateigröße für Belege (in Megabyte)
    'max_upload_mb'  => 10,
];
