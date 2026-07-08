-- SQL Server 报表建表脚本
-- 适用: SQL Server 2016+

-- =====================================================
-- 1. 报表定义表
-- =====================================================
IF OBJECT_ID('dbo.report_def', 'U') IS NOT NULL
    DROP TABLE dbo.report_def;

CREATE TABLE dbo.report_def (
    id            INT            NOT NULL IDENTITY(1,1),
    code          NVARCHAR(64)   NOT NULL,
    name          NVARCHAR(128)  NOT NULL,
    sql_text      NVARCHAR(MAX)  NOT NULL,
    cols_json     NVARCHAR(MAX)  NULL,
    default_limit INT            NOT NULL DEFAULT 20,
    max_export_rows INT          NOT NULL DEFAULT 50000,
    enabled       TINYINT        NOT NULL DEFAULT 1,
    created_at    DATETIME2      NULL,
    updated_at    DATETIME2      NULL,
    CONSTRAINT PK_report_def PRIMARY KEY (id),
    CONSTRAINT UQ_report_def_code UNIQUE (code)
);

-- =====================================================
-- 2. 报表查询条件表
-- =====================================================
IF OBJECT_ID('dbo.report_filter', 'U') IS NOT NULL
    DROP TABLE dbo.report_filter;

CREATE TABLE dbo.report_filter (
    id             INT            NOT NULL IDENTITY(1,1),
    report_code    NVARCHAR(64)   NOT NULL,
    param_name     NVARCHAR(64)   NOT NULL,
    label          NVARCHAR(128)  NOT NULL,
    form_type      NVARCHAR(32)   NOT NULL DEFAULT 'text',
    operator       NVARCHAR(16)   NOT NULL DEFAULT '=',
    expr           NVARCHAR(255)  NOT NULL,
    required       TINYINT        NOT NULL DEFAULT 0,
    default_value  NVARCHAR(255)  NULL,
    options_json   NVARCHAR(MAX)  NULL,
    options_sql    NVARCHAR(MAX)  NULL,
    depends_on     NVARCHAR(64)   NULL,
    sort           INT            NOT NULL DEFAULT 100,
    enabled        TINYINT        NOT NULL DEFAULT 1,
    CONSTRAINT PK_report_filter PRIMARY KEY (id)
);

CREATE INDEX IX_report_filter_report_code ON dbo.report_filter (report_code);

-- =====================================================
-- 3. 插入数据（保留原 ID）
-- =====================================================
SET IDENTITY_INSERT dbo.report_def ON;

INSERT INTO dbo.report_def (id, code, name, sql_text, cols_json, default_limit, max_export_rows, enabled, created_at, updated_at)
VALUES
(1, 'user_list', '用户列表',
 N'SELECT a.id,a.username,a.email,a.create_time
                  FROM fun_admin a
                  WHERE 1=1 {{where}}
                  ORDER BY a.id DESC',
 N'[{"sort": true, "field": "id", "title": "ID", "width": 90}, {"field": "username", "title": "用户名", "width": 160}, {"field": "email", "title": "邮箱", "width": 220}, {"sort": true, "field": "create_time", "title": "创建时间", "width": 180}]',
 20, 50000, 1, NULL, NULL),
(2, 'category_list', '菜单列表',
 N'SELECT catename,cateflag,title,keywords FROM fun_addons_cms_category WHERE 1=1 {{where}}  ORDER BY id DESC',
 N'[{"sort": true, "field": "catename", "title": "catename", "width": 90}, {"field": "cateflag", "title": "cateflag", "width": 160}, {"field": "title", "title": "title", "width": 220}, {"sort": true, "field": "keywords", "title": "keywords", "width": 180}]',
 20, 50000, 1, NULL, NULL);

SET IDENTITY_INSERT dbo.report_def OFF;

SET IDENTITY_INSERT dbo.report_filter ON;

