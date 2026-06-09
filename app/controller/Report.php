<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\View;

class Report extends BaseController
{
    public function index(string $code = '')
    {
        View::assign('code', $code);
        // 直接复用 backend 模块的视图
        return view('backend@report/index');
    }
}

