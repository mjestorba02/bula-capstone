<?php
declare(strict_types=1);

/**
 * Report writers: CSV, Excel (.xlsx) and PDF. Library-free so they run on a
 * plain XAMPP install (the .xlsx writer uses PHP's built-in ZipArchive).
 *
 * $columns: [['key'=>'amount','label'=>'Amount','type'=>'money'|'number'|'text'|'percent'], ...]
 * $rows:    list of associative arrays keyed by column key
 * $meta:    ['title'=>..., 'subtitle'=>..., 'filters'=>..., 'summary'=>[label=>value,...], 'filename'=>...]
 */

function report_cell_text($value, string $type): string {
  if ($value === null) return '';
  switch ($type) {
    case 'money': return 'PHP '.number_format((float)$value, 2);
    case 'number': return number_format((float)$value, 0);
    case 'percent': return number_format((float)$value, 1).'%';
    default: return trim(preg_replace('/\s+/', ' ', (string)$value));
  }
}

function report_send_headers(string $mime, string $filename): void {
  header_remove('Content-Type');
  header('Content-Type: '.$mime);
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  header('Cache-Control: no-store');
  header('X-Content-Type-Options: nosniff');
}

// ---------------------------------------------------------------- CSV
function report_write_csv(array $columns, array $rows, array $meta): void {
  report_send_headers('text/csv; charset=utf-8', $meta['filename'].'.csv');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ñ and other characters correctly
  fputcsv($out, array_column($columns, 'label'));
  foreach ($rows as $r) {
    $line = [];
    foreach ($columns as $c) {
      $v = $r[$c['key']] ?? '';
      if (in_array($c['type'], ['money','number','percent'], true)) $line[] = is_numeric($v) ? (string)round((float)$v, 2) : '';
      else {
        $v = (string)$v;
        // Neutralise spreadsheet formula injection (=, +, -, @ at the start of a text cell).
        if ($v !== '' && strpbrk($v[0], '=+-@') !== false) $v = "'".$v;
        $line[] = $v;
      }
    }
    fputcsv($out, $line);
  }
  fclose($out);
}

// ---------------------------------------------------------------- XLSX
function xlsx_col(int $index): string {
  $s = '';
  for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + (($n - 1) % 26)).$s;
  return $s;
}

