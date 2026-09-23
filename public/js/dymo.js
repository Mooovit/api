/*
 * Dymo — label printing service for DYMO LabelWriter printers (API-038).
 *
 * Talks to the DYMO Connect desktop app through the vendored framework 2.x
 * (public/js/vendor/dymo.connect.framework.js, official build from
 * download.dymo.com/dymo/Software/JavaScript/dymo.connect.framework.js),
 * which drives the localhost web service on port 41951. No runtime CDN.
 *
 * Label = item NAME (text, shrink-to-fit) + the item's uuid encoded as
 * Code128 AND QR Code (mirrors the API-028 identity-QR convention: either
 * symbology scanned back resolves via GET /kanban/item/{uuid}).
 *
 * Detection is a cached promise kicked off at page init; every UI hook is
 * CSS-gated on body.dymo-ready so buttons only appear when a LabelWriter
 * is actually present. Printer + label template choices are localStorage
 * prefs — they belong to the workstation (the physically attached printer),
 * not to the team or user.
 */
window.Dymo = (function () {
    'use strict';

    const LS_PRINTER = 'dymo.printer';
    const LS_TEMPLATE = 'dymo.template';
    const DEFAULT_TEMPLATE = '30334';
    const DETECT_TIMEOUT_MS = 5000;

    /*
     * Label templates. Geometry in twips (1440/inch). DrawCommands use the
     * portrait space DYMO's own exported .label files use (Width = short
     * side); object Bounds use the display space (long side horizontal) —
     * same split as DYMO Label's own output. name bounds use ShrinkToFit so
     * long item names shrink instead of truncating.
     */
    const TEMPLATES = {
        /* 3.5" x 2.125" — roomy: name + Code128 + QR (default) */
        '30334': {
            paper: '30334 Large Address',
            draw: { w: 3060, h: 5040 },
            name: { x: 240, y: 180, w: 4560, h: 680, font: 14 },
            code: { x: 240, y: 960, w: 3200, h: 1900 },
            qr: { x: 3560, y: 980, w: 1380, h: 1380 }
        },
        /* 3.5" x 1.125" — slim: everything, but the Code128 is dense */
        '30252': {
            paper: '30252 Address',
            draw: { w: 1581, h: 5040 },
            name: { x: 240, y: 140, w: 4560, h: 520, font: 11 },
            code: { x: 240, y: 700, w: 3300, h: 760 },
            qr: { x: 3640, y: 620, w: 920, h: 900 }
        },
        /* 2.25" x 1.25" — small stock: a 36-char Code128 + QR cannot both
           fit legibly, so this template carries the QR only */
        '30336': {
            paper: '30336 Multipurpose',
            draw: { w: 1800, h: 3240 },
            name: { x: 160, y: 120, w: 2920, h: 480, font: 9 },
            code: null,
            qr: { x: 960, y: 680, w: 1080, h: 1080 }
        }
    };

    const REASONS = {
        'framework-missing': 'DYMO framework script not loaded',
        'service-unreachable': 'DYMO Connect not running (or blocked) on this computer',
        'no-printer': 'No DYMO LabelWriter printer found',
        'framework-error': 'DYMO framework failed to initialize'
    };

    let detectionPromise = null;
    let state = { ready: false, reason: 'not-started', printers: [] };

    /* ---- Detection (cached; one web-service round trip per page load) ---- */

    function detect() {
        if (detectionPromise) return detectionPromise;
        detectionPromise = new Promise(function (resolve) {
            const fw = window.dymo && window.dymo.label ? window.dymo.label.framework : null;
            if (!fw) return finish({ ready: false, reason: 'framework-missing', printers: [] });

            const timer = setTimeout(function () {
                finish({ ready: false, reason: 'service-unreachable', printers: [] });
            }, DETECT_TIMEOUT_MS);

            function finish(result) {
                clearTimeout(timer);
                state = result;
                if (result.ready && document.body) document.body.classList.add('dymo-ready');
                if (document.body) document.body.classList.add('dymo-checked');
                resolve(result);
            }

            try {
                fw.checkEnvironment(function (env) {
                    if (!env || !env.isWebServicePresent) {
                        return finish({ ready: false, reason: 'service-unreachable', printers: [] });
                    }
                    fw.getLabelWriterPrintersAsync().then(function (printers) {
                        const names = (printers || []).map(function (p) { return p.name; });
                        if (!names.length) {
                            return finish({ ready: false, reason: 'no-printer', printers: [] });
                        }
                        finish({ ready: true, reason: '', printers: names });
                    }).catch(function () {
                        finish({ ready: false, reason: 'service-unreachable', printers: [] });
                    });
                });
            } catch (e) {
                finish({ ready: false, reason: 'framework-error', printers: [] });
            }
        });
        return detectionPromise;
    }

    function reasonText(reason) {
        return REASONS[reason] || (reason || 'unknown');
    }

    /* ---- Per-workstation prefs ---- */

    function getPrinter() { return localStorage.getItem(LS_PRINTER) || ''; }
    function setPrinter(name) { localStorage.setItem(LS_PRINTER, String(name || '')); }
    function getTemplate() { return localStorage.getItem(LS_TEMPLATE) || DEFAULT_TEMPLATE; }
    function setTemplate(id) { if (TEMPLATES[id]) localStorage.setItem(LS_TEMPLATE, id); }

    /* ---- Label XML ---- */

    function xmlEscape(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&apos;');
    }

    function bounds(b) {
        return '<Bounds X="' + b.x + '" Y="' + b.y + '" Width="' + b.w + '" Height="' + b.h + '"/>';
    }

    function objectInfo(inner, b) {
        return '<ObjectInfo>' + inner + bounds(b) + '</ObjectInfo>';
    }

    function textObject(name, text, cfg) {
        return '<TextObject>' +
            '<Name>' + name + '</Name>' +
            '<ForeColor Alpha="255" Red="0" Green="0" Blue="0"/>' +
            '<BackColor Alpha="0" Red="255" Green="255" Blue="255"/>' +
            '<LinkedObjectName></LinkedObjectName>' +
            '<Rotation>Rotation0</Rotation>' +
            '<IsMirrored>False</IsMirrored>' +
            '<IsVariable>True</IsVariable>' +
            '<HorizontalAlignment>Center</HorizontalAlignment>' +
            '<VerticalAlignment>Middle</VerticalAlignment>' +
            '<TextFitMode>ShrinkToFit</TextFitMode>' +
            '<UseFullFontHeight>True</UseFullFontHeight>' +
            '<Verticalized>False</Verticalized>' +
            '<StyledText><Element>' +
            '<String>' + xmlEscape(text) + '</String>' +
            '<Attributes>' +
            '<Font Family="Arial" Size="' + cfg.font + '" Bold="True" Italic="False" Underline="False" Strike="False"/>' +
            '<ForeColor Alpha="255" Red="0" Green="0" Blue="0"/>' +
            '</Attributes>' +
            '</Element></StyledText>' +
            '</TextObject>';
    }

    function barcodeObject(name, data, type, size) {
        return '<BarcodeObject>' +
            '<Name>' + name + '</Name>' +
            '<ForeColor Alpha="255" Red="0" Green="0" Blue="0"/>' +
            '<BackColor Alpha="0" Red="255" Green="255" Blue="255"/>' +
            '<LinkedObjectName></LinkedObjectName>' +
            '<Rotation>Rotation0</Rotation>' +
            '<IsMirrored>False</IsMirrored>' +
            '<IsVariable>True</IsVariable>' +
            '<Text>' + xmlEscape(data) + '</Text>' +
            '<Type>' + type + '</Type>' +
            '<Size>' + size + '</Size>' +
            '<TextPosition>None</TextPosition>' +
            '<TextFont Family="Arial" Size="8" Bold="False" Italic="False" Underline="False" Strike="False"/>' +
            '<CheckSumText>False</CheckSumText>' +
            '<HumanReadable>None</HumanReadable>' +
            '<EANMargin>True</EANMargin>' +
            '</BarcodeObject>';
    }

    /**
     * Build the DieCutLabel XML for one item sticker.
     * 30334/30252 carry BARCODE (Code128) + QRCODE; 30336 carries QRCODE only.
     * Exposed for console smoke-testing (round-trip the output through DYMO
     * Connect before trusting a new template).
     */
    function buildLabelXml(templateId, name, uuid) {
        const t = TEMPLATES[templateId] || TEMPLATES[DEFAULT_TEMPLATE];
        const text = String(name || '').trim().substring(0, 60) || 'Unlabeled item';
        const u = String(uuid || '').trim();

        return '<?xml version="1.0" encoding="utf-8"?>' +
            '<DieCutLabel Version="8.0" Units="twips">' +
            '<PaperOrientation>Landscape</PaperOrientation>' +
            '<Id>Custom</Id>' +
            '<IsOutlined>False</IsOutlined>' +
            '<PaperName>' + t.paper + '</PaperName>' +
            '<DrawCommands><RoundRectangle X="0" Y="0" Width="' + t.draw.w +
            '" Height="' + t.draw.h + '" Rx="278" Ry="278"/></DrawCommands>' +
            objectInfo(textObject('NAME', text, t.name), t.name) +
            (t.code ? objectInfo(barcodeObject('BARCODE', u, 'Code128', 'Small'), t.code) : '') +
            objectInfo(barcodeObject('QRCODE', u, 'QRCode', 'Large'), t.qr) +
            '</DieCutLabel>';
    }

    /* ---- Printing ---- */

    async function printItem(name, uuid, opts) {
        opts = opts || {};
        const det = await detect();
        if (!det.ready) throw new Error(reasonText(det.reason));

        /* Prefer the workstation's saved printer; fall back to the first found */
        let printer = opts.printer || getPrinter();
        if (!printer || det.printers.indexOf(printer) === -1) printer = det.printers[0];

        let templateId = opts.template || getTemplate();
        if (!TEMPLATES[templateId]) templateId = DEFAULT_TEMPLATE;

        const labelXml = buildLabelXml(templateId, name, uuid);
        dymo.label.framework.printLabel(printer, labelXml, null, null);
        return printer;
    }

    /* Serialized jobs — the print station's touch-to-print fires faster than
       the printer drains, and DYMO Connect queues per job cleanly in order */
    let queue = Promise.resolve();
    function printItemQueued(name, uuid, opts) {
        const job = queue.then(function () { return printItem(name, uuid, opts); });
        queue = job.catch(function () {});
        return job;
    }

    return {
        detect: detect,
        reasonText: reasonText,
        status: function () { return state; },
        getPrinter: getPrinter,
        setPrinter: setPrinter,
        getTemplate: getTemplate,
        setTemplate: setTemplate,
        TEMPLATES: TEMPLATES,
        buildLabelXml: buildLabelXml,
        printItem: printItem,
        printItemQueued: printItemQueued
    };
})();
