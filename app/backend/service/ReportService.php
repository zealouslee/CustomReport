<?php
declare(strict_types=1);

namespace app\backend\service;

use think\facade\Db;
use think\facade\Request;
use think\exception\HttpException;

class ReportService
{
    public function getReportConfig(string $code): array
    {
        $report = Db::name('report_def')
            ->where(['code' => $code, 'enabled' => 1])
            ->find();

        if (!$report) {
            throw new HttpException(404, '报表不存在或已禁用');
        }

        $filters = Db::name('report_filter')
            ->where(['report_code' => $code, 'enabled' => 1])
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        $cols = $this->decodeJson($report['cols_json'] ?? '[]', []);
        $filters = array_map(function ($f) {
            $f['options'] = $this->decodeJson($f['options_json'] ?? '[]', []);
            unset($f['options_json']);
            return $f;
        }, $filters);

        $sql = (string)($report['sql_text'] ?? '');
        $this->assertSafeSelectSql($sql);

        return [
            'report' => [
                'code' => $report['code'],
                'name' => $report['name'],
                'default_limit' => (int)($report['default_limit'] ?? 20),
                'max_export_rows' => (int)($report['max_export_rows'] ?? 50000),
            ],
            'cols' => $cols,
            'filters' => $filters,
        ];
    }

    public function query(string $code, array $params, int $page, int $limit): array
    {
        $def = Db::name('report_def')->where(['code' => $code, 'enabled' => 1])->find();
        if (!$def) {
            throw new HttpException(404, '报表不存在或已禁用');
        }

        $sqlTemplate = (string)($def['sql_text'] ?? '');
        $this->assertSafeSelectSql($sqlTemplate);

        $filters = Db::name('report_filter')
            ->where(['report_code' => $code, 'enabled' => 1])
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        [$whereSql, $bind] = $this->buildWhere($filters, $params);

        if (str_contains($sqlTemplate, '{{where}}')) {
            $baseSql = str_replace('{{where}}', $whereSql, $sqlTemplate);
        } else {
            $baseSql = $sqlTemplate . ' ' . $whereSql;
        }

        $page = max(1, (int)$page);
        $limit = max(1, min(2000, (int)$limit));
        $offset = ($page - 1) * $limit;

        $countSql = 'SELECT COUNT(1) AS cnt FROM (' . $baseSql . ') _t';
        $countRow = Db::query($countSql, $bind);
        $total = (int)($countRow[0]['cnt'] ?? 0);

        $dataSql = $baseSql . ' LIMIT ' . (int)$offset . ',' . (int)$limit;
        $rows = Db::query($dataSql, $bind);

        return [$total, $rows];
    }

    public function exportRows(string $code, array $params, int $maxRows): array
    {
        $def = Db::name('report_def')->where(['code' => $code, 'enabled' => 1])->find();
        if (!$def) {
            throw new HttpException(404, '报表不存在或已禁用');
        }

        $sqlTemplate = (string)($def['sql_text'] ?? '');
        $this->assertSafeSelectSql($sqlTemplate);

        $filters = Db::name('report_filter')
            ->where(['report_code' => $code, 'enabled' => 1])
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        [$whereSql, $bind] = $this->buildWhere($filters, $params);

        if (str_contains($sqlTemplate, '{{where}}')) {
            $baseSql = str_replace('{{where}}', $whereSql, $sqlTemplate);
        } else {
            $baseSql = $sqlTemplate . ' ' . $whereSql;
        }

        $maxRows = max(1, min(200000, (int)$maxRows));
        $sql = $baseSql . ' LIMIT ' . (int)$maxRows;
        return Db::query($sql, $bind);
    }

