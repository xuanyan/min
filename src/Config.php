<?php

namespace Min;

class Config extends Singleton
{
    const API_URL = 'http://configuration.sinaapp.com/index.php?domain=%s';
    private $_config = null;
    private $rootPath = null;
	const PUBLIC_KEY = '-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDNa3wbn1e3izdjQr5vLifIkET7
C9hNaaZxyU8/mMqYSqkLseq+JNgSrKzTNV3yiY71h2MZQEhz3NSDdlNL4DA60fk+
a+MzTkPRiGYTaDMOjoQiygPbKSJnBeyx9iZfpqJMXONbWt0LII16L5dOaWi9DXt8
5rSPpGhDOUyOjIKsjwIDAQAB
-----END PUBLIC KEY-----';

    private function init()
    {
        if ($this->_config !== null) {
            return true;
        }

        if ($this->rootPath !== null) {
            if (file_exists($this->rootPath . '/config.php' )) {
                $this->_config = require_once $this->rootPath . '/config.php';

                return true;
            }
        }

        //$domain = $_SERVER['HTTP_HOST'];

        $domain = 'ostom.jx-ks.com';

        $file = sys_get_temp_dir() . '/' . $domain . '.php';
        if (!file_exists($file) || (time() - filemtime($file)) > 60 || 0) {
			
			$publicKey = openssl_pkey_get_public(self::PUBLIC_KEY);//这个函数可用来判断公钥是否是可用的  

			openssl_public_encrypt($domain, $encrypted, $publicKey);//公钥加密  
			$encrypted = urlencode(base64_encode($encrypted));  
			// echo sprintf(self::API_URL, $encrypted);exit;
            $config = file_get_contents(sprintf(self::API_URL, $encrypted));

            $config = json_decode($config, true);
            foreach ($config as &$value) {
    			openssl_public_decrypt(base64_decode($value), $value, self::PUBLIC_KEY);//私钥加密的内容通过公钥可用解密出来
            }
            
            $this->_config = json_decode(implode('', $config), true);

            if (empty($this->_config)) {
                throw new ConfigException("cant load config", 2);
            }

            file_put_contents($file, "<?php\n return " . var_export($this->_config, true) . ';');
        } else {
            $this->_config = require_once $file;
        }

        return true;
    }

    public function setRootPath($rootPath)
    {
        $this->rootPath = $rootPath;
    }

    // private static function strcode($string, $salt = 'whateveryouwant')
    // {
    //     $key = md5($salt);
    //     $keylen = strlen($key);
    //     $strlen = strlen($string);
    //     $code = '';
    //
    //     for ($i = 0; $i < $strlen; $i ++) {
    //         //echo $i;
    //         $k = $i % $keylen; //余数  将字符全部位移
    //         $code .= $string[$i] ^ $key[$k];//位移
    //     }
    //
    //     return $code;
    // }
    /**
     * 获取config
     *
     * @param string config key 值
     * @return mix
     * @author xuanyan
     */
    public function __get($name)
    {
        $this->init();

        return isset($this->_config[$name]) ? $this->_config[$name] : '';
    }
}

class ConfigException extends \Exception
{
    
}