INSERT INTO dbo.report_filter (id, report_code, param_name, label, form_type, operator, expr, required, default_value, options_json, options_sql, sort, enabled)
VALUES
(1, 'user_list', 'username', '用户名', 'text', 'like', 'a.username', 0, NULL, NULL, NULL, 10, 1),
(5, 'user_list', 'field_type', '字段类型', 'select', '=', 'u.field_type', 0, NULL, NULL, N'SELECT name AS value, name AS label FROM fun_field_type', 15, 1),
(2, 'user_list', 'created', '创建日期', 'daterange', 'between', N'DATE(FROM_UNIXTIME(a.create_time))', 0, NULL, NULL, NULL, 20, 1);

SET IDENTITY_INSERT dbo.report_filter OFF;

-- =====================================================
-- 报表：体检人员弃检项目列表
-- =====================================================
INSERT INTO dbo.report_def (code, name, sql_text, cols_json, default_limit, max_export_rows, enabled, created_at, updated_at)
VALUES (N'give_up_items', N'体检人员弃检项目列表',
 N'SELECT pp.ID_Patient, pp.PatientCode, pp.PatientName, pp.Org_Name, pp.Sex, pp.Age, pped.Depart_Name_R, ppfi.ExamFeeItem_Name, ppfi.Price, ppfi.FactPrice, pp.DateRegister, ppfi.F_GiveUp, ppfi.DateGiveUp, ppfi.ExamDoctor_Name_R, ppfi.GiveUpNotelet FROM PeisPatient pp JOIN PeisPatientExamDepart pped ON pp.ID_Patient = pped.ID_Patient JOIN PeisPatientFeeItem ppfi ON ppfi.ID_PatientExamDepart = pped.ID_PatientExamDepart WHERE ppfi.F_GiveUp = 1 {{where}} ORDER BY pp.ID_Patient DESC',
 N'[{"field":"PatientCode","title":"体检号","width":120},{"field":"PatientName","title":"姓名","width":100},{"field":"Sex","title":"性别","width":60},{"field":"Age","title":"年龄","width":60},{"field":"Org_Name","title":"单位名称","width":180},{"field":"Depart_Name_R","title":"科室","width":120},{"field":"ExamFeeItem_Name","title":"弃检项目","width":180},{"field":"Price","title":"价格","width":80},{"field":"FactPrice","title":"实收金额","width":80},{"field":"DateRegister","title":"登记日期","width":160,"sort":true},{"field":"DateGiveUp","title":"弃检日期","width":160,"sort":true},{"field":"ExamDoctor_Name_R","title":"弃检医生","width":100},{"field":"GiveUpNotelet","title":"弃检备注","width":200}]',
 20, 50000, 1, NULL, NULL);

INSERT INTO dbo.report_filter (report_code, param_name, label, form_type, operator, expr, required, default_value, options_json, options_sql, sort, enabled)
VALUES
(N'give_up_items', N'Org_Name', N'单位名称', N'select', N'=', N'pp.ID_Org', 0, NULL, NULL, N'SELECT ID_Org AS value, Org_Name AS label FROM PeisOrg ORDER BY Org_Name', 5, 1),
(N'give_up_items', N'ID_OrgReservation', N'单位任务', N'select', N'=', N'ppfi.ID_OrgReservation', 0, NULL, NULL, N'SELECT ID_OrgReservation AS value, OrgReservationName AS label, ID_Org FROM PeisOrgReservation ORDER BY OrgReservationName', N'org_id', 7, 1),
(N'give_up_items', N'ExamFeeItem_Name', N'收费项目', N'select', N'=', N'ppfi.ExamFeeItem_Name', 0, NULL, NULL, N'SELECT DISTINCT ExamFeeItem_Name AS value, ExamFeeItem_Name AS label FROM PeisPatientFeeItem WHERE ExamFeeItem_Name IS NOT NULL ORDER BY ExamFeeItem_Name', 8, 1),
(N'give_up_items', N'PatientCode', N'体检号', N'text', N'like', N'pp.PatientCode', 0, NULL, NULL, NULL, 10, 1),
(N'give_up_items', N'DateRegister', N'登记时间', N'daterange', N'between', N'pp.DateRegister', 0, NULL, NULL, NULL, 20, 1);

