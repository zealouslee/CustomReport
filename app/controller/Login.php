<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

class Login extends BaseController
{
    public function login()
    {
        // 如果已经登录，直接跳转到报表页
        if (session('?admin_id')) {
            return redirect('/backend/report/user_list');
        }
        return view('backend@login/index');
    }

    public function doLogin()
    {
        $username = (string)$this->request->post('username', '');
        $password = (string)$this->request->post('password', '');

        if (empty($username) || empty($password)) {
            return json(['code' => -1, 'msg' => '用户名或密码不能为空']);
        }

        $admin = Db::name('fun_admin')
            ->where('username', $username)
            ->where('status', 1)
            ->find();

        if (!$admin) {
            return json(['code' => -1, 'msg' => '用户名或密码错误']);
        }

        if (!password_verify($password, $admin['password'])) {
            return json(['code' => -1, 'msg' => '用户名或密码错误']);
        }

        // 登录成功，写入 session
        session('admin_id', (int)$admin['id']);
        session('admin_username', $admin['username']);
        session('admin_realname', $admin['realname'] ?? '');
        session('admin_avatar', $admin['avatar'] ?? '');

        return json(['code' => 0, 'msg' => '登录成功']);
    }

    public function logout()
    {
        // 清除 session
        Session::delete('admin_id');
        Session::delete('admin_username');
        Session::delete('admin_realname');
        Session::delete('admin_avatar');

        return redirect('/backend/login');
    }
}
