<?php
declare(strict_types=1);

namespace app\backend\controller;

use app\common\controller\Backend;
use think\facade\View;

class Report extends Backend
{
    public function index(string $code = '')
    {
        View::assign('code', $code);
        return view('report/index');
    }
}