-- =====================================================
-- 报表：体检人员列表（peis_patient_list）
-- =====================================================
INSERT INTO dbo.report_def (code, name, sql_text, cols_json, default_limit, max_export_rows, enabled, created_at, updated_at)
VALUES (N'peis_patient_list', N'体检人员列表',
 N'SELECT
       pp.id_patient,
       pp.PatientCardNo,
       pp.PatientCode,
       pp.PatientName,
       pp.Org_Name,
       pp.Sex,
       pp.Age,
       pp.Marriage,
       pp.Phone,
       pp.F_Registered,
       pp.DateRegister,
       pp.DoctorReg,
       pp.BirthDate,
       pp.F_FeeCharged,
       pp.DateFee,
       pp.F_ExamStarted,
       pp.DateExamStart,
       pp.F_Paused,
       pp.ID_ExamSuite,
       pp.ExamSuite_Name,
       CASE
           WHEN F_GuidanceReturned = 0 THEN NULL
           ELSE ''已回收''
       END AS F_GuidanceReturned,
       sqep.ExamStatus_Name,
       pp.IDCardNo,
       pp.DatePrepare,
       porg.OffPercent
FROM   PeisPatient pp
       LEFT JOIN PeisOrgReservationGroup porg
            ON  porg.ID_OrgReservationGroup = pp.ID_OrgReservationGroup
       LEFT JOIN Sys_Quality_ExamProcess sqep ON sqep.Exam_Status = pp.Exam_Status
WHERE 1=1 {{where}}
ORDER BY
       DateRegister DESC, pp.DateFee',
 N'[{"field":"id_patient","title":"体检ID","width":130},{"field":"PatientCode","title":"体检编号","width":130},{"field":"PatientName","title":"姓名","width":100},{"field":"Sex","title":"性别","width":60},{"field":"Age","title":"年龄","width":60},{"field":"IDCardNo","title":"身份证号","width":180},{"field":"Phone","title":"手机号","width":130},{"field":"Marriage","title":"婚姻状况","width":90},{"field":"Org_Name","title":"单位名称","width":180},{"field":"ExamSuite_Name","title":"体检套餐","width":150},{"field":"DateRegister","title":"登记日期","width":110,"sort":true},{"field":"DoctorReg","title":"登记医生","width":100},{"field":"DateFee","title":"缴费日期","width":110,"sort":true},{"field":"DateExamStart","title":"检查开始日期","width":130},{"field":"ExamStatus_Name","title":"检查状态","width":100},{"field":"DatePrepare","title":"预约日期","width":100},{"field":"F_GuidanceReturned","title":"指引单回收","width":100,"bit":true},{"field":"OffPercent","title":"折扣比例","width":80}]',
 20, 50000, 1, NULL, NULL);

INSERT INTO dbo.report_filter (report_code, param_name, label, form_type, operator, expr, required, default_value, options_json, options_sql, sort, enabled)
VALUES
(N'peis_patient_list', N'patient_name', N'姓名', N'text', N'like', N'pp.PatientName', 0, NULL, NULL, NULL, 1, 1),
(N'peis_patient_list', N'phone', N'手机号', N'text', N'like', N'pp.Phone', 0, NULL, NULL, NULL, 2, 1),
(N'peis_patient_list', N'patient_code', N'体检编号', N'text', N'like', N'pp.PatientCode', 0, NULL, NULL, NULL, 3, 1),
(N'peis_patient_list', N'idcard_no', N'身份证号', N'text', N'like', N'pp.IDCardNo', 0, NULL, NULL, NULL, 4, 1),
(N'peis_patient_list', N'org_id', N'所属单位', N'select', N'=', N'pp.ID_Org', 0, NULL, NULL, N'SELECT ID_Org AS value, Org_Name AS label FROM PeisOrg ORDER BY Org_Name', 5, 1),
(N'peis_patient_list', N'ID_OrgReservation', N'单位任务', N'select', N'=', N'pp.ID_OrgReservation', 0, NULL, NULL, N'SELECT ID_OrgReservation AS value, OrgReservationName AS label, ID_Org FROM PeisOrgReservation ORDER BY OrgReservationName', N'org_id', 6, 1),
(N'peis_patient_list', N'date_register', N'登记日期', N'daterange', N'between', N'pp.DateRegister', 0, NULL, NULL, NULL, 7, 1);

