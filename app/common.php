<?php
// 应用公共文件

if(!function_exists('password')){
    /**
     * 密码
     * @param $password
     * @return string
     */
    function password($password,$typ=PASSWORD_DEFAULT)
    {
        return password_hash($password, $typ);
    }

}