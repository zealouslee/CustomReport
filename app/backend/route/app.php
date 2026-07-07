<?php
use think\facade\Route;

// 登录路由（白名单，不需要认证）
Route::get('login', 'Login/login');
Route::post('doLogin', 'Login/doLogin');
Route::post('logout', 'Login/logout');
Route::get('api/userInfo', 'Login/userInfo');
Route::post('api/changePassword', 'Login/changePassword');

// 报表路由
Route::get('report$', 'Report/list');
Route::get('report/:code', 'Report/index');

// 报表 API 路由
Route::get('api/report/config', 'ReportApi/config');
Route::get('api/report/data', 'ReportApi/data');
Route::get('api/report/export', 'ReportApi/export');
Route::get('api/report/filterOptions', 'ReportApi/filterOptions');
