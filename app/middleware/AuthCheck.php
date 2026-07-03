<?php
declare(strict_types=1);

namespace app\middleware;

use think\facade\Session;
use think\Request;

class AuthCheck
{
    public function handle(Request $request, \Closure $next)
    {
        // 白名单路径：不需要认证（多应用模式，pathinfo 已去除模块前缀）
        $whitelist = ['login', 'doLogin'];
        $path = $request->pathinfo();
        // 兼容带后缀的路径
        $normalized = rtrim($path, '/');
        if ($normalized === '') {
            $normalized = '/';
        }

        foreach ($whitelist as $w) {
            if ($normalized === $w) {
                return $next($request);
            }
        }

        // 检查 session 是否已登录
        if (!Session::has('admin_id')) {
            // API 请求返回 JSON
            if ($request->isAjax() || $request->isJson() || str_contains($normalized, 'api/')) {
                return json(['code' => -1, 'msg' => '未登录或登录已过期'], 401);
            }
            // 页面请求跳转到登录页
            return redirect('/backend/login');
        }

        return $next($request);
    }
}
