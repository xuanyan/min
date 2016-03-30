<?php

namespace Min;

abstract class Singleton
{
    protected static $instance = array();

    public static function getInstance()
    {
        $class = get_called_class();
        if (!isset(self::$instance[$class])) {
            self::$instance[$class] = new static();
        }

        return self::$instance[$class];
        // if (!static::$instance instanceof static) {
        //     
        //     echo get_called_class();
        //     echo '<br>';
        //     static::$instance = new static();
        // }
        // 
        // return static::$instance;
    }
}