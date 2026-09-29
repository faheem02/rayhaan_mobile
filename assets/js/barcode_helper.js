/**
 * Barcode Helper for IMEI & Product Serials
 * Powered by JsBarcode
 */
function renderImeiBarcodes(container) {
    if (typeof JsBarcode === 'undefined') {
        console.warn('JsBarcode is not loaded.');
        return;
    }
    var root = container ? (typeof container === 'string' ? document.querySelector(container) : container) : document;
    if (!root) return;

    var elements = root.querySelectorAll('.imei-barcode');
    elements.forEach(function(el) {
        var code = el.getAttribute('data-barcode');
        if (!code || code.trim() === '' || code.trim() === '-') return;

        // Skip if already rendered
        if (el.getAttribute('data-rendered') === 'true') return;

        var width = parseFloat(el.getAttribute('data-width') || '1.1');
        var height = parseInt(el.getAttribute('data-height') || '30', 10);
        var fontSize = parseInt(el.getAttribute('data-font-size') || '10', 10);
        var displayValue = el.getAttribute('data-display-value') !== 'false';

        try {
            JsBarcode(el, code.trim(), {
                format: 'CODE128',
                width: width,
                height: height,
                fontSize: fontSize,
                textMargin: 1,
                margin: 2,
                displayValue: displayValue
            });
            el.setAttribute('data-rendered', 'true');
        } catch (e) {
            console.error('Failed to generate barcode for:', code, e);
        }
    });
}

/**
 * Print a standard thermal barcode sticker for a phone / IMEI
 */
function printImeiBarcodeSticker(imei, productName, subtitle) {
    if (!imei || imei === '-') return;
    var w = window.open('', '_blank', 'width=450,height=350');
    if (!w) {
        alert('Please allow popups to print barcode stickers.');
        return;
    }
    var storeName = 'RAYHAAN MOBILE KAHUTA';
    var pName = productName || 'Mobile Phone';
    var sub = subtitle || 'IMEI';

    var html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
    html += '<title>Barcode Sticker - ' + imei + '</title>';
    html += '<style>';
    html += '@page { size: auto; margin: 0; }';
    html += 'body { margin: 0; padding: 10px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; text-align: center; }';
    html += '.sticker { display: inline-block; width: 48mm; padding: 3mm 2mm; border: 1px dashed #cbd5e1; box-sizing: border-box; text-align: center; }';
    html += '.store-name { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #0f172a; margin-bottom: 2px; }';
    html += '.prod-name { font-size: 9px; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 44mm; margin: 0 auto 2px; font-weight: 600; }';
    html += 'svg { max-width: 100%; height: auto; display: block; margin: 0 auto; }';
    html += '@media print { .no-print { display: none !important; } .sticker { border: none !important; } }';
    html += '</style>';
    html += '<script src="' + window.location.origin + '/rayhaan_mobile/assets/js/JsBarcode.all.min.js"><\/script>';
    html += '</head><body>';
    html += '<div class="no-print" style="margin-bottom: 8px;">';
    html += '<button onclick="window.print()" style="padding: 4px 12px; font-size: 12px; cursor: pointer;">Print Sticker</button>';
    html += ' <button onclick="window.close()" style="padding: 4px 12px; font-size: 12px; cursor: pointer;">Close</button>';
    html += '</div>';
    html += '<div class="sticker">';
    html += '<div class="store-name">' + storeName + '</div>';
    html += '<div class="prod-name">' + pName + '</div>';
    html += '<svg id="printBarcodeSvg"></svg>';
    html += '</div>';
    html += '<script>';
    html += 'window.onload = function() {';
    html += '  if (typeof JsBarcode !== "undefined") {';
    html += '    JsBarcode("#printBarcodeSvg", "' + imei + '", {';
    html += '      format: "CODE128", width: 1.15, height: 32, fontSize: 10, margin: 2, displayValue: true';
    html += '    });';
    html += '    setTimeout(function(){ window.print(); }, 250);';
    html += '  }';
    html += '};';
    html += '<\/script>';
    html += '</body></html>';

    w.document.write(html);
    w.document.close();
}

// Auto-render barcodes on page ready
document.addEventListener('DOMContentLoaded', function() {
    renderImeiBarcodes();
});

// Auto-render when bootstrap modals open
if (typeof jQuery !== 'undefined') {
    jQuery(document).on('shown.bs.modal', function(e) {
        renderImeiBarcodes(e.target);
    });
}