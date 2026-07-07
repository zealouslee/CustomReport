<?php

namespace app\backend\controller;

use app\BaseController;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

class Login extends BaseController
{
    public function login()
    {
        // 如果已经登录，直接跳转到报表页
        if (Session::has('admin_id')) {
            return redirect('/backend/report');
        }
        // 生成 CSRF token
        if (!Session::has('__token__')) {
            Session::set('__token__', md5((string)microtime(true)));
        }
        return view('login/index');
    }

    public function doLogin()
    {
        $username = (string)$this->request->post('username', '');
        $password = (string)$this->request->post('password', '');

        if (empty($username) || empty($password)) {
            return json(['code' => -1, 'msg' => '用户名或密码不能为空']);
        }

        // 登录频率限制：同一 IP 每分钟最多 10 次，同一用户名每分钟最多 5 次
        $ip = $this->request->ip();
        $ipKey = 'login_ip_' . md5($ip);
        $userKey = 'login_user_' . md5($username);
        $ipCount = (int)Cache::get($ipKey, 0);
        $userCount = (int)Cache::get($userKey, 0);

        if ($ipCount >= 10) {
            return json(['code' => -1, 'msg' => '登录尝试过于频繁，请1分钟后再试']);
        }
        if ($userCount >= 5) {
            return json(['code' => -1, 'msg' => '该账号登录尝试过于频繁，请1分钟后再试']);
        }

        Cache::set($ipKey, $ipCount + 1, 60);
        Cache::set($userKey, $userCount + 1, 60);

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
        Session::set('admin_id', (int)$admin['id']);
        Session::set('admin_username', $admin['username']);
        Session::set('admin_realname', $admin['realname'] ?? '');
        Session::set('admin_avatar', $admin['avatar'] ?? '');

        // 登录成功，清除频率限制计数
        Cache::delete($ipKey);
        Cache::delete($userKey);

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

    public function userInfo()
    {
        if (!Session::has('admin_id')) {
            return json(['code' => -1, 'msg' => '未登录']);
        }
        return json(['code' => 0, 'data' => [
            'id'        => Session::get('admin_id'),
            'username'  => Session::get('admin_username'),
            'realname'  => Session::get('admin_realname'),
            'avatar'    => Session::get('admin_avatar'),
        ]]);
    }

    public function changePassword()
    {
        if (!Session::has('admin_id')) {
            return json(['code' => -1, 'msg' => '未登录']);
        }

        $oldPwd = (string)$this->request->post('old_password', '');
        $newPwd = (string)$this->request->post('new_password', '');

        if (empty($oldPwd) || empty($newPwd)) {
            return json(['code' => -1, 'msg' => '旧密码和新密码不能为空']);
        }

        if (strlen($newPwd) < 6) {
            return json(['code' => -1, 'msg' => '新密码长度不能少于6位']);
        }

        $admin = Db::name('fun_admin')
            ->where('id', Session::get('admin_id'))
            ->find();

        if (!$admin) {
            return json(['code' => -1, 'msg' => '用户不存在']);
        }

        if (!password_verify($oldPwd, $admin['password'])) {
            return json(['code' => -1, 'msg' => '旧密码不正确']);
        }

        Db::name('fun_admin')
            ->where('id', $admin['id'])
            ->update(['password' => password_hash($newPwd, PASSWORD_DEFAULT)]);

        return json(['code' => 0, 'msg' => '密码修改成功，请重新登录']);
    }
}
