<?php

class ApsLog{

    private static $instance = null;

    private function __construct(){

    }

    public static function getInstance($class_name){
        if (self::$instance == null){
            self::$instance = new ApsLog();
        }
        return self::$instance;
    }

    public function log($string){

    }
}