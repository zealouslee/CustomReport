<?php

namespace app\common\controller;

use app\BaseController;
use app\common\traits\Jump;
use think\App;

class Backend extends  BaseController
{
    use  Jump;
    protected $modelClass;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->initJump();
    }

    public function index()
    {

    }
}