    private function buildWhere(array $filters, array $params): array
    {
        $where = '';
        $bind = [];

        foreach ($filters as $f) {
            $paramName = (string)($f['param_name'] ?? '');
            $operator = strtolower((string)($f['operator'] ?? '='));
            $expr = (string)($f['expr'] ?? '');
            $required = (int)($f['required'] ?? 0) === 1;

            if ($paramName === '' || $expr === '') {
                continue;
            }

            $this->assertSafeExpr($expr);
            $this->assertAllowedOperator($operator);

            $value = $params[$paramName] ?? null;

            // between 操作符使用 {paramName}_start 和 {paramName}_end 参数，跳过这里的值检查
            if ($operator !== 'between') {
                if ($value === '' || $value === null) {
                    if ($required) {
                        throw new HttpException(422, '缺少必填条件：' . ($f['label'] ?? $paramName));
                    }
                    continue;
                }
            }

            $bindKeyBase = 'p_' . preg_replace('/\W+/', '_', $paramName);

            if ($operator === 'in') {
                $items = is_array($value) ? $value : preg_split('/\s*,\s*/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
                if (!$items) {
                    continue;
                }
                $ph = [];
                foreach (array_values($items) as $i => $v) {
                    $k = $bindKeyBase . '_' . $i;
                    $ph[] = ':' . $k;
                    $bind[$k] = $v;
                }
                $where .= ' AND ' . $expr . ' IN (' . implode(',', $ph) . ')';
                continue;
            }

            if ($operator === 'between') {
                $start = $params[$paramName . '_start'] ?? null;
                $end = $params[$paramName . '_end'] ?? null;
                if (($start === null || $start === '') && ($end === null || $end === '')) {
                    if ($required) {
                        throw new HttpException(422, '缺少必填条件：' . ($f['label'] ?? $paramName));
                    }
                    continue;
                }
                $k1 = $bindKeyBase . '_start';
                $k2 = $bindKeyBase . '_end';
                if ($start !== null && $start !== '' && $end !== null && $end !== '') {
                    $where .= ' AND ' . $expr . ' BETWEEN :' . $k1 . ' AND :' . $k2;
                    $bind[$k1] = $start;
                    $bind[$k2] = $end;
                } elseif ($start !== null && $start !== '') {
                    $where .= ' AND ' . $expr . ' >= :' . $k1;
                    $bind[$k1] = $start;
                } else {
                    $where .= ' AND ' . $expr . ' <= :' . $k2;
                    $bind[$k2] = $end;
                }
                continue;
            }

            $bindKey = $bindKeyBase;
            $sqlOp = strtoupper($operator);
            if ($operator === 'like') {
                $bind[$bindKey] = '%' . (string)$value . '%';
                $where .= ' AND ' . $expr . ' LIKE :' . $bindKey;
            } else {
                $bind[$bindKey] = $value;
                $where .= ' AND ' . $expr . ' ' . $sqlOp . ' :' . $bindKey;
            }
        }

        return [$where, $bind];
    }

    private function assertSafeSelectSql(string $sql): void
    {
        $s = trim($sql);
        if ($s === '') {
            throw new HttpException(500, '报表SQL未配置');
        }
        if (!preg_match('/^\s*select\b/i', $s)) {
            throw new HttpException(500, '仅允许SELECT报表SQL');
        }
        if (str_contains($s, ';') || str_contains($s, '--') || str_contains($s, '/*')) {
            throw new HttpException(500, '报表SQL包含不安全片段');
        }
    }

    private function assertAllowedOperator(string $op): void
    {
        $allow = ['=', '!=', '>', '>=', '<', '<=', 'like', 'in', 'between'];
        if (!in_array($op, $allow, true)) {
            throw new HttpException(500, '不支持的查询操作符：' . $op);
        }
    }

    private function assertSafeExpr(string $expr): void
    {
        if (str_contains($expr, ';') || str_contains($expr, '--') || str_contains($expr, '/*')) {
            throw new HttpException(500, '字段表达式包含不安全片段');
        }
        if (!preg_match('/^[\w\s\.\(\)\+\-\*\/`]+$/', $expr)) {
            throw new HttpException(500, '字段表达式包含非法字符');
        }
    }

    private function decodeJson(string $json, $default)
    {
        $data = json_decode($json, true);
        return is_array($data) ? $data : $default;
    }
}