function xlsx_esc(string $v): string {
  $v = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $v) ?? '';
  return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function report_write_xlsx(array $columns, array $rows, array $meta): void {
  if (!class_exists('ZipArchive')) { report_write_xls_xml($columns, $rows, $meta); return; }
  $styleFor = ['text'=>0, 'money'=>2, 'number'=>4, 'percent'=>5];
  $xmlRows = [];
  $r = 1;
  $inline = fn(string $ref, string $text, int $style = 0) => '<c r="'.$ref.'" t="inlineStr"'.($style ? ' s="'.$style.'"' : '').'><is><t xml:space="preserve">'.xlsx_esc($text).'</t></is></c>';
  $xmlRows[] = '<row r="'.$r.'">'.$inline('A'.$r, (string)$meta['title'], 3).'</row>'; $r++;
  if (!empty($meta['subtitle'])) { $xmlRows[] = '<row r="'.$r.'">'.$inline('A'.$r, (string)$meta['subtitle']).'</row>'; $r++; }
  $xmlRows[] = '<row r="'.$r.'">'.$inline('A'.$r, 'Generated: '.date('F j, Y g:i A').'   |   '.($meta['filters'] ?? '')).'</row>'; $r++;
  $r++;
  $headerRow = $r;
  $cells = '';
  foreach ($columns as $i => $c) $cells .= $inline(xlsx_col($i).$r, $c['label'], 1);
  $xmlRows[] = '<row r="'.$r.'">'.$cells.'</row>'; $r++;
  foreach ($rows as $row) {
    $cells = '';
    foreach ($columns as $i => $c) {
      $ref = xlsx_col($i).$r;
      $v = $row[$c['key']] ?? '';
      if ($c['type'] !== 'text' && is_numeric($v)) {
        $num = (float)$v;
        if ($c['type'] === 'percent') $num = $num / 100;
        $cells .= '<c r="'.$ref.'" s="'.$styleFor[$c['type']].'"><v>'.$num.'</v></c>';
      } elseif ((string)$v !== '') {
        $cells .= $inline($ref, (string)$v);
      }
    }
    $xmlRows[] = '<row r="'.$r.'">'.$cells.'</row>'; $r++;
  }
  if (!empty($meta['summary'])) {
    $r++;
    foreach ($meta['summary'] as $label => $value) {
      $xmlRows[] = '<row r="'.$r.'">'.$inline('A'.$r, (string)$label, 1).$inline('B'.$r, (string)$value).'</row>'; $r++;
    }
  }
  $cols = '';
  foreach ($columns as $i => $c) {
    $max = mb_strlen($c['label']);
    foreach (array_slice($rows, 0, 300) as $row) $max = max($max, mb_strlen(report_cell_text($row[$c['key']] ?? '', $c['type'])));
    $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.min(48, max(10, $max + 2)).'" customWidth="1"/>';
  }
  $lastCol = xlsx_col(max(0, count($columns) - 1));
  $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$headerRow.'" topLeftCell="A'.($headerRow + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
    .'<cols>'.$cols.'</cols><sheetData>'.implode('', $xmlRows).'</sheetData>'
    .(count($rows) ? '<autoFilter ref="A'.$headerRow.':'.$lastCol.($headerRow + count($rows)).'"/>' : '')
    .'</worksheet>';
  $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    .'<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;PHP &quot;#,##0.00"/></numFmts>'
    .'<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF173B82"/><name val="Calibri"/></font></fonts>'
    .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF173B82"/><bgColor indexed="64"/></patternFill></fill></fills>'
    .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    .'<cellXfs count="6">'
      .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
      .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
      .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
      .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
      .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
      .'<xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    .'</cellXfs></styleSheet>';
  $sheetName = xlsx_esc(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', (string)($meta['sheet'] ?? 'Report')), 0, 31));
  $files = [
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
    'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>'.xlsx_esc((string)$meta['title']).'</dc:title><dc:creator>Bestlink College Payment System</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>',
    'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$sheetName.'" sheetId="1" r:id="rId1"/></sheets>'.(count($rows) ? '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\''.$sheetName.'\'!$A$'.$headerRow.':$'.$lastCol.'$'.($headerRow + count($rows)).'</definedName></definedNames>' : '').'</workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
    'xl/styles.xml' => $styles,
    'xl/worksheets/sheet1.xml' => $sheet,
  ];
  $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
  $zip = new ZipArchive();
  if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); report_write_xls_xml($columns, $rows, $meta); return; }
  foreach ($files as $name => $content) $zip->addFromString($name, $content);
  $zip->close();
  report_send_headers('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $meta['filename'].'.xlsx');
  header('Content-Length: '.filesize($tmp));
  readfile($tmp);
  @unlink($tmp);
}

/** Fallback when ZipArchive is missing: Excel 2003 XML spreadsheet. */
function report_write_xls_xml(array $columns, array $rows, array $meta): void {
  report_send_headers('application/vnd.ms-excel', $meta['filename'].'.xls');
  echo '<?xml version="1.0" encoding="UTF-8"?><?mso-application progid="Excel.Sheet"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Report"><Table>';
  echo '<Row><Cell><Data ss:Type="String">'.xlsx_esc((string)$meta['title']).'</Data></Cell></Row><Row>';
  foreach ($columns as $c) echo '<Cell><Data ss:Type="String">'.xlsx_esc($c['label']).'</Data></Cell>';
  echo '</Row>';
  foreach ($rows as $r) {
    echo '<Row>';
    foreach ($columns as $c) {
      $v = $r[$c['key']] ?? '';
      echo ($c['type'] !== 'text' && is_numeric($v)) ? '<Cell><Data ss:Type="Number">'.(float)$v.'</Data></Cell>' : '<Cell><Data ss:Type="String">'.xlsx_esc((string)$v).'</Data></Cell>';
    }
    echo '</Row>';
  }
  echo '</Table></Worksheet></Workbook>';
}

// ---------------------------------------------------------------- PDF
function pdf_text(string $s): string {
  $s = str_replace(['₱', "\u{2014}", "\u{2013}", "\u{2022}"], ['PHP ', '-', '-', '-'], $s);
  $converted = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
  if ($converted === false) $converted = preg_replace('/[^\x20-\x7E]/', '?', $s);
  return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], (string)$converted);
}

