<?php
declare(strict_types=1);
ob_start(); // buffer any accidental output so redirects always work
error_reporting(E_ALL);
ini_set('display_errors', '1');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    header('Location: ' . $base . '/index.php');
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use setasign\Fpdi\Fpdi;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

$config = require __DIR__ . '/config.php';

// ── Helpers ───────────────────────────────────────────────────────────────────

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function formatEuro(float $amount): string
{
    return number_format($amount, 2, ',', '.') . ' EUR';
}

// FPDF uses ISO-8859-1; convert UTF-8 strings before passing them
function iso(string $s): string
{
    return mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
}

function resizeImage(string $path, string $mime, int $maxPx = 1800): string
{
    list($w, $h) = getimagesize($path);
    if ($w <= $maxPx && $h <= $maxPx) {
        return $path;
    }
    $ratio = min($maxPx / $w, $maxPx / $h);
    $nw = (int)($w * $ratio);
    $nh = (int)($h * $ratio);
    $src = ($mime === 'image/jpeg') ? imagecreatefromjpeg($path) : imagecreatefrompng($path);
    $dst = imagecreatetruecolor($nw, $nh);
    if ($mime === 'image/png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefill($dst, 0, 0, $transparent);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $resized = $path . '_resized' . ($mime === 'image/jpeg' ? '.jpg' : '.png');
    $mime === 'image/jpeg' ? imagejpeg($dst, $resized, 85) : imagepng($dst, $resized, 6);
    imagedestroy($src);
    imagedestroy($dst);
    return $resized;
}

// ── Sanitise input ────────────────────────────────────────────────────────────

$action       = in_array($_POST['action'] ?? '', ['download', 'email']) ? $_POST['action'] : 'download';
$name         = trim(strip_tags($_POST['name'] ?? ''));
$einrichtung  = 'Buana e.V.'; // immer fest
$zahlungsart  = $_POST['zahlungsart'] ?? '';
$kontoinhaber = trim(strip_tags($_POST['kontoinhaber'] ?? ''));
$iban         = preg_replace('/\s+/', '', strip_tags($_POST['iban'] ?? ''));
$bank         = trim(strip_tags($_POST['bank'] ?? ''));
$datum        = $_POST['datum'] ?? date('Y-m-d');

$ts             = strtotime($datum) ?: time();
$datumFormatted = date('d.m.Y', $ts);

$beschreibungen = $_POST['ausgaben_beschreibung'] ?? [];
$preise         = $_POST['ausgaben_preis'] ?? [];
$ausgaben       = [];
$gesamt         = 0.0;

foreach ($beschreibungen as $i => $desc) {
    $desc  = trim(strip_tags($desc));
    $preis = (float)($preise[$i] ?? 0);
    if ($desc !== '') {
        $ausgaben[] = ['beschreibung' => $desc, 'preis' => $preis];
        $gesamt    += $preis;
    }
}

$signatureData = $_POST['signature'] ?? '';

// ── Temp directory ────────────────────────────────────────────────────────────

$tmpDir = sys_get_temp_dir() . '/ausgaben_' . bin2hex(random_bytes(8));
mkdir($tmpDir, 0700, true);

// ── Handle file uploads ───────────────────────────────────────────────────────

$imageFiles = [];
$pdfFiles   = [];
$maxBytes   = ($config['max_upload_mb'] ?? 10) * 1024 * 1024;

if (!empty($_FILES['belege']['name'][0])) {
    foreach ($_FILES['belege']['error'] as $i => $errCode) {
        if ($errCode !== UPLOAD_ERR_OK) {
            continue;
        }
        if ($_FILES['belege']['size'][$i] > $maxBytes) {
            continue;
        }

        $tmpName = $_FILES['belege']['tmp_name'][$i];
        $mime    = mime_content_type($tmpName);

        $allowed = ['image/jpeg', 'image/png', 'application/pdf'];
        if (!in_array($mime, $allowed, true)) {
            continue;
        }
        if ($mime !== 'application/pdf' && !getimagesize($tmpName)) {
            continue;
        }

        $ext  = ($mime === 'application/pdf') ? '.pdf' : (($mime === 'image/jpeg') ? '.jpg' : '.png');
        $dest = $tmpDir . '/beleg_' . $i . $ext;
        move_uploaded_file($tmpName, $dest);

        if ($mime === 'application/pdf') {
            $pdfFiles[] = $dest;
        } else {
            $imageFiles[] = ['path' => $dest, 'mime' => $mime];
        }
    }
}

// ── Signature ─────────────────────────────────────────────────────────────────

$signaturePath = null;
if ($signatureData && strpos($signatureData, 'data:image/png;base64,') === 0) {
    $raw           = base64_decode(substr($signatureData, strlen('data:image/png;base64,')));
    $signaturePath = $tmpDir . '/signature.png';
    file_put_contents($signaturePath, $raw);
}

// ── PDF class ─────────────────────────────────────────────────────────────────

class AusgabenPDF extends Fpdi
{
    const C_GREEN       = [44, 95, 46];
    const C_GREEN_LIGHT = [240, 247, 240];
    const C_GREY        = [100, 100, 100];
    const C_LIGHT_GREY  = [180, 180, 180];
    const C_BLACK       = [26, 26, 26];
    const C_WHITE       = [255, 255, 255];
    const C_ROW_ALT     = [248, 252, 248];
    const C_TOTAL_BG    = [234, 247, 234];

    private function applyFill(array $c): void { $this->SetFillColor($c[0], $c[1], $c[2]); }
    private function applyText(array $c): void { $this->SetTextColor($c[0], $c[1], $c[2]); }
    private function applyDraw(array $c): void { $this->SetDrawColor($c[0], $c[1], $c[2]); }

    // ── Formular-Titel (unterhalb des Briefkopfs) ──────────────────────────
    public function drawFormTitle(): void
    {
        // Separator oben
        $this->applyDraw(self::C_GREEN);
        $this->SetLineWidth(0.4);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(2);

        $this->SetFont('Helvetica', 'B', 11);
        $this->applyText(self::C_GREEN);
        $this->SetX(15);
        $this->Cell(180, 7, iso('Formular zur Bestätigung von Ausgaben'), 0, 1, 'C');

        // Separator unten
        $y = $this->GetY();
        $this->Line(15, $y, 195, $y);
        $this->SetY($y + 5);
    }

    // ── Section title ──────────────────────────────────────────────────────
    public function section(string $title): void
    {
        $this->SetFont('Helvetica', 'B', 9);
        $this->applyText(self::C_GREEN);
        $this->SetX(15);
        $this->Cell(180, 6, iso($title), 0, 1, 'L');

        $y = $this->GetY();
        $this->applyDraw(self::C_GREEN);
        $this->SetLineWidth(0.3);
        $this->Line(15, $y, 195, $y);
        $this->SetY($y + 3);
    }

    // ── Name (Einrichtung ist immer Buana e.V.) ────────────────────────────
    public function personFields(string $name): void
    {
        $y = $this->GetY();
        $this->SetFont('Helvetica', 'B', 7.5);
        $this->applyText(self::C_GREY);
        $this->SetXY(15, $y);
        $this->Cell(180, 4, 'NAME', 0, 1, 'L');

        $y2 = $this->GetY();
        $this->SetFont('Helvetica', '', 10);
        $this->applyText(self::C_BLACK);
        $this->applyDraw(self::C_LIGHT_GREY);
        $this->SetLineWidth(0.3);
        $this->SetXY(15, $y2);
        $this->Cell(180, 8, iso($name), 'B', 1, 'L');

        $this->SetY($this->GetY() + 4);
    }

    // ── Ausgaben table ─────────────────────────────────────────────────────
    public function ausgabenTable(array $ausgaben, float $gesamt): void
    {
        // Header row
        $this->applyFill(self::C_GREEN);
        $this->applyText(self::C_WHITE);
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->applyDraw(self::C_GREEN);
        $this->SetLineWidth(0.1);
        $this->SetX(15);
        $this->Cell(12, 7, 'Nr.', 0, 0, 'C', true);
        $this->Cell(128, 7, 'Beschreibung', 0, 0, 'L', true);
        $this->Cell(40, 7, 'Preis', 0, 1, 'R', true);

        // Data rows
        $this->SetFont('Helvetica', '', 9);
        $this->applyText(self::C_BLACK);
        $this->applyDraw([220, 220, 220]);
        $this->SetLineWidth(0.2);

        foreach ($ausgaben as $i => $a) {
            $alt = ($i % 2 === 0);
            if ($alt) {
                $this->applyFill(self::C_ROW_ALT);
            }
            $this->SetX(15);
            $this->Cell(12, 7, ($i + 1) . '.)', 0, 0, 'C', $alt);
            $this->Cell(128, 7, iso($a['beschreibung']), 0, 0, 'L', $alt);
            $this->Cell(40, 7, iso(formatEuro($a['preis'])), 0, 1, 'R', $alt);
        }

        // Total row
        $this->SetLineWidth(0.5);
        $this->applyDraw(self::C_GREEN);
        $y = $this->GetY();
        $this->Line(15, $y, 195, $y);
        $this->applyFill(self::C_TOTAL_BG);
        $this->applyText(self::C_GREEN);
        $this->SetFont('Helvetica', 'B', 9.5);
        $this->SetX(15);
        $this->Cell(140, 8, iso('Gesamt'), 0, 0, 'R', true);
        $this->Cell(40, 8, iso(formatEuro($gesamt)), 0, 1, 'R', true);

        $this->SetY($this->GetY() + 4);
    }

    // ── Zahlungsart ────────────────────────────────────────────────────────
    public function zahlungsart(string $selected): void
    {
        $options = [
            'handkasse'                     => 'Der Rechnungsbetrag wird über die Handkasse erstattet',
            'ueberweisung_rechnungssteller'  => 'Der Rechnungsbetrag muss noch an den Rechnungssteller überwiesen werden',
            'rueckueberweisung'             => 'Ich habe den Rechnungsbetrag privat ausgelegt und bitte um Rücküberweisung',
        ];

        $this->SetFont('Helvetica', '', 9.5);
        $this->applyText(self::C_BLACK);

        foreach ($options as $val => $label) {
            $checked = ($val === $selected);
            $y = $this->GetY();

            // Checkbox border
            $this->applyDraw(self::C_LIGHT_GREY);
            $this->SetLineWidth(0.3);
            $this->applyFill($checked ? self::C_GREEN_LIGHT : self::C_WHITE);
            $this->Rect(15, $y + 1.2, 4.5, 4.5, 'DF');

            if ($checked) {
                // Checkmark
                $this->applyDraw(self::C_GREEN);
                $this->SetLineWidth(0.8);
                $this->Line(15.5, $y + 3.5, 16.8, $y + 5.2);
                $this->Line(16.8, $y + 5.2, 19.1, $y + 2.2);
                $this->SetLineWidth(0.3);
                $this->applyDraw(self::C_LIGHT_GREY);
            }

            // Label
            $this->applyText(self::C_BLACK);
            $this->SetXY(22, $y);
            $this->MultiCell(168, 6.5, iso($label), 0, 'L');
            $this->SetY($this->GetY() + 1);
        }

        $this->SetY($this->GetY() + 3);
    }

    // ── Konto fields ───────────────────────────────────────────────────────
    public function kontoFelder(string $inhaber, string $iban, string $bank): void
    {
        $fields = [
            ['KONTOINHABER', $inhaber],
            ['IBAN', $iban],
            ['BANK', $bank],
        ];

        $this->applyDraw(self::C_LIGHT_GREY);
        $this->SetLineWidth(0.3);

        foreach ($fields as $pair) {
            list($label, $value) = $pair;
            $y = $this->GetY();
            $this->SetFont('Helvetica', 'B', 7.5);
            $this->applyText(self::C_GREY);
            $this->SetXY(15, $y);
            $this->Cell(180, 4, $label, 0, 1, 'L');

            $y2 = $this->GetY();
            $this->SetFont('Helvetica', '', 10);
            $this->applyText(self::C_BLACK);
            $this->SetXY(15, $y2);
            $this->Cell(180, 8, iso($value), 'B', 1, 'L');
            $this->SetY($this->GetY() + 2);
        }

        $this->SetY($this->GetY() + 3);
    }

    // ── Confirmation + signature row ───────────────────────────────────────
    public function footerSection(string $datum, ?string $sigPath): void
    {
        $this->SetFont('Helvetica', 'I', 8);
        $this->applyText(self::C_GREY);
        $this->SetX(15);
        $this->Cell(180, 6, iso('Hiermit bestätige ich die Richtigkeit der obigen Ausführungen.'), 0, 1, 'L');

        $this->SetY($this->GetY() + 4);
        $y = $this->GetY();

        // Labels
        $this->SetFont('Helvetica', 'B', 7.5);
        $this->applyText(self::C_GREY);
        $this->SetXY(15, $y);
        $this->Cell(85, 4, 'DATUM', 0, 0, 'L');
        $this->SetX(110);
        $this->Cell(80, 4, 'UNTERSCHRIFT', 0, 1, 'L');

        $y2 = $this->GetY();
        $this->SetFont('Helvetica', '', 10);
        $this->applyText(self::C_BLACK);
        $this->applyDraw(self::C_LIGHT_GREY);
        $this->SetLineWidth(0.4);

        // Date
        $this->SetXY(15, $y2);
        $this->Cell(85, 16, iso($datum), 'B', 0, 'L');

        // Signature box
        $this->Rect(110, $y2, 80, 16, 'D');
        if ($sigPath && file_exists($sigPath)) {
            $this->Image($sigPath, 112, $y2 + 1, 76, 14, 'PNG');
        }
    }

    // ── Receipt image as a new page ────────────────────────────────────────
    public function addImagePage(string $path, string $mime): void
    {
        $this->AddPage();
        $this->SetFont('Helvetica', '', 8);
        $this->applyText(self::C_GREY);
        $this->SetXY(15, 10);
        $this->Cell(180, 5, 'Beleg', 0, 1, 'C');

        list($imgW, $imgH) = getimagesize($path);
        $maxW  = 180.0;
        $maxH  = 255.0;
        $ratio = min($maxW / $imgW, $maxH / $imgH);
        $w     = $imgW * $ratio;
        $h     = $imgH * $ratio;
        $x     = 15 + ($maxW - $w) / 2;

        $type = ($mime === 'image/jpeg') ? 'JPEG' : 'PNG';
        $this->Image($path, $x, 18, $w, $h, $type);
    }
}

// ── Build the PDF ─────────────────────────────────────────────────────────────

// ── Schritt 1: Formular-PDF komplett aufbauen (inkl. aller Belege) ───────────
// Briefbogen wird erst in Schritt 2 als Overlay draufgelegt, damit FPDI-Reader
// für Rechnungs-PDFs nicht kollidieren.

$pdf = new AusgabenPDF();
$pdf->SetCreator('Buana e.V. Ausgabenformular');
$pdf->SetAuthor(iso($name));
$pdf->SetTitle(iso('Ausgabenbestaetigung ' . $datumFormatted));
// Oberer Rand 51 mm = freier Platz für den Briefkopf-Overlay
$pdf->SetMargins(15, 51, 15);
$pdf->SetAutoPageBreak(true, 22);
$pdf->AddPage('P', [210, 297]);

$pdf->SetY(51);
$pdf->drawFormTitle();

$pdf->section('Angaben zur Person');
$pdf->personFields($name);

$pdf->section('Getätigte Ausgaben');
$pdf->ausgabenTable($ausgaben, $gesamt);

$pdf->section('Zahlungsart');
$pdf->zahlungsart($zahlungsart);

if ($zahlungsart === 'rueckueberweisung') {
    $pdf->section('Kontoverbindung zur Erstattung');
    $pdf->kontoFelder($kontoinhaber, $iban, $bank);
}

$pdf->footerSection($datumFormatted, $signaturePath);

// Bild-Belege als zusätzliche Seiten
$pdf->SetTopMargin(15);
foreach ($imageFiles as $img) {
    $imgPath = resizeImage($img['path'], $img['mime']);
    $pdf->addImagePage($imgPath, $img['mime']);
}

// ── PDF-Belege anhängen ───────────────────────────────────────────────────────
// Strategie: FPDI zuerst (vektortreu, aber nur für PDF ≤ 1.4 ohne Xref-Streams).
// Falls FPDI scheitert (modernes PDF 1.5+ mit komprimierten Xref-Tabellen),
// Imagick als Fallback: Seiten zu JPEG rastern und als Bildseiten einbetten.

foreach ($pdfFiles as $receiptPath) {
    $handled = false;

    // ── Methode 1: FPDI ───────────────────────────────────────────────────────
    if (!$handled) {
        try {
            $pageCount = $pdf->setSourceFile($receiptPath);
            for ($p = 1; $p <= $pageCount; $p++) {
                $tpl  = $pdf->importPage($p);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
            }
            $handled = true;
        } catch (Exception $e) {
            // FPDI kann dieses PDF nicht lesen (vermutlich PDF 1.5+) → Imagick
        }
    }

    // ── Methode 2: Imagick (PDF → JPEG) ──────────────────────────────────────
    if (!$handled && class_exists('Imagick')) {
        try {
            $im = new Imagick();
            $im->setResolution(150, 150);
            $im->readImage($receiptPath);
            $numPages = $im->getNumberImages();

            for ($pi = 0; $pi < $numPages; $pi++) {
                $im->setIteratorIndex($pi);
                $page = $im->getImage();

                // Transparenz auf weißen Hintergrund setzen
                $bg = new Imagick();
                $bg->newImage($page->getImageWidth(), $page->getImageHeight(),
                              new ImagickPixel('white'));
                $bg->setImageColorspace(Imagick::COLORSPACE_SRGB);
                $bg->compositeImage($page, Imagick::COMPOSITE_OVER, 0, 0);
                $bg->setImageFormat('jpeg');
                $bg->setImageCompression(Imagick::COMPRESSION_JPEG);
                $bg->setImageCompressionQuality(85);

                $imgPath = $tmpDir . '/pdfimg_' . $pi . '_' . uniqid() . '.jpg';
                $bg->writeImage($imgPath);
                $bg->destroy();
                $page->destroy();

                $pdf->addImagePage($imgPath, 'image/jpeg');
            }
            $im->destroy();
            $handled = true;
        } catch (Exception $e) {
            // Imagick auch gescheitert (kein Ghostscript o.ä.)
        }
    }

    // ── Fallback: Platzhalterseite ────────────────────────────────────────────
    if (!$handled) {
        $pdf->AddPage('P', [210, 297]);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->SetXY(15, 130);
        $pdf->Cell(180, 8, iso('Beleg (PDF) konnte nicht eingebettet werden.'), 0, 1, 'C');
    }
}

$rawPdf = $pdf->Output('S');

// ── Schritt 2: Briefbogen als Hintergrund auf Seite 1 legen ──────────────────
// Separater FPDI-Durchlauf – kein Konflikt mit den Beleg-Readern.

$briefbogenPath = __DIR__ . '/assets/briefbogen.pdf';
$finalPdf       = $rawPdf; // Fallback ohne Briefbogen

if (file_exists($briefbogenPath)) {
    $rawPath = $tmpDir . '/raw.pdf';
    file_put_contents($rawPath, $rawPdf);

    try {
        $merger = new Fpdi();
        $merger->SetMargins(0, 0, 0);
        $merger->SetAutoPageBreak(false);

        // Briefbogen-Template importieren
        $merger->setSourceFile($briefbogenPath);
        $briefbogenTpl = $merger->importPage(1);

        // Alle Seiten des Roh-PDFs importieren
        $totalPages = $merger->setSourceFile($rawPath);
        $rawTpls    = [];
        for ($p = 1; $p <= $totalPages; $p++) {
            $rawTpls[$p] = $merger->importPage($p);
        }

        // Seiten zusammenführen
        for ($p = 1; $p <= $totalPages; $p++) {
            $size = $merger->getTemplateSize($rawTpls[$p]);
            $merger->AddPage($size['orientation'], [$size['width'], $size['height']]);

            // Nur auf Seite 1 den Briefbogen als Hintergrund
            if ($p === 1) {
                $merger->useTemplate($briefbogenTpl, 0, 0, 210, 297);
            }

            // Formular-/Beleginhalt darüberlegen
            $merger->useTemplate($rawTpls[$p], 0, 0, 210, 297);
        }

        $finalPdf = $merger->Output('S');
    } catch (Exception $e) {
        // Fehler beim Overlay → trotzdem das Roh-PDF ausliefern
        $finalPdf = $rawPdf;
    }
}

// ── Filename ──────────────────────────────────────────────────────────────────

$safeDate = date('Y-m-d', $ts);
$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
$filename  = 'Ausgaben_' . $safeName . '_' . $safeDate . '.pdf';

// ── Deliver ───────────────────────────────────────────────────────────────────

if ($action === 'email') {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_user'];
        $mail->Password   = $config['smtp_pass'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)$config['smtp_port'];
        $mail->Timeout    = 10; // Sekunden – verhindert endloses Hängen

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($config['smtp_from'], $config['smtp_from_name']);
        $mail->addAddress($config['email_to']);

        $mail->Subject = $config['email_subject'] . ' - ' . $name . ' - ' . $datumFormatted;
        $mail->isHTML(false);
        $mail->Body = sprintf(
            "Ausgabenbestaetigung von %s (%s) vom %s.\nGesamt: %s",
            $name,
            $einrichtung,
            $datumFormatted,
            formatEuro($gesamt)
        );
        $mail->addStringAttachment($finalPdf, $filename, PHPMailer::ENCODING_BASE64, 'application/pdf');

        $mail->send();
        cleanup($tmpDir);
        header('Location: index.php?success=1');
        exit;
    } catch (MailException $e) {
        cleanup($tmpDir);
        header('Location: index.php?error=' . urlencode($mail->ErrorInfo));
        exit;
    }
}

// Download – set cookie so JS knows the download has started
$token = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['download_token'] ?? '');
if ($token) {
    setcookie('download_ready_' . $token, '1', time() + 60, '/');
}
cleanup($tmpDir);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . strlen($finalPdf));
header('Cache-Control: private, no-cache');
echo $finalPdf;
exit;

// ── Cleanup ───────────────────────────────────────────────────────────────────

function cleanup(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($dir);
}
