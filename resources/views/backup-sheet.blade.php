<!DOCTYPE html>
{{-- API-029: printable cold-storage backup sheet — the MV-135 QR backup
     (manifest first, then the chunk frames) rendered with the vendored
     qrcodejs at EC-M. Print to paper/PDF; the app scans it back (MV-137). --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cold-storage backup — {{ $teamName }}</title>
    <script src="{{ asset('js/vendor/qrcode.min.js') }}"></script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #111827; margin: 0; padding: 24px; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-all; }
        .no-print { margin-bottom: 24px; }
        .no-print button { background: #4f46e5; color: #fff; border: 0; border-radius: 8px; padding: 10px 18px; font-size: 15px; cursor: pointer; }
        .sheet { max-width: 900px; margin: 0 auto; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .sub { color: #6b7280; font-size: 13px; margin: 0 0 16px; }
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .summary td { border: 1px solid #e5e7eb; padding: 6px 10px; font-size: 13px; }
        .summary td:first-child { font-weight: 600; width: 190px; background: #f9fafb; }
        .warning { background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 10px 12px; font-size: 13px; margin: 0 0 16px; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
        .cell { text-align: center; page-break-inside: avoid; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px; }
        .cell img, .cell canvas { width: 240px; height: 240px; display: block; margin: 0 auto; }
        .caption { font-size: 12px; color: #374151; margin-top: 6px; font-weight: 600; }
        .crc { font-size: 11px; color: #6b7280; }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">Print this sheet</button>
        <span style="font-size: 13px; color: #6b7280; margin-left: 12px;">
            Print to paper or PDF — the app's "Backup import" scans these codes back.
        </span>
    </div>

    <div class="sheet">
        {{-- Page 1: summary + manifest QR --}}
        <div class="page">
            <h1>Cold-storage backup</h1>
            <p class="sub">Moovit — team dataset archive as scannable QR frames (format MVBAK1)</p>
            <table class="summary">
                <tr><td>Team</td><td>{{ $teamName }}</td></tr>
                <tr><td>Exported at</td><td>{{ $exportedAt }} UTC</td></tr>
                <tr><td>Chunk count</td><td>{{ $chunkCount }}</td></tr>
                <tr><td>Payload bytes</td><td>{{ $totalPayloadBytes }}</td></tr>
                <tr><td>SHA-256 fingerprint</td><td class="mono">{{ $fingerprint }}</td></tr>
            </table>
            <p class="warning">
                Photo bytes are NOT part of this archive (a printed backup cannot carry them) —
                attachment metadata only. Store this sheet safely: anyone who scans it holds a
                full copy of the team dataset.
            </p>
            <div class="grid" style="grid-template-columns: repeat(2, 1fr);">
                <div class="cell">
                    <div id="qr-manifest"></div>
                    <div class="caption">manifest</div>
                    <div class="crc mono">{{ $manifest }}</div>
                </div>
            </div>
        </div>

        {{-- Chunk pages: rendered by JS, 3×4 per printed page --}}
        <div id="chunkPages"></div>
    </div>

    <script>
        /* The manifest QR (EC-M, like the app's backup codes) */
        new QRCode(document.getElementById('qr-manifest'), {
            text: @json($manifest),
            width: 300,
            height: 300,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });

        /* Chunk frames → pages of 12 (3 × 4), each with `chunk i/N` + CRC */
        const chunks = @json($chunks);
        const chunkCount = {{ $chunkCount }};
        const pages = document.getElementById('chunkPages');

        for (let p = 0; p < chunks.length; p += 12) {
            const page = document.createElement('div');
            page.className = 'page';
            const grid = document.createElement('div');
            grid.className = 'grid';
            page.appendChild(grid);
            pages.appendChild(page);

            for (const chunk of chunks.slice(p, p + 12)) {
                const cell = document.createElement('div');
                cell.className = 'cell';
                const target = document.createElement('div');
                cell.appendChild(target);
                const caption = document.createElement('div');
                caption.className = 'caption';
                caption.textContent = 'chunk ' + (chunk.index + 1) + '/' + chunkCount;
                const crc = document.createElement('div');
                crc.className = 'crc mono';
                crc.textContent = 'crc32 ' + chunk.crc;
                cell.appendChild(caption);
                cell.appendChild(crc);
                grid.appendChild(cell);

                new QRCode(target, {
                    text: chunk.frame,
                    width: 240,
                    height: 240,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        }
    </script>
</body>
</html>