-- =====================================================
-- 报表：质控指标监测表（quality_monitor）
-- =====================================================
INSERT INTO dbo.report_def (code, name, sql_text, cols_json, default_limit, max_export_rows, enabled, created_at, updated_at)
VALUES (N'quality_monitor', N'质控指标监测表',
 N'SELECT
    MONTH(pp.DateRegister) AS 月份,
    COUNT(DISTINCT pp.ID_Patient) AS 总人次,
    COUNT(DISTINCT CASE WHEN pp.ID_Sex = 1 AND pp.ID_Org IS NOT NULL THEN pp.ID_Patient END) AS 团体男_人数,
    COUNT(DISTINCT CASE WHEN pp.ID_Sex = 2 AND pp.ID_Org IS NOT NULL THEN pp.ID_Patient END) AS 团体女_人数,
    COUNT(DISTINCT CASE WHEN pp.ID_Sex = 1 AND pp.ID_Org IS NULL THEN pp.ID_Patient END) AS 个人男_人数,
    COUNT(DISTINCT CASE WHEN pp.ID_Sex = 2 AND pp.ID_Org IS NULL THEN pp.ID_Patient END) AS 个人女_人数,
    SUM(CASE WHEN pp.ID_Org IS NOT NULL THEN ppfi.FactPrice ELSE 0 END) AS 团体_金额,
    SUM(CASE WHEN pp.ID_Org IS NULL THEN ppfi.FactPrice ELSE 0 END) AS 个人_金额
FROM PeisPatient pp
JOIN PeisPatientFeeItem ppfi ON pp.ID_Patient = ppfi.ID_Patient
WHERE ppfi.F_FeeCharged = 1 {{where}}
GROUP BY MONTH(pp.DateRegister)
ORDER BY 月份',
 N'[{"field":"月份","title":"月份","width":80},{"field":"总人次","title":"总人次","width":90},{"field":"团体男_人数","title":"团体男","width":90},{"field":"团体女_人数","title":"团体女","width":90},{"field":"个人男_人数","title":"个人男","width":90},{"field":"个人女_人数","title":"个人女","width":90},{"field":"团体_金额","title":"团体金额","width":100},{"field":"个人_金额","title":"个人金额","width":100}]',
 20, 50000, 1, NULL, NULL);

INSERT INTO dbo.report_filter (report_code, param_name, label, form_type, operator, expr, required, default_value, options_json, options_sql, sort, enabled)
VALUES (N'quality_monitor', N'date_register', N'登记时间', N'daterange', N'between', N'pp.DateRegister', 0, NULL, NULL, NULL, 10, 1);