/** Approximate Helvetica text width in points. */
function pdf_width(string $s, float $size): float {
  $w = 0.0;
  $len = mb_strlen($s);
  for ($i = 0; $i < $len; $i++) {
    $ch = mb_substr($s, $i, 1);
    if (strpbrk($ch, 'iljtf.,;:!\'|I ') !== false) $w += 0.29;
    elseif (strpbrk($ch, 'mwMW@') !== false) $w += 0.86;
    elseif (ctype_upper($ch)) $w += 0.68;
    elseif (ctype_digit($ch)) $w += 0.556;
    else $w += 0.53;
  }
  return $w * $size;
}

function pdf_fit(string $s, float $maxWidth, float $size): string {
  if (pdf_width($s, $size) <= $maxWidth) return $s;
  while (mb_strlen($s) > 1 && pdf_width($s.'...', $size) > $maxWidth) $s = mb_substr($s, 0, -1);
  return rtrim($s).'...';
}

function report_write_pdf(array $columns, array $rows, array $meta): void {
  $pageW = 842.0; $pageH = 595.0; // A4 landscape
  $margin = 32.0; $usable = $pageW - 2 * $margin;
  $font = count($columns) > 9 ? 6.6 : 7.6;
  $rowH = $font + 6.2;
  $pad = 3.0;

  // Column widths proportional to content length (capped), scaled to the page width.
  $weights = [];
  foreach ($columns as $c) {
    $max = pdf_width($c['label'], $font) + 2 * $pad;
    foreach (array_slice($rows, 0, 400) as $r) $max = max($max, pdf_width(report_cell_text($r[$c['key']] ?? '', $c['type']), $font) + 2 * $pad);
    $weights[] = min($max, 190.0) + ($c['type'] === 'text' ? 0 : 10.0);
  }
  $scale = $usable / max(1, array_sum($weights));
  $widths = array_map(fn($w) => $w * $scale, $weights);

  $titleBlock = 64.0; $footer = 26.0;
  $firstRows = (int)floor(($pageH - 2 * $margin - $titleBlock - $footer - $rowH) / $rowH);
  $otherRows = (int)floor(($pageH - 2 * $margin - 18 - $footer - $rowH) / $rowH);
  $chunks = [];
  $rest = $rows;
  $chunks[] = array_splice($rest, 0, max(1, $firstRows));
  while ($rest) $chunks[] = array_splice($rest, 0, max(1, $otherRows));
  $summary = $meta['summary'] ?? [];
  $summaryH = $summary ? 20 + count($summary) * 12 : 0;
  $lastUsed = count(end($chunks)) * $rowH + $rowH + (count($chunks) === 1 ? $titleBlock : 18);
  if ($summary && ($pageH - 2 * $margin - $footer - $lastUsed) < $summaryH) $chunks[] = [];
  $pageCount = count($chunks);

  $streams = [];
  foreach ($chunks as $pi => $chunk) {
    $y = $pageH - $margin;
    $s = '';
    $text = function (float $x, float $y, string $str, float $size, bool $bold = false, string $color = '0.09 0.13 0.2') use (&$s) {
      $s .= "BT {$color} rg /".($bold ? 'F2' : 'F1')." $size Tf ".round($x, 2).' '.round($y, 2).' Td ('.pdf_text($str).") Tj ET\n";
    };
    if ($pi === 0) {
      $text($margin, $y - 14, 'BESTLINK COLLEGE OF THE PHILIPPINES', 9, true, '0.42 0.47 0.55');
      $text($margin, $y - 32, (string)$meta['title'], 15, true, '0.09 0.23 0.51');
      $text($margin, $y - 46, pdf_fit(trim(($meta['subtitle'] ?? '').'  '.($meta['filters'] ?? '')), $usable, 7.5), 7.5, false, '0.39 0.45 0.55');
      $text($margin, $y - 57, 'Generated: '.date('F j, Y g:i A').'   |   Records: '.count($rows), 7.5, false, '0.39 0.45 0.55');
      $y -= $titleBlock;
    } else {
      $text($margin, $y - 10, (string)$meta['title'].' (continued)', 8, true, '0.09 0.23 0.51');
      $y -= 18;
    }
    // Header row
    $s .= '0.09 0.23 0.51 rg '.$margin.' '.round($y - $rowH, 2).' '.$usable.' '.round($rowH, 2)." re f\n";
    $x = $margin;
    foreach ($columns as $i => $c) {
      $label = pdf_fit($c['label'], $widths[$i] - 2 * $pad, $font);
      $tx = $c['type'] === 'text' ? $x + $pad : $x + $widths[$i] - $pad - 6 - pdf_width($label, $font);
      $text($tx, $y - $rowH + 4.2, $label, $font, true, '1 1 1');
      $x += $widths[$i];
    }
    $y -= $rowH;
    foreach ($chunk as $ri => $r) {
      if ($ri % 2 === 1) $s .= '0.96 0.97 0.99 rg '.$margin.' '.round($y - $rowH, 2).' '.$usable.' '.round($rowH, 2)." re f\n";
      $x = $margin;
      foreach ($columns as $i => $c) {
        $val = pdf_fit(report_cell_text($r[$c['key']] ?? '', $c['type']), $widths[$i] - 2 * $pad, $font);
        $tx = $c['type'] === 'text' ? $x + $pad : $x + $widths[$i] - $pad - 6 - pdf_width($val, $font);
        $text($tx, $y - $rowH + 4.2, $val, $font);
        $x += $widths[$i];
      }
      $s .= '0.89 0.91 0.94 RG 0.4 w '.$margin.' '.round($y - $rowH, 2).' m '.($margin + $usable).' '.round($y - $rowH, 2)." l S\n";
      $y -= $rowH;
    }
    if (!$rows && $pi === 0) { $text($margin + $pad, $y - 12, 'No records match the selected filters.', 8); $y -= 16; }
    if ($pi === $pageCount - 1 && $summary) {
      $y -= 14;
      $text($margin, $y, 'SUMMARY', 8, true, '0.09 0.23 0.51');
      foreach ($summary as $label => $value) { $y -= 12; $text($margin, $y, $label.':', 7.8, true); $text($margin + 170, $y, (string)$value, 7.8); }
    }
    $text($margin, $margin - 8, 'Bestlink College Payment Management System - system-generated report', 6.8, false, '0.5 0.55 0.62');
    $pageLabel = 'Page '.($pi + 1).' of '.$pageCount;
    $text($pageW - $margin - pdf_width($pageLabel, 6.8), $margin - 8, $pageLabel, 6.8, false, '0.5 0.55 0.62');
    $streams[] = $s;
  }

  // Assemble objects: 1 catalog, 2 pages, 3 F1, 4 F2, then page/content pairs.
  $objects = [];
  $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
  $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
  $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
  $kids = [];
  $n = 5;
  foreach ($streams as $stream) {
    $pageObj = $n++; $contentObj = $n++;
    $kids[] = $pageObj.' 0 R';
    $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$pageW.' '.$pageH.'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$contentObj.' 0 R >>';
    $objects[$contentObj] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream";
  }
  $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
  ksort($objects);
  $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
  $offsets = [];
  foreach ($objects as $id => $body) { $offsets[$id] = strlen($pdf); $pdf .= $id." 0 obj\n".$body."\nendobj\n"; }
  $xref = strlen($pdf);
  $max = max(array_keys($objects));
  $pdf .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";
  for ($i = 1; $i <= $max; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
  $pdf .= "trailer\n<< /Size ".($max + 1)." /Root 1 0 R /Info << /Title (".pdf_text((string)$meta['title']).") /Producer (Bestlink Payment System) >> >>\nstartxref\n".$xref."\n%%EOF";

  report_send_headers('application/pdf', $meta['filename'].'.pdf');
  header('Content-Length: '.strlen($pdf));
  echo $pdf;
}
