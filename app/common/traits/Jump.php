<?php

namespace app\common\traits;

use think\exception\HttpResponseException;
use think\Response;

trait Jump
{
    protected $request;

    protected function initJump(): void
    {
        $this->request = request();
    }

    protected function success($msg = '', string $url = null, $data = '', int $wait = 3, array $header = [])
    {
        if (is_null($url) && isset($_SERVER['HTTP_REFERER'])) {
            $url = $_SERVER['HTTP_REFERER'];
        } elseif ($url) {
            $url = (strpos($url, '://')) || 0 == strpos($url, '/') ? $url : (string)app()->route->buildUrl($url);
        }

        $result = [
            'code' => 1,
            'msg' => $msg,
            'data' => $data,
            'url' => $url,
            'wait' => $wait
        ];
        $header['__token__'] = $this->request->buildToken();
        $type = $this->getResponseType();
        if ('html' == $type) {
            $type = 'view';
            $response = Response::create(app('config')->get('app.dispatch_success_tmpl'), $type)->assign($result)->header($header);
        } else {
            $response = Response::create($result, $type)->header($header);
        }
        throw  new HttpResponseException($response);
    }

    protected function error($msg = '', string $url = '', $data = '', int $wait = 3, array $heard = [])
    {
        if (is_null($url)) {
            $url = $this->request->isAjax() ? '' : 'javascript:history.back(-1);';
        } elseif ($url) {
            $url = (strpos($url, "://") || 0 === strpos($url, "/")) ? $url : (string)app()->route->buildUrl($url);
        }
        $result = [
            'code' => 0,
            'msg' => $msg,
            'data' => $data,
            'url' => $url,
            'wait' => $wait
        ];
        $header['__token__'] = $this->request->buildToken();
        $type = $this->getResponseType();
        if ('html' == $type) {
            $type = 'view';
            $response = Response::create(app('config')->get('app.dispatch_error_tmpl'), $type)->assign($result)->header($header);
        } else {
            $response = Response::create($result, $type)->header($header);
        }
        throw  new HttpResponseException($response);
    }

    protected function getResponseType(): string
    {
        return $this->request->isJson() || $this->request->isAjax() ? 'json' : 'html';
    }

    protected function result($data = '', $code = 0, $msg = '', $type = '', array $header = [])
    {
        $result = [
            'code' => $code,
            'msg' => $msg,
            'time' => time(),
            'data' => $data
        ];
        $header['__token__'] = $this->request->buildToken();
        $type = $type ?: $this->getResponseType();
        $response = Response::create($result, $type)->header($header);

        throw new HttpResponseException($response);
    }

    protected function redirect($url, $code = 302, $with = [])
    {
        $response = Response::create($url, 'redirect');

        $response->code($code)->with($with);

        throw new HttpResponseException($response);
    }
}