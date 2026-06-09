<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\backend\service\ReportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ReportApi extends BaseController
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
            $first = $rows[0] ?? [];
            $cols = array_map(fn($k) => ['field' => $k, 'title' => $k], array_keys($first));
        }

        $colIndex = 1;
        foreach ($cols as $c) {
            $col = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue($col . '1', (string)($c['title'] ?? $c['field'] ?? ''));
            $colIndex++;
        }

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
        (new Xlsx($spreadsheet))->save($tmp);

        $content = file_get_contents($tmp);
        @unlink($tmp);

        return response($content, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
        ]);
    }
}

