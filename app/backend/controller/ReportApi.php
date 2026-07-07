<?php
declare(strict_types=1);

namespace app\backend\controller;

use app\backend\service\ReportService;
use app\common\controller\Backend;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ReportApi extends Backend
{
    public function config()
    {
        try {
            $code = (string)request()->get('code', '');
            $svc = new ReportService();
            $cfg = $svc->getReportConfig($code);
            return json(['code' => 0, 'msg' => '', 'data' => $cfg]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '加载报表配置失败']);
        }
    }

    public function filterOptions()
    {
        try {
            $code = (string)request()->get('code', '');
            $paramName = (string)request()->get('param_name', '');
            $parentValue = (string)request()->get('parent_value', '');

            if ($code === '' || $paramName === '' || $parentValue === '') {
                return json(['code' => -1, 'msg' => '缺少参数']);
            }

            $svc = new ReportService();
            $options = $svc->getFilterOptions($code, $paramName, $parentValue);

            return json(['code' => 0, 'msg' => '', 'data' => $options]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '加载筛选选项失败']);
        }
    }

    public function data()
    {
        try {
            $code = (string)request()->param('code', '');
            $page = (int)request()->param('page', 1);
            $limit = (int)request()->param('limit', 20);
            $params = request()->param();
            unset($params['code'], $params['page'], $params['limit']);

            $svc = new ReportService();
            [$total, $rows] = $svc->query($code, $params, $page, $limit);

            return json(['code' => 0, 'msg' => '', 'count' => $total, 'data' => $rows]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '查询数据失败']);
        }
    }

    public function export()
    {
        try {
                $originalMemory = ini_get('memory_limit');
                ini_set('memory_limit', '512M');

            $code = (string)request()->get('code', '');
            $params = request()->get();
            unset($params['code']);

            $svc = new ReportService();
            $cfg = $svc->getReportConfig($code);

            $max = (int)($cfg['report']['max_export_rows'] ?? 50000);
            $rows = $svc->exportRows($code, $params, $max);

            $spreadsheet = new Spreadsheet();
            $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
            $sheet = $spreadsheet->getActiveSheet();

            $cols = $cfg['cols'] ?? [];
            if (!$cols) {
                // 没配置列时，按查询结果自动生成表头
                $first = $rows[0] ?? [];
                $cols = array_map(fn($k) => ['field' => $k, 'title' => $k], array_keys($first));
            }

            $colCount = count($cols);
            $rowCount = count($rows);
            $lastCol = Coordinate::stringFromColumnIndex($colCount);

            // 设置所有数据区域为文本格式，防止科学计数法
            if ($rowCount > 0) {
                $dataArea = 'A2:' . $lastCol . ($rowCount + 1);
                $sheet->getStyle($dataArea)->getNumberFormat()->setFormatCode('@');
            }

            // 表头样式：蓝底白字、加粗、居中、细边框
            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11, 'name' => 'Calibri'],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            ];

            // 数据行样式：黑色字体、垂直居中、细边框
            $dataStyle = [
                'font' => ['bold' => false, 'color' => ['rgb' => '000000'], 'size' => 11, 'name' => 'Calibri'],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            ];

            // 写入表头
            $colIndex = 1;
            foreach ($cols as $c) {
                $col = Coordinate::stringFromColumnIndex($colIndex);
                $cell = $col . '1';
                $sheet->setCellValue($cell, (string)($c['title'] ?? $c['field'] ?? ''));
                $sheet->getStyle($cell)->applyFromArray($headerStyle);
                $colIndex++;
            }
            $sheet->getRowDimension(1)->setRowHeight(24);

            // 写入数据行
            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;
                $sheet->getRowDimension($rowNum)->setRowHeight(20);
                $colIndex = 1;
                foreach ($cols as $c) {
                    $field = (string)($c['field'] ?? '');
                    $col = Coordinate::stringFromColumnIndex($colIndex);
                    $val = $row[$field] ?? '';
                    // 用显式字符串写入，避免 Excel 将长数字转为科学计数法
                    $sheet->setCellValueExplicit($col . $rowNum, $val, DataType::TYPE_STRING);
                    $colIndex++;
                }
            }

            // 批量设置数据行样式（比逐单元格设置更高效）
            if ($rowCount > 0) {
                $dataRange = 'A2:' . $lastCol . ($rowCount + 1);
                $sheet->getStyle($dataRange)->applyFromArray($dataStyle);
            }

            // 自动列宽
            foreach (range('A', $lastCol) as $colID) {
                $sheet->getColumnDimension($colID)->setAutoSize(true);
            }

            // 冻结首行
            $sheet->freezePane('A2');

            $filename = ($cfg['report']['name'] ?? $code) . '_' . date('Ymd_His') . '.xlsx';
            $tmp = runtime_path() . 'report_export_' . md5($filename . microtime(true)) . '.xlsx';
            try {
                (new Xlsx($spreadsheet))->save($tmp);

                if (!file_exists($tmp)) {
                    throw new \RuntimeException('导出文件生成失败');
                }

                $content = file_get_contents($tmp);

                return response($content, 200, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
                ]);
            } finally {
                // 恢复内存限制
                ini_set('memory_limit', $originalMemory);
                if (file_exists($tmp)) {
                    unlink($tmp);
                }
            }
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '导出失败']);
        }
    }
}

