<?php
declare(strict_types=1);

namespace app\backend\controller;

use app\common\controller\Backend;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

class Report extends Backend
{
    public function list()
    {
        // 生成 CSRF token
        if (!Session::has('__token__')) {
            Session::set('__token__', md5((string)microtime(true)));
        }

        $reports = Db::name('report_def')
            ->where('enabled', 1)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        View::assign('reports', $reports);
        return view('report/list');
    }

    public function index(string $code = '')
    {
        // 生成 CSRF token
        if (!Session::has('__token__')) {
            Session::set('__token__', md5((string)microtime(true)));
        }

        View::assign('code', $code);
        return view('report/index');
    }
}

