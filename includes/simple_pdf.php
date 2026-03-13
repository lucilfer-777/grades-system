<?php
// Minimal single-page PDF generator for plain text (works with PHP 8.2)
// Not a full PDF library — sufficient for simple official record exports.

function pdf_send_simple($filename, $lines = []) {
    $contents = "%PDF-1.1\n";

    // Objects
    $objects = [];
    $offset = strlen($contents);

    // Catalog
    $objects[] = ["<< /Type /Catalog /Pages 2 0 R >>\n"];
    // Pages
    $objects[] = ["<< /Type /Pages /Kids [3 0 R] /Count 1 >>\n"];

    // Prepare page content (text drawing)
    $text = "BT\n/F1 12 Tf\n50 760 Td\n";
    foreach ($lines as $i => $line) {
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
        $text .= '('.$safe.') Tj\n0 -14 Td\n';
    }
    $text .= "ET\n";

    // Content stream
    $stream = $text;
    $stream_len = strlen($stream);
    $objects[] = ["<< /Length " . ($stream_len) . " >>\nstream\n" . $stream . "\nendstream\n"];

    // Font object (Helvetica)
    $objects[] = ["<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\n"];

    // Build xref
    $xrefs = [];
    foreach ($objects as $obj) {
        $xrefs[] = $offset;
        $contents .= (string)$obj[0];
        $offset = strlen($contents);
    }

    $xref_pos = strlen($contents);
    $contents .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($xrefs as $x) {
        $contents .= sprintf("%010d 00000 n \n", $x);
    }

    $contents .= "trailer<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref_pos . "\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    echo $contents;
    exit;
}

?>
