-- 自定义查询报表：配置表
-- MySQL 5.7+ / 8.0+

CREATE TABLE IF NOT EXISTS `report_def` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL COMMENT '报表编码(唯一)',
  `name` VARCHAR(128) NOT NULL COMMENT '报表名称',
  `sql_text` MEDIUMTEXT NOT NULL COMMENT '报表SQL模板(仅SELECT，可用 {{where}} 占位)',
  `cols_json` JSON NULL COMMENT 'layui列配置JSON数组',
  `default_limit` INT NOT NULL DEFAULT 20 COMMENT '默认分页条数',
  `max_export_rows` INT NOT NULL DEFAULT 50000 COMMENT '最大导出行数',
  `enabled` TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用',
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报表定义';

CREATE TABLE IF NOT EXISTS `report_filter` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_code` VARCHAR(64) NOT NULL COMMENT '所属报表编码',
  `param_name` VARCHAR(64) NOT NULL COMMENT '参数名(用于请求参数)',
  `label` VARCHAR(128) NOT NULL COMMENT '显示名称',
  `form_type` VARCHAR(32) NOT NULL DEFAULT 'text' COMMENT 'text/date/datetime/daterange/select',
  `operator` VARCHAR(16) NOT NULL DEFAULT '=' COMMENT '=,!=,>,>=,<,<=,like,in,between',
  `expr` VARCHAR(255) NOT NULL COMMENT 'SQL字段/表达式(例: u.id / DATE(u.created_at))',
  `required` TINYINT NOT NULL DEFAULT 0 COMMENT '是否必填',
  `default_value` VARCHAR(255) NULL COMMENT '默认值',
  `options_json` JSON NULL COMMENT 'select选项: [{\"value\":\"1\",\"label\":\"xxx\"}]',
  `sort` INT NOT NULL DEFAULT 100,
  `enabled` TINYINT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_report_code` (`report_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报表查询条件';

-- 示例：用户列表报表（如果你有 users 表请自行调整字段）
-- SQL 模板约定：WHERE 1=1 {{where}}，系统会把条件拼成 " AND ..." 注入 {{where}}
INSERT INTO `report_def` (`code`,`name`,`sql_text`,`cols_json`,`default_limit`,`max_export_rows`,`enabled`)
VALUES
('user_list','用户列表',
 'SELECT u.id,u.username,u.email,u.created_at FROM users u WHERE 1=1 {{where}} ORDER BY u.id DESC',
 JSON_ARRAY(
   JSON_OBJECT('field','id','title','ID','width',90,'sort',true),
   JSON_OBJECT('field','username','title','用户名','width',160),
   JSON_OBJECT('field','email','title','邮箱','width',220),
   JSON_OBJECT('field','created_at','title','创建时间','width',180,'sort',true)
 ),
 20,50000,1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

INSERT INTO `report_filter` (`report_code`,`param_name`,`label`,`form_type`,`operator`,`expr`,`required`,`sort`,`enabled`)
VALUES
('user_list','username','用户名','text','like','u.username',0,10,1),
('user_list','created','创建日期','daterange','between','DATE(u.created_at)',0,20,1)
;

