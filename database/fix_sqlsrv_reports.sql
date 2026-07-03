-- ============================================================
-- 修复 SQL Server 不兼容的报表配置
-- 1. category_list 报表引用了 MySQL 表 fun_addons_cms_category
--    → 在 SQL Server 中该表不存在，禁用此报表
-- 2. user_list 的日期筛选器使用了 MySQL 的 FROM_UNIXTIME 函数
--    → SQL Server 使用 DATEADD 替代
-- ============================================================

-- 1. 禁用 category_list 报表（表不存在）
UPDATE dbo.report_def SET enabled = 0 WHERE code = 'category_list';

-- 2. 修复 user_list 日期筛选器表达式
-- MySQL: DATE(FROM_UNIXTIME(a.create_time))
-- SQL Server (无需引号): CAST(DATEADD(SECOND, a.create_time, DATEADD(DAY, 25567, 0)) AS DATE)
--   25567 = days between 1900-01-01 (SQL Server epoch) and 1970-01-01 (Unix epoch)
UPDATE dbo.report_filter
SET expr = N'CAST(DATEADD(SECOND, a.create_time, DATEADD(DAY, 25567, 0)) AS DATE)'
WHERE report_code = 'user_list' AND param_name = 'created';

PRINT '修复完成。';
PRINT '  - category_list 已禁用';
PRINT '  - user_list.created 表达式已修复 (使用纯整数运算, 无需引号)';
