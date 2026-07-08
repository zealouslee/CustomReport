<?php
declare(strict_types=1);

namespace app\backend\controller;

use app\backend\service\ReportService;
use app\common\controller\Backend;

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
            set_time_limit(300);

            $code = (string)request()->get('code', '');
            $params = request()->get();
            unset($params['code']);

            $svc = new ReportService();
            $cfg = $svc->getReportConfig($code);

            $max = (int)($cfg['report']['max_export_rows'] ?? 50000);
            $rows = $svc->exportRows($code, $params, $max);

            $cols = $cfg['cols'] ?? [];
            if (!$cols && !empty($rows)) {
                $first = $rows[0];
                $cols = array_map(fn($k) => ['field' => $k, 'title' => $k], array_keys($first));
            }

            $filename = ($cfg['report']['name'] ?? $code) . '_' . date('Ymd_His') . '.csv';
            $tmpFile = runtime_path() . 'export_' . md5($filename . microtime(true)) . '.csv';

            // 流式写入 CSV：内存只占一行，8000行毫无压力
            $fp = fopen($tmpFile, 'w');
            if (!$fp) {
                throw new \RuntimeException('无法创建临时导出文件');
            }
            // UTF-8 BOM：让 Excel 正确识别中文
            fwrite($fp, "\xEF\xBB\xBF");
            // 表头
            fputcsv($fp, array_map(fn($c) => (string)($c['title'] ?? $c['field'] ?? ''), $cols));
            // 数据行
            foreach ($rows as $row) {
                $line = [];
                foreach ($cols as $c) {
                    $field = (string)($c['field'] ?? '');
                    $val = $row[$field] ?? '';
                    $strVal = $this->formatCellValue($val);
                    // 长数字串用 ="value" 包裹，防止 Excel 转科学计数法
                    if (preg_match('/^\d{11,}$/', $strVal)) {
                        $strVal = '="' . str_replace('"', '""', $strVal) . '"';
                    }
                    $line[] = $strVal;
                }
                fputcsv($fp, $line);
            }
            fclose($fp);

            // 发送文件后清理
            register_shutdown_function(function () use ($tmpFile) {
                if (file_exists($tmpFile)) {
                    @unlink($tmpFile);
                }
            });

            // 手动设置 RFC 5987 编码的 Content-Disposition，解决中文文件名乱码
            $encodedName = rawurlencode($filename);
            $disposition = 'attachment; filename="' . $encodedName . '"; filename*=UTF-8\'\'' . $encodedName;

            return response(file_get_contents($tmpFile), 200, [
                'Content-Type'              => 'text/csv; charset=utf-8',
                'Content-Disposition'       => $disposition,
                'Content-Transfer-Encoding' => 'binary',
                'Content-Length'            => (string)filesize($tmpFile),
            ]);
        } catch (\Throwable $e) {
            \think\facade\Log::error('导出失败: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return json(['code' => -1, 'msg' => '导出失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 格式化单元格值：DateTime 对象 / Unix 时间戳 → 可读日期字符串
     */
    private function formatCellValue($val): string
    {
        // sqlsrv 驱动返回 DateTime 对象
        if ($val instanceof \DateTimeInterface) {
            return $val->format('Y-m-d H:i:s');
        }

        $str = (string)$val;

        // Unix 时间戳（10位秒级 / 13位毫秒级）
        if (preg_match('/^\d{10}(\d{3})?$/', $str)) {
            $ts = (int)$str;
            if ($ts > 9999999999) {
                $ts = (int)($ts / 1000); // 毫秒→秒
            }
            // 仅转换合理范围内的时间戳（2000-2050年）
            if ($ts >= 946684800 && $ts <= 2524608000) {
                return date('Y-m-d H:i:s', $ts);
            }
        }

        // SQL Server datetime 带微秒 "2026-07-08 12:30:00.0000000" → 截断
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+$/', $str)) {
            return substr($str, 0, 19);
        }

        return $str;
    }
}

