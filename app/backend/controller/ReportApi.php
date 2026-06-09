<?php
declare(strict_types=1);

namespace app\backend\controller;

use app\backend\service\ReportService;
use app\common\controller\Backend;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ReportApi extends Backend
{
    public function config()
    {
        $code = (string)request()->get('code', '');
        $svc = new ReportService();
        $cfg = $svc->getReportConfig($code);
        return json(['code' => 0, 'msg' => '', 'data' => $cfg]);
    }

    public function data()
    {
        $code = (string)request()->get('code', '');
        $page = (int)request()->get('page', 1);
        $limit = (int)request()->get('limit', 20);
        $params = request()->get();
        unset($params['code'], $params['page'], $params['limit']);

        $svc = new ReportService();
        [$total, $rows] = $svc->query($code, $params, $page, $limit);

        return json(['code' => 0, 'msg' => '', 'count' => $total, 'data' => $rows]);
    }

    public function export()
    {
        $code = (string)request()->get('code', '');
        $params = request()->get();
        unset($params['code']);

        $svc = new ReportService();
        $cfg = $svc->getReportConfig($code);

        $max = (int)($cfg['report']['max_export_rows'] ?? 50000);
        $rows = $svc->exportRows($code, $params, $max);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $cols = $cfg['cols'] ?? [];
        if (!$cols) {
            // 没配置列时，按查询结果自动生成表头
            $first = $rows[0] ?? [];
            $cols = array_map(fn($k) => ['field' => $k, 'title' => $k], array_keys($first));
        }

        // header
        $colIndex = 1;
        foreach ($cols as $c) {
            $col = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue($col . '1', (string)($c['title'] ?? $c['field'] ?? ''));
            $colIndex++;
        }

        // rows
        $rowNum = 2;
        foreach ($rows as $row) {
            $colIndex = 1;
            foreach ($cols as $c) {
                $field = (string)($c['field'] ?? '');
                $col = Coordinate::stringFromColumnIndex($colIndex);
                $sheet->setCellValue($col . $rowNum, $row[$field] ?? '');
                $colIndex++;
            }
            $rowNum++;
        }

        $filename = ($cfg['report']['name'] ?? $code) . '_' . date('Ymd_His') . '.xlsx';
        $tmp = runtime_path() . 'report_export_' . md5($filename . microtime(true)) . '.xlsx';
        try {
            (new Xlsx($spreadsheet))->save($tmp);

            if (!file_exists($tmp)) {
                throw new \RuntimeException('导出文件生成失败');
            }

            $content = file_get_contents($tmp);

            return response($content, 200, [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
            ]);
        } finally {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }
    }
}

