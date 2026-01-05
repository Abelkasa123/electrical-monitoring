<?php
// Force download of Excel template
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="electrical_data_template.xlsx"');
header('Cache-Control: max-age=0');

// Create simple Excel file with headers
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set headers
$headers = [
    'Timestamp',
    'Voltage (V)',
    'Current (A)',
    'Active Power (W)',
    'Reactive Power (VAR)',
    'Frequency (Hz)',
    'Power Factor',
    'Voltage Category'
];

// Add header row
foreach($headers as $index => $header) {
    $sheet->setCellValue(chr(65 + $index) . '1', $header);
    
    // Set column width
    $sheet->getColumnDimension(chr(65 + $index))->setWidth(20);
}

// Add some sample data
$sampleData = [
    ['2023-12-15 08:00:00', 230.50, 15.25, 3512.75, 1250.30, 50.00, 0.95, 'Low Voltage'],
    ['2023-12-15 09:00:00', 231.00, 14.80, 3418.80, 1105.60, 49.98, 0.96, 'Low Voltage'],
    ['', 229.75, 16.50, 3790.88, 1420.45, 50.02, 0.93, 'Medium Voltage']
];

foreach($sampleData as $rowIndex => $rowData) {
    foreach($rowData as $colIndex => $value) {
        $sheet->setCellValue(chr(65 + $colIndex) . ($rowIndex + 2), $value);
    }
}

// Style the header
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => [
        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
        'color' => ['rgb' => '107c41']
    ],
    'borders' => [
        'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
    ]
];

$sheet->getStyle('A1:H1')->applyFromArray($headerStyle);

// Auto-filter
$sheet->setAutoFilter('A1:H1');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;