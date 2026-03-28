<?php namespace EqemuLogin;

use XF;
use XF\Db\Mysqli\Adapter;

class LoginServerDb {

    private static $instance = null;

    private $connection;

    private function __construct()
    {
        $loginServerDb = XF::app()->config('login_server_db');
        $this->connection = new Adapter([
            'host' => $loginServerDb['host'],
            'port' => $loginServerDb['port'],
            'username' => $loginServerDb['username'],
            'password' => $loginServerDb['password'],
            'dbname' => $loginServerDb['dbname']
        ], true);
    }

    public static function getInstance()
    {
        if (null == self::$instance)
        {
            self::$instance = new LoginServerDb();
        }

        return self::$instance;
    }

    public function getConnection()
    {
        return $this->connection;
    }
}

