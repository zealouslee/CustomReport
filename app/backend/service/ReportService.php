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
            // 依赖型筛选器（有 depends_on）不预加载选项，由前端按需通过 API 获取
            if (!empty($f['depends_on'])) {
                $f['options'] = [];
            } else {
                $f['options'] = $this->resolveFilterOptions($f);
            }
            unset($f['options_json'], $f['options_sql']);
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

        // SQL Server 不支持子查询中的 ORDER BY，计数时需移除
        $countSql = 'SELECT COUNT(1) AS cnt FROM (' . $this->stripOrderBy($baseSql) . ') _t';
        $countRow = Db::query($countSql, $bind);
        $total = (int)($countRow[0]['cnt'] ?? 0);

        if ($this->isSqlSrv()) {
            // SQL Server 使用 OFFSET/FETCH 分页（需保证有 ORDER BY）
            $dataSql = $baseSql . ' OFFSET ' . (int)$offset . ' ROWS FETCH NEXT ' . (int)$limit . ' ROWS ONLY';
        } else {
            $dataSql = $baseSql . ' LIMIT ' . (int)$offset . ',' . (int)$limit;
        }
        $rows = Db::query($dataSql, $bind);
        $rows = $this->fixDecimalLeadingZero($rows);

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
        if ($this->isSqlSrv()) {
            $sql = $baseSql . ' OFFSET 0 ROWS FETCH NEXT ' . (int)$maxRows . ' ROWS ONLY';
        } else {
            $sql = $baseSql . ' LIMIT ' . (int)$maxRows;
        }
        $rows = Db::query($sql, $bind);
        return $this->fixDecimalLeadingZero($rows);
    }

    /**
     * 获取依赖型筛选器的选项（按父级值过滤）
     */
    public function getFilterOptions(string $code, string $paramName, string $parentValue): array
    {
        $filter = Db::name('report_filter')
            ->where(['report_code' => $code, 'param_name' => $paramName, 'enabled' => 1])
            ->find();

        if (!$filter) {
            return [];
        }

        $sql = (string)($filter['options_sql'] ?? '');
        if ($sql === '') {
            return [];
        }

        $s = trim($sql);
        if (!preg_match('/^\s*select\b/i', $s)
            || str_contains($s, ';')
            || str_contains($s, '--')
            || str_contains($s, '/*')
        ) {
            return [];
        }

        // 根据父级 filter 的 expr 提取列名，拼接 WHERE 条件
        $dependsOn = (string)($filter['depends_on'] ?? '');
        if ($dependsOn !== '') {
            // 如果 options_sql 已包含 :parent_value 占位符（自定义 WHERE），直接使用
            if (str_contains($s, ':parent_value')) {
                // SQL 已自带 :parent_value，不需要注入 WHERE
            } else {
                $parentFilter = Db::name('report_filter')
                    ->where(['report_code' => $code, 'param_name' => $dependsOn])
                    ->find();
                if (!$parentFilter) {
                    return [];
                }
                $parentExpr = (string)($parentFilter['expr'] ?? '');
                // 去除表前缀，如 pp.ID_Org -> ID_Org
                $colName = preg_replace('/^\w+\./', '', $parentExpr);
                $whereClause = ' WHERE ' . $colName . ' = :parent_value';
                if (preg_match('/\s+ORDER\s+BY\s/i', $s)) {
                    $s = preg_replace('/\s+ORDER\s+BY\s/i', $whereClause . ' ORDER BY ', $s, 1);
                } else {
                    $s .= $whereClause;
                }
            }
        }

        try {
            $bind = $dependsOn !== '' ? ['parent_value' => $parentValue] : [];
            $rows = Db::query($s, $bind);
            $options = [];
            foreach ($rows as $row) {
                $options[] = [
                    'value' => $row['value'] ?? reset($row),
                    'label' => $row['label'] ?? $row['value'] ?? reset($row),
                ];
            }
            return $options;
        } catch (\Exception $e) {
            return [];
        }
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
                    // 开始日期补 00:00:00，结束日期补 23:59:59，避免同一天查不出数据
                    $where .= ' AND ' . $expr . ' BETWEEN :' . $k1 . ' AND :' . $k2;
                    $bind[$k1] = $start . (str_contains($start, ' ') ? '' : ' 00:00:00');
                    $bind[$k2] = $end . (str_contains($end, ' ') ? '' : ' 23:59:59');
                } elseif ($start !== null && $start !== '') {
                    $where .= ' AND ' . $expr . ' >= :' . $k1;
                    $bind[$k1] = $start . (str_contains($start, ' ') ? '' : ' 00:00:00');
                } else {
                    $where .= ' AND ' . $expr . ' <= :' . $k2;
                    $bind[$k2] = $end . (str_contains($end, ' ') ? '' : ' 23:59:59');
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
        if (!preg_match('/^[\w\s\.\(\)\+\-\*\/`,]+$/', $expr)) {
            throw new HttpException(500, '字段表达式包含非法字符');
        }
    }

    private function decodeJson(string $json, $default)
    {
        $data = json_decode($json, true);
        return is_array($data) ? $data : $default;
    }

    /**
     * 解析筛选器选项：优先执行 options_sql，回退到 options_json
     */
    private function resolveFilterOptions(array $filter): array
    {
        $sql = (string)($filter['options_sql'] ?? '');
        if ($sql !== '') {
            $s = trim($sql);
            if (!preg_match('/^\s*select\b/i', $s)
                || str_contains($s, ';')
                || str_contains($s, '--')
                || str_contains($s, '/*')
            ) {
                return [];
            }
            try {
                $rows = Db::query($sql);
                if (!empty($rows)) {
                    $options = [];
                    foreach ($rows as $row) {
                        $option = [
                            'value' => $row['value'] ?? reset($row),
                            'label'  => $row['label'] ?? $row['value'] ?? reset($row),
                        ];
                        // 保留 options_sql SELECT 中的其他字段（如 ID_Org 用于二级联动）
                        foreach ($row as $k => $v) {
                            if (!in_array($k, ['value', 'label'])) {
                                $option[$k] = $v;
                            }
                        }
                        $options[] = $option;
                    }
                    return $options;
                }
            } catch (\Exception $e) {
                return [];
            }
        }
        return $this->decodeJson($filter['options_json'] ?? '[]', []);
    }

    /**
     * 检测当前数据库是否为 SQL Server
     */
    private function isSqlSrv(): bool
    {
        $config = Db::getConfig();
        $default = $config['default'] ?? '';
        $connection = $config['connections'][$default] ?? [];
        return ($connection['type'] ?? '') === 'sqlsrv';
    }

    /**
     * 移除 SQL 末尾的 ORDER BY 子句（仅最外层，不影响子查询）
     */
    private function stripOrderBy(string $sql): string
    {
        return preg_replace('/\s+ORDER\s+BY\s+[\s\S]*$/i', '', $sql);
    }

    /**
     * 修复 SQL Server PDO 驱动返回 DECIMAL 值时丢失前导零的问题（.0000 → 0.0000）
     */
    private function fixDecimalLeadingZero(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach ($row as &$val) {
                if (is_string($val) && $val !== '' && $val[0] === '.') {
                    $val = '0' . $val;
                }
            }
            unset($val);
        }
        unset($row);
        return $rows;
    }
}

