<?php

namespace Min;

/*
* Router
*
* @copyright (c) 2012 Atom Projects More info http://geek-zoo.com
* @license http://opensource.org/licenses/gpl-2.0.php The GNU General Public License
* @author xuanyan <xuanyan@geek-zoo.com>
*
*/

class Router
{
    private $controllerDir = '';
    private $delimiter = '/';

    public $format = '';
    public $controller = 'index';
    public $action = 'index';
    public $url = '';

    public $controllerObj = null;
    public $controllerName = '%sController';
    public $actionName = '%sAction';

    function __construct($controllerDir = 'Controllers')
    {
        $this->controllerDir = $controllerDir;
    }

    private static function getValue($value, $default)
    {
        if (is_string($default)) {
            return trim($value);
        }
        if (is_int($default)) {
            return intval($value);
        }
        if (is_array($default)) {
            return (array)$value;
        }

        return floatval($value);
    }

    /**
     * dispatch url function
     *
     * @param string $url
     * @return mix
     */
    public function run($url = null)
    {
        if (!isset($url)) {
            $url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        }

        $url = $raw_url = trim($url, ' '.$this->delimiter);
        // trim the url extention (xxx/xxx.html or yyy/yyy.asp or any extention)
        if (($pos = strrpos($url, '.')) !== false) {
            $this->format = substr($url, $pos+1);
            $url = substr($url, 0, $pos);
        }

        $this->url = $url;

        $tmp = $url ? array_filter(explode($this->delimiter, $url)) : array();

        $path = $this->controllerDir;

        $count = count($tmp);
        $nameSpace = '\\';
        for ($i = 0; $i < $count; $i++) {
            if (!is_dir($path.'/'.$tmp[$i])) {
                break;
            }
            $path .= '/'.$tmp[$i];
            $nameSpace .= $tmp[$i].'\\';
        }

        if (isset($tmp[$i])) {
            $this->controller = $tmp[$i];
            $i++;
            if (isset($tmp[$i])) {
                $this->action = $tmp[$i];
                $i++;
            }
        }

        if (!$file = realpath($path.'/'.$this->controller.'.php')) {
            throw new RouterException("Controller not exists: {$this->controller}", 404);
        }
        $className = $nameSpace.sprintf($this->controllerName, $this->controller);

        if (!class_exists($className, false)) {
            if (strpos($file, $this->controllerDir) !== 0) {
                throw new RouterException("no permission to access: $file", 403);
            } else {
                include $file;
            }
        }

        $i && $tmp = array_slice($tmp, $i);

        $class = new $className($this);

        $this->controllerObj = $class;

        $actionName = sprintf($this->actionName, $this->action);

        try {
            $method = new \ReflectionMethod($class, $actionName);
            if ($method->getNumberOfParameters() > 0) {
                $ps = array();
                foreach($method->getParameters() as $i => $val)
                {
                    $name = $val->getName();
                    $default = $val->isDefaultValueAvailable() ? $val->getDefaultValue() : '';
                    if (isset($tmp[$i])) {
                        $ps[] = self::getValue($tmp[$i], $default);
                    } elseif (isset($_GET[$name])) {
                        $ps[] = self::getValue($_GET[$name], $default);
                    } else {
                        $ps[] = $default;
                    }
                }
                return $method->invokeArgs($class, $ps);
            }
        } catch (\Exception $e) {
            throw $e;
        }

        $doAction = array($class, $actionName);

        if (!is_callable($doAction, false)) {
            throw new RouterException("Action not exists: {$this->action}: $file", 500);
        }

        return call_user_func_array($doAction, $tmp);
    }
}


class RouterException extends \Exception
{

}