-- =====================================================
-- 报表：重大阳性表（positive_findings）
-- =====================================================
INSERT INTO dbo.report_def (code, name, sql_text, cols_json, default_limit, max_export_rows, enabled, created_at, updated_at)
VALUES (N'positive_findings', N'重大阳性表',
 N'SELECT pp.ID_Patient AS 体检号,
       pp.PatientName AS 姓名,
       pp.Age AS 年龄,
       pp.Sex AS 性别,
       pp.Phone AS 电话,
       pp.IDCardNo AS 身份证,
       pp.Org_Name AS 团体名称,
       pp.Org_Depart AS 部门,
       pp.DateRegister AS 体检时间,
       ppfi.ExamFeeItem_Name AS 收费项目名称,
       ppei.ExamItem_Name_R AS 检查项目名称,
       ppei.ExamItemValues AS 体征,
       ppei.ExamItemValuesText AS 描述,
       ppei.ExamItemValuesShort AS 结果_文本,
       ppei.ExamItemValuesNumber AS 结果_数值,
       ppei.RefRange AS 参考范围,
       ppei.LabItemFlag AS 标志,
       ppei.Note AS 备注,
       ppei.SevereDegreeAB AS AB类,
       pped.DepartSummary AS 小结
FROM PeisPatientExamItem AS ppei
    JOIN PeisPatientFeeItem AS ppfi
        ON ppei.ID_PatientFeeItem = ppfi.ID_PatientFeeItem
    JOIN PeisPatientExamDepart AS pped
        ON ppei.ID_PatientExamDepart = pped.ID_PatientExamDepart
    LEFT JOIN PeisPatient pp
        ON pped.ID_Patient = pp.ID_Patient
WHERE (ppei.SevereDegreeAB IS NOT NULL AND ppei.SevereDegreeAB <> CHAR(39)+CHAR(39)) {{where}}
ORDER BY pp.ID_Patient',
 N'[{"field":"体检号","title":"体检号","width":100},{"field":"姓名","title":"姓名","width":90},{"field":"年龄","title":"年龄","width":60},{"field":"性别","title":"性别","width":60},{"field":"电话","title":"电话","width":130},{"field":"身份证","title":"身份证","width":180},{"field":"团体名称","title":"团体名称","width":160},{"field":"部门","title":"部门","width":120},{"field":"体检时间","title":"体检时间","width":110},{"field":"收费项目名称","title":"收费项目","width":140},{"field":"检查项目名称","title":"检查项目","width":160},{"field":"体征","title":"体征","width":100},{"field":"描述","title":"描述","width":200},{"field":"结果_文本","title":"结果(文本)","width":120},{"field":"结果_数值","title":"结果(数值)","width":100},{"field":"参考范围","title":"参考范围","width":120},{"field":"标志","title":"标志","width":60,"highlight":[{"match":["H","↑"],"color":"red"},{"match":["L","↓"],"color":"blue"}]},{"field":"备注","title":"备注","width":200},{"field":"AB类","title":"AB类","width":70,"highlight":[{"match":["A"],"color":"red"},{"match":["B"],"color":"orange"}]},{"field":"小结","title":"小结","width":250}]',
 20, 50000, 1, NULL, NULL);

INSERT INTO dbo.report_filter (report_code, param_name, label, form_type, operator, expr, required, default_value, options_json, options_sql, sort, enabled)
VALUES (N'positive_findings', N'exam_time', N'检查时间', N'daterange', N'between', N'PPED.EXAMAPPROVETIME', 0, NULL, NULL, NULL, 10, 1);

-- =====================================================
-- 报表：职业总检工作量报表（occ_final_workload）
-- =====================================================
INSERT INTO dbo.report_def (code, name, sql_text, cols_json, default_limit, max_export_rows, enabled, created_at, updated_at)
VALUES (N'occ_final_workload', N'职业总检工作量报表',
 N'SELECT MONTH(ppoc.OccFinalExamTime) AS 月份,
       COUNT(ppoc.ID_OccFinalExamDoc) AS 数量,
       hu.Name AS 总检医生
FROM PeisPatientOccuConclusion ppoc
LEFT JOIN HerUser hu ON hu.ID_User = ppoc.ID_OccFinalExamDoc
WHERE 1=1 {{where}}
GROUP BY hu.Name, MONTH(ppoc.OccFinalExamTime)
ORDER BY 月份',
 N'[{"field":"月份","title":"月份","width":80,"sort":true},{"field":"数量","title":"数量","width":80,"sort":true},{"field":"总检医生","title":"总检医生","width":120}]',
 20, 50000, 1, NULL, NULL);

INSERT INTO dbo.report_filter (report_code, param_name, label, form_type, operator, expr, required, default_value, options_json, options_sql, sort, enabled)
VALUES (N'occ_final_workload', N'final_time', N'总检时间', N'daterange', N'between', N'ppoc.OccFinalExamTime', 0, NULL, NULL, NULL, 10, 1);