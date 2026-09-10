<?php

namespace App\Support\Backup;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use FPDF;

/**
 * API-031 — renders the API-029 MVBAK1 bundle as a PDF (the sheet of
 * API-029's blade view, server-side): page 1 carries the summary header,
 * the photo-bytes warning and the MANIFEST QR; the chunk frames follow
 * 3×4 per A4 page with `chunk i/N` + crc32 captions — the same layout the
 * app's MV-136 print uses.
 *
 * QRs are drawn as VECTOR rectangles from chillerlan's module matrix
 * (`getMatrix()->matrix(true)` — battle-tested encoder, EC-M + 4-module
 * quiet zone like the HTML sheet's qrcodejs), so no GD/imagick requirement
 * and no raster blur: sharp at any print size. FPDF `Output('S')` embeds a
 * `/CreationDate` — pinned to the snapshot's exported-at below (FPDF would
 * stamp its own real clock), so identical inputs stay byte-identical
 * (pinned by test).
 */
class BackupPdf
{
    /** A4 portrait, mm units, page margin. */
    private const MARGIN = 12;

    /** Chunk QRs per page (3 columns × 4 rows), like the HTML sheet. */
    private const COLS = 3;
    private const ROWS = 4;

    /**
     * The PDF bytes for one cold-storage bundle.
     *
     * @param array $bundle BackupCodec::export() result
     * @param string $teamName
     * @param string $exportedAt pipe-free ISO-8601 (the snapshot's)
     * @return string
     */
    public static function render(array $bundle, string $teamName, string $exportedAt): string
    {
        $pdf = new class('P', 'mm', 'A4') extends FPDF {
            /** The snapshot's exported-at, as a unix timestamp. */
            public ?int $pinnedCreationDate = null;

            /**
             * FPDF's `_enddoc` hardcodes `CreationDate = time()` just before
             * output — re-pin to the snapshot's clock (the only time input
             * the bundle carries), keeping renders byte-deterministic.
             */
            protected function _putinfo()
            {
                if ($this->pinnedCreationDate !== null) {
                    $this->CreationDate = $this->pinnedCreationDate;
                }

                parent::_putinfo();
            }
        };
        $pdf->pinnedCreationDate = strtotime($exportedAt) ?: null;
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(self::MARGIN, self::MARGIN);
        $pdf->SetTitle(self::txt('Cold-storage backup — ' . $teamName));

        self::summaryPage($pdf, $bundle, $teamName, $exportedAt);
        self::chunkPages($pdf, $bundle);

        return $pdf->Output('S');
    }

    /**
     * Page 1: title, summary table, warning, manifest QR + manifest text.
     *
     * @param FPDF $pdf
     * @param array $bundle
     * @param string $teamName
     * @param string $exportedAt
     * @return void
     */
    private static function summaryPage(FPDF $pdf, array $bundle, string $teamName, string $exportedAt): void
    {
        $pdf->AddPage();
        $x = self::MARGIN;
        $w = 210 - 2 * self::MARGIN;

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY($x, 14);
        $pdf->Cell($w, 8, self::txt('Cold-storage backup'), 0, 1);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell($w, 5, self::txt('Moovit — team dataset archive as scannable QR frames (format MVBAK1)'), 0, 1);

        $pdf->Ln(4);
        $pdf->SetFont('helvetica', '', 10);
        foreach ([
            'Team' => $teamName,
            'Exported at' => $exportedAt . ' UTC',
            'Chunk count' => (string) $bundle['chunkCount'],
            'Payload bytes' => (string) $bundle['totalPayloadBytes'],
            'SHA-256 fingerprint' => $bundle['fingerprint'],
        ] as $label => $value) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(45, 5, self::txt($label), 0, 0);
            $pdf->SetFont($label === 'SHA-256 fingerprint' ? 'courier' : 'helvetica', '', $label === 'SHA-256 fingerprint' ? 8 : 9);
            $pdf->Cell(0, 5, self::txt($value), 0, 1);
        }

        $pdf->Ln(3);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->MultiCell($w, 4, self::txt(
            'WARNING: Photo bytes are NOT part of this archive (a printed backup cannot carry them) — '
            . 'attachment metadata only. Store this sheet safely: anyone who scans it holds a full copy '
            . 'of the team dataset.'
        ), 1);

        /* The manifest QR first — the app expects it before the chunks. */
        $pdf->Ln(4);
        $qrSize = 62;
        self::qr($pdf, $bundle['manifest'], $x + ($w - $qrSize) / 2, $pdf->GetY(), $qrSize);
        $pdf->SetY($pdf->GetY() + $qrSize + 3);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell($w, 4, 'manifest', 0, 1, 'C');

        $pdf->SetFont('courier', '', 7);
        $pdf->MultiCell($w, 3.2, $bundle['manifest'], 0, 'C');
    }

    /**
     * Chunk frames, 12 per page (3×4), each with `chunk i/N` + crc32 caption.
     *
     * @param FPDF $pdf
     * @param array $bundle
     * @return void
     */
    private static function chunkPages(FPDF $pdf, array $bundle): void
    {
        $w = 210 - 2 * self::MARGIN;
        $cellW = $w / self::COLS;
        $qrSize = 44;
        $rowH = 68;
        $total = $bundle['chunkCount'];

        foreach ($bundle['frames'] as $index => $frame) {
            $slot = $index % (self::COLS * self::ROWS);
            if ($slot === 0) {
                $pdf->AddPage();
            }

            $col = $slot % self::COLS;
            $row = intdiv($slot, self::COLS);
            $x = self::MARGIN + $col * $cellW + ($cellW - $qrSize) / 2;
            $y = 14 + $row * $rowH;

            self::qr($pdf, $frame, $x, $y, $qrSize);

            $pdf->SetXY(self::MARGIN + $col * $cellW, $y + $qrSize + 2);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell($cellW, 4, self::txt('chunk ' . ($index + 1) . '/' . $total), 0, 2, 'C');
            $pdf->SetFont('courier', '', 7);
            $pdf->Cell($cellW, 3, 'crc32 ' . explode('|', $frame)[5], 0, 2, 'C');
        }
    }

    /**
     * One QR as vector rectangles: dark modules of the EC-M matrix (quiet
     * zone included) drawn at qrSize/moduleCount mm each.
     *
     * @param FPDF $pdf
     * @param string $data
     * @param float $x top-left, mm
     * @param float $y top-left, mm
     * @param float $size side length, mm
     * @return void
     */
    private static function qr(FPDF $pdf, string $data, float $x, float $y, float $size): void
    {
        $matrix = (new QRCode(new QROptions([
            'eccLevel' => QRCode::ECC_M,
            'addQuietzone' => true,
            'quietzoneSize' => 4,
        ])))->getMatrix($data)->matrix(true);

        $scale = $size / count($matrix);
        $pdf->SetFillColor(0, 0, 0);
        foreach ($matrix as $row => $cols) {
            foreach ($cols as $col => $dark) {
                if ($dark) {
                    $pdf->Rect($x + $col * $scale, $y + $row * $scale, $scale, $scale, 'F');
                }
            }
        }
    }

    /**
     * FPDF core fonts are Latin-1 — transliterate UTF-8 captions.
     *
     * @param string $utf8
     * @return string
     */
    private static function txt(string $utf8): string
    {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $utf8);

        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '?', $utf8) : $converted;
    }
}
