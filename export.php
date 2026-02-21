<?php
// =============================================
// export.php - 엑셀 다운로드 (PhpSpreadsheet)
// composer require phpoffice/phpspreadsheet
// =============================================

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

function exportExcel(Database $db, array $selectedIds, array $filters): void {
    $bids = $db->getBidsForExport($selectedIds, $filters);

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();
    $sheet->setTitle('입찰공고목록');

    // ── 헤더 스타일 ──
    $headerStyle = [
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F6FEB']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']]],
    ];

    // ── 헤더 작성 ──
    $headers = ['번호', '공고명', '출처', '발주기관', '매칭 키워드', '예산', '마감일', '수집일', '링크'];
    foreach ($headers as $col => $header) {
        $cell = chr(65 + $col) . '1';
        $sheet->setCellValue($cell, $header);
    }
    $sheet->getStyle('A1:I1')->applyFromArray($headerStyle);
    $sheet->getRowDimension(1)->setRowHeight(24);

    // ── 데이터 작성 ──
    $rowStyle = [
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ];

    foreach ($bids as $i => $bid) {
        $row = $i + 2;
        $sheet->setCellValue("A{$row}", $i + 1);
        $sheet->setCellValue("B{$row}", $bid['title']);
        $sheet->setCellValue("C{$row}", $bid['source']);
        $sheet->setCellValue("D{$row}", $bid['org_name']);
        $sheet->setCellValue("E{$row}", $bid['matched_keywords'] ?? '-');
        $sheet->setCellValue("F{$row}", $bid['budget']);
        $sheet->setCellValue("G{$row}", $bid['deadline_date']);
        $sheet->setCellValue("H{$row}", date('Y-m-d', strtotime($bid['fetched_at'])));
        $sheet->setCellValue("I{$row}", $bid['url']);

        // 링크 하이퍼링크
        $sheet->getCell("I{$row}")->getHyperlink()->setUrl($bid['url']);

        // 짝수 행 배경
        if ($row % 2 === 0) {
            $sheet->getStyle("A{$row}:I{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('F6F8FF');
        }

        $sheet->getStyle("A{$row}:I{$row}")->applyFromArray($rowStyle);
        $sheet->getRowDimension($row)->setRowHeight(20);
    }

    // ── 열 너비 ──
    $widths = [6, 60, 18, 24, 28, 16, 14, 14, 50];
    foreach ($widths as $col => $width) {
        $sheet->getColumnDimension(chr(65 + $col))->setWidth($width);
    }

    // ── 출처별 배지 색상 ──
    $sourceColors = [
        '나라장터'           => 'DBEAFE',
        'K-스타트업'         => 'DCFCE7',
        '중소기업기술정보진흥원' => 'FEF9C3',
        'IITP'              => 'FFE4E6',
    ];
    foreach ($bids as $i => $bid) {
        $row   = $i + 2;
        $color = $sourceColors[$bid['source']] ?? 'FFFFFF';
        $sheet->getStyle("C{$row}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB($color);
    }

    // ── 헤더 고정 ──
    $sheet->freezePane('A2');

    // ── 다운로드 ──
    $filename = '입찰공고목록_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
}
