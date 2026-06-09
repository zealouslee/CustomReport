<?php
declare(strict_types=1);

namespace app\backend\controller;

use app\common\controller\Backend;
use think\facade\Db;
use think\facade\View;

class Report extends Backend
{
    public function list()
    {
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
        View::assign('code', $code);
        return view('report/index');
    }
}

