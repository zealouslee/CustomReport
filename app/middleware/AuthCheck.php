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
                // 白名单路由也需校验 CSRF（POST 请求）
                if ($request->isPost()) {
                    $csrfResult = $this->checkCsrf($request);
                    if ($csrfResult !== null) return $csrfResult;
                }
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

        // 所有 POST/PUT/DELETE 请求校验 CSRF token
        if ($request->isPost() || $request->method() === 'PUT' || $request->method() === 'DELETE') {
            $csrfResult = $this->checkCsrf($request);
            if ($csrfResult !== null) return $csrfResult;
        }

        return $next($request)->header([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'SAMEORIGIN',
            'X-XSS-Protection'       => '1; mode=block',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        ]);
    }

    /**
     * 校验 CSRF token，通过返回 null，失败返回 JSON 响应
     */
    private function checkCsrf(Request $request)
    {
        $token = $request->post('__token__')
            ?: $request->header('X-CSRF-TOKEN', '');

        if (empty($token)) {
            return json(['code' => -1, 'msg' => '安全验证失败，请刷新页面重试']);
        }

        $sessionToken = Session::get('__token__');
        if (empty($sessionToken) || !hash_equals($sessionToken, $token)) {
            // 验证失败，刷新 token 防止重放
            Session::set('__token__', md5((string)microtime(true)));
            return json(['code' => -1, 'msg' => '安全验证失败，请刷新页面重试']);
        }

        // 验证通过，token 保持有效直到页面刷新
        return null;
    }
}
