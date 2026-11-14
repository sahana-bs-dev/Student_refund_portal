<?php

require_once "db.php";
require_once "auth_check.php";

require_login(['hod']);

$department = current_department();

function xmlEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

$rowsXml = '';

$rowsXml .= '<row r="1">';
foreach (['USN', 'Name', 'Department', 'Semester', 'Password'] as $i => $label) {
    $col = chr(65 + $i); 
    $rowsXml .= "<c r=\"{$col}1\" t=\"inlineStr\" s=\"1\"><is><t>" . xmlEscape($label) . "</t></is></c>";
}
$rowsXml .= '</row>';

$example = ['4GW23CS999', 'Student Name', $department, '7', 'SetAPassword'];
$rowsXml .= '<row r="2">';
foreach ($example as $i => $val) {
    $col = chr(65 + $i);
    $rowsXml .= "<c r=\"{$col}2\" t=\"inlineStr\" s=\"2\"><is><t>" . xmlEscape($val) . "</t></is></c>";
}
$rowsXml .= '</row>';

for ($r = 3; $r <= 52; $r++) {
    $rowsXml .= "<row r=\"$r\">";
    $rowsXml .= "<c r=\"A$r\" t=\"inlineStr\" s=\"3\"><is><t></t></is></c>";
    $rowsXml .= "<c r=\"B$r\" t=\"inlineStr\" s=\"0\"><is><t></t></is></c>";
    $rowsXml .= "<c r=\"C$r\" t=\"inlineStr\" s=\"0\"><is><t>" . xmlEscape($department) . "</t></is></c>";
    $rowsXml .= "<c r=\"D$r\" t=\"inlineStr\" s=\"3\"><is><t></t></is></c>";
    $rowsXml .= "<c r=\"E$r\" t=\"inlineStr\" s=\"3\"><is><t></t></is></c>";
    $rowsXml .= '</row>';
}

// -------------------------------------------------------------
// OOXML parts
// -------------------------------------------------------------

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';

$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="Students" sheetId="1" r:id="rId1"/></sheets>
</workbook>';

$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="1"><numFmt numFmtId="164" formatCode="@"/></numFmts>
<fonts count="3">
<font><sz val="11"/><name val="Calibri"/></font>
<font><sz val="11"/><name val="Calibri"/><b/><color rgb="FFFFFFFF"/></font>
<font><sz val="11"/><name val="Calibri"/><i/><color rgb="FF6B7280"/></font>
</fonts>
<fills count="3">
<fill><patternFill patternType="none"/></fill>
<fill><patternFill patternType="gray125"/></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/><bgColor indexed="64"/></patternFill></fill>
</fills>
<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="4">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
<xf numFmtId="164" fontId="2" fillId="0" borderId="0" xfId="0" applyNumFmt="1" applyFont="1"/>
<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumFmt="1"/>
</cellXfs>
</styleSheet>';

$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<cols>
<col min="1" max="1" width="16" customWidth="1"/>
<col min="2" max="2" width="26" customWidth="1"/>
<col min="3" max="3" width="14" customWidth="1"/>
<col min="4" max="4" width="12" customWidth="1"/>
<col min="5" max="5" width="18" customWidth="1"/>
</cols>
<sheetData>' . $rowsXml . '</sheetData>
</worksheet>';

function zipAddFile($name, $data) {
    $nameLen   = strlen($name);
    $crc       = crc32($data);
    $compressed = gzdeflate($data, 9);
    $compSize  = strlen($compressed);
    $uncompSize = strlen($data);

    // Local file header
    $localHeader = "PK\x03\x04";
    $localHeader .= pack('v', 20);   
    $localHeader .= pack('v', 0);       
    $localHeader .= pack('v', 8);  
    $localHeader .= pack('v', 0);   
    $localHeader .= pack('v', 0);      
    $localHeader .= pack('V', $crc);
    $localHeader .= pack('V', $compSize);
    $localHeader .= pack('V', $uncompSize);
    $localHeader .= pack('v', $nameLen);
    $localHeader .= pack('v', 0);      
    $localHeader .= $name;

    return [
        'local' => $localHeader . $compressed,
        'name' => $name,
        'crc' => $crc,
        'compSize' => $compSize,
        'uncompSize' => $uncompSize,
        'offset' => null 
    ];
}

$files = [
    '[Content_Types].xml' => $contentTypes,
    '_rels/.rels' => $rootRels,
    'xl/workbook.xml' => $workbookXml,
    'xl/_rels/workbook.xml.rels' => $workbookRels,
    'xl/styles.xml' => $stylesXml,
    'xl/worksheets/sheet1.xml' => $sheetXml,
];

$body = '';
$entries = [];
$offset = 0;

foreach ($files as $name => $data) {
    $entry = zipAddFile($name, $data);
    $entry['offset'] = $offset;
    $body .= $entry['local'];
    $offset += strlen($entry['local']);
    $entries[] = $entry;
}

$centralDir = '';
foreach ($entries as $entry) {
    $nameLen = strlen($entry['name']);
    $centralDir .= "PK\x01\x02";
    $centralDir .= pack('v', 20);          
    $centralDir .= pack('v', 20);     
    $centralDir .= pack('v', 0);     
    $centralDir .= pack('v', 8);   
    $centralDir .= pack('v', 0);  
    $centralDir .= pack('v', 0);  
    $centralDir .= pack('V', $entry['crc']);
    $centralDir .= pack('V', $entry['compSize']);
    $centralDir .= pack('V', $entry['uncompSize']);
    $centralDir .= pack('v', $nameLen);
    $centralDir .= pack('v', 0);        
    $centralDir .= pack('v', 0);        
    $centralDir .= pack('v', 0);    
    $centralDir .= pack('v', 0);    
    $centralDir .= pack('V', 0);    
    $centralDir .= pack('V', $entry['offset']);
    $centralDir .= $entry['name'];
}

$centralDirOffset = strlen($body);
$centralDirSize = strlen($centralDir);
$numEntries = count($entries);

$endRecord = "PK\x05\x06";
$endRecord .= pack('v', 0);              
$endRecord .= pack('v', 0);         
$endRecord .= pack('v', $numEntries);
$endRecord .= pack('v', $numEntries);
$endRecord .= pack('V', $centralDirSize);
$endRecord .= pack('V', $centralDirOffset);
$endRecord .= pack('v', 0);             

$zipData = $body . $centralDir . $endRecord;

$filename = 'student_upload_template_'
    . preg_replace('/[^a-zA-Z0-9_-]/', '', $department)
    . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($zipData));

echo $zipData;
exit;
?>