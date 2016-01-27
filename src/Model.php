<?php

// Model::$config = array(
//     'db' => Database::$instance,
//     'cache' => $cache,
//     'path' => './model'
// );
// Model::load('user')


// $user = new Model('user');

// $user = new Model('user');
// 
// $user->read('WHERE id > 2');
// $user->field('id', 'name', 'value')->getAll();
// 
// $user->getRecord


class Model
{
    public static $config = array();

    protected $model = null;

    protected $table = null;

    function __construct($tableName)
    {
        $defaultConfig = array(
            'db' => null,
            'cache' => null,
            'path' => './model'
        );

        self::$config = array_merge($defaultConfig, self::$config);

        $file = self::$config['path']."/{$tableName}.php";

        $class = 'modelAbsract';

        if (file_exists($file)) {
            require $file;
            $class = "{$tableName}Model";
        }

        $this->table = '{{'.$tableName.'}}';
        $this->model = new $class(self::$config['db'], self::$config['cache'], $tableName);
    }

    public static function load($tableName)
    {
        static $models = array();
        if (!isset($models[$tableName])) {
            $models[$tableName] = new self($tableName);
        }

        return $models[$tableName];
    }

    function __get($key)
    {
        return $this->model->$key;
    }

    function __call($fun, $params = array())
    {
        return call_user_func_array(array($this->model, $fun), $params);
    }
}



class modelAbsract
{
    protected $db = null;
    protected $cache = null;
    protected $table = null;

    protected $cached = true;

    public $fields = array();
    protected $pri = null;

    protected function genKey()
    {
        $data = func_get_args();
        return md5(var_export($data, true));
    }

    protected function getSql($option)
    {
        $params = array();
        $where = '';
        $sqlAdd = '';
        //$where = array();
        foreach ($option as $key => $val) {
            if (is_numeric($key)) {
                $sqlAdd .= " {$val}";
                continue;
            }
            $add = $where ? 'AND' : '';
            if (!stripos($key, ' ')) {
                if (is_array($val)) {
                    $where .= " {$add} `{$key}` IN ('".implode("', '", $val)."')";
                    continue;
                }
                $where .= " {$add} `{$key}` = ?";
                $params[] = $val;
                continue;
            }

            $ck = explode(' ', $key);

            if (isset($ck[2])) {
                $where .= " {$ck[0]} `{$ck[1]}` {$ck[2]} ?";
                $params[] = $val;
                continue;
            }

            $where .= " {$add} `{$ck[0]}` {$ck[1]} ?";
            $params[] = $val;
        }
        
        $where && $where = "WHERE $where";

        return array($where.$sqlAdd, $params);
    }

    protected function needCache()
    {
        if ($this->cache !== null && $this->cached) {
            return true;
        }

        return false;
    }

    function __construct($db, $cache, $table)
    {
        $this->db = $db;
        $this->cache = $cache;
        $this->table = $table;

        $writeCache = false;
        
        if ($this->needCache()) {
            $key =$this->genKey('fields_', $this->table);
        
            $this->fields = $this->cache->get($key);

            $writeCache = true;
        }
        
        if (empty($this->fields)) {
            $database = $this->db->getOne("SELECT database()");
            $sql = "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '$this->table' AND TABLE_SCHEMA = '$database'";
            $data = $this->db->getAll($sql);

            $pri = null;
            foreach ($data as $val) {
                //  主键后插入数组
                if ($val['COLUMN_KEY'] == 'PRI') {
                    $pri = $val['COLUMN_NAME'];
                }
                $this->fields[$val['COLUMN_NAME']] = array(
                    'default' => $val['COLUMN_DEFAULT'],
                    'comment' => $val['COLUMN_COMMENT']
                );
            }
            // 没有主键扔出异常
            if ($pri == null) {
                throw new Exception("table : {$table} need have a pri-key");
            }

            array_unshift($this->fields, $pri);
    
            $writeCache && $this->cache->set($key, $this->fields);
        }

        $this->pri = array_shift($this->fields);
    }

    protected function getDataByPRI($id)
    {
        $writeCache = false;
        $data = false;

        if ($this->needCache()) {
            $ns = $this->table.'_id';
            $data = $this->cache->ns($ns)->get($id);
            $writeCache = true;
        }

        if ($data === false) {
            $sql = "SELECT * FROM `{$this->table}` WHERE {$this->pri} = ?";
            $data = $this->db->getRow($sql, $id);
            $writeCache && $this->cache->ns($ns)->set($id, $data);
        }

        return $data;
    }

    public function read()
    {
        $params = $key = func_get_args();

        $sql = array_shift($params);

        if (is_numeric($sql)) {
            return $this->getDataByPRI($sql);
        }

        
        $writeCache = false;
        $data = false;

        if ($this->needCache()) {
            $ns = $this->table;
            $key = $this->genKey('read_', $key);
            $data = $this->cache->ns($ns)->get($key);
            $writeCache = true;
        }

        if ($data === false) {
            if (is_string($sql)) {
                $sql = array($sql);
            }

            if ($params && is_array($params[0])) {
                $params = $params[0];
            }

            list($ap, $p) = $this->getSql($sql);
            $params = array_merge($p, $params);
            $sql = "SELECT * FROM `{$this->table}` ".$ap;
            $writeCache && $sql = "SELECT {$this->pri} FROM `$table` ".$ap;

            if ($params) {
                $data = $this->db->getRow($sql, $params);
            } else {
                $data = $this->db->getRow($sql);
            }
        }

        if (!$writeCache) {
            return $data;
        }

        $this->cache->ns($this->table)->set($key, $data);

        if (empty($data)) {
            return array();
        }

        return $this->getDataById($data[$this->pri]);
    }

    public function getList()
    {
        $table = $this->table;
        $pager = null;

        $params = $key = func_get_args();

        $sql = array_shift($params);

        if (!$sql) {
            $sql = '';
        } elseif (is_object($sql)) {
            $pager = $sql;
            $sql = '';
        } elseif (isset($params[0]) && is_object($params[0])) {
            $pager = array_shift($params);
        }

        $writeCache = false;
        $data = false;

        if ($this->needCache()) {
            $page = intval(@$_GET['page_no']);
            !$page && $page = 1;
            $key = $this->genKey('getList_', $key, $page);

            $ns = $this->table;
            $data = $this->cache->ns($ns)->get($key);
            $writeCache = true;
        }

        if ($data === false) {
            
            if (is_string($sql)) {
                $sql = array($sql);
            }

            if ($params && is_array($params[0])) {
                $params = $params[0];
            }

            list($ap, $p) = $this->getSql($sql);
            $params = array_merge($p, $params);

            if ($pager) {
                $limit = $pager->setPage()->getLimit();
                $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM `{$this->table}` $ap $limit";
                $writeCache && $sql = "SELECT SQL_CALC_FOUND_ROWS {$this->pri} FROM `{$this->table}` $ap $limit";
            } else {
                $sql = "SELECT * FROM `{$this->table}` $ap";
                $writeCache && $sql = "SELECT {$this->pri} FROM `{$this->table}` $ap";
            }

            if ($params) {
                $result = $this->db->getAll($sql, $params);
            } else {
                $result = $this->db->getAll($sql);
            }

            if (!$pager) {
                $data = $result;
            } else {
                $count = $this->db->getOne("SELECT FOUND_ROWS()");
                $pager->generate($count);
                $data = array(
                    'data' => $result,
                    'pager' => $count
                );
            }
            //Model::$cache->ns($this->table)->set($key, $data);
        }

        $writeCache && $this->cache->ns($this->table)->set($key, $data);

        if (isset($data['pager'])) {
            $pager->generate($data['pager']);
            $data['pager'] = $pager;
            if (!$writeCache) {
                return $data;
            }

            foreach ($data['data'] as $key => $value) {
                $data['data'][$key] = $this->getDataByPRI($value[$this->pri]);
            }

            return $data;
        }

        if (!$writeCache) {
            return $data;
        }

        foreach ($data as $key => $value) {
            $data[$key] = $this->getDataByPRI($value[$this->pri]);
        }

        return $data;
    }

    public function create()
    {
        $params = func_get_args();
        $sql = array_shift($params);

        $sql = $this->beforeCreate($sql);

        // beforeCreate 终止插入进行
        if (!$sql) {
            return false;
        }

        $params = array_values($sql);

        $sql = "INSERT INTO `{$this->table}` (`".implode('`, `', array_keys($sql)).'`) VALUES ('.implode(', ', array_fill(0, count($sql), '?')) . ')';

        $result = $this->db->exec($sql, $params);

        $id = $this->db->lastInsertId();

        // 没有自增id
        if (!$id) {
            return $result;
        }

        $new = $this->getDataByPRI($id);

        $this->afterCreate($new);

        
        // 清空表缓存
        if ($this->needCache()) {
            $this->cache->ns($this->table)->delete();
        }

        return $id;
    }

    public function update()
    {
        $params = func_get_args();
        $sql = array_shift($params);
        $writeCache = false;
        
        // 主键 id 特殊处理
        if (is_numeric($sql)) {
            $sql = array($this->pri => $sql);
        }

        if (is_string($sql)) {

            if ($params && is_array($params[0])) {
                $params = $params[0];
            }

            if (strpos($sql, 'WHERE') === false) {
                $select = "SELECT * FROM `{$this->table}`";
                $result = $this->$db->getAll($select);
                $array = array($sql);
            } else {
                list($set, $where) = explode('WHERE', $sql);
                $select = "SELECT * FROM `{$this->table}` WHERE {$where}";
                $c = substr_count($select, '?');
                $select_param = array();
                while ($c) {
                    $select_param[] = array_pop($params);
                    $c--;
                }
                $select_param = array_reverse($select_param);
                if ($select_param) {
                    $result = $this->db->getAll($select, $select_param);
                } else {
                    $result = $this->db->getAll($select);
                }
                $array = array_merge(array($set), $params);
            }

        } else {
            $array = array_shift($params);

            if ($params && is_array($params[0])) {
                $params = $params[0];
            }
            list($ap, $p) = $this->getSql($sql);
            $params = array_merge($p, $params);

            $select = "SELECT * FROM `{$this->table}` $ap";

            if ($params) {
                $result = $this->db->getAll($select, $params);
            } else {
                $result = $this->db->getAll($select);
            }
        }

        $num = 0;
        foreach ($result as $val) {
            $array = $this->beforeUpdate($array, $val);
            // beforeUpdate 终止更新
            if (!$sql) {
                continue;
            }
            if (isset($array[0])) {
                $params = $array;
                $set = array_shift($params);
            } else {
                $params = array_values($array);
                $set = array();

                foreach ($array as $kk => $vv) {
                    $set[] = "`$kk` = ?";
                }

                $set = 'SET ' . implode(',', $set);
            }

            $sql = "UPDATE `{$this->table}` $set WHERE {$this->pri} = {$val[$this->pri]}";
            if ($params) {
                $this->db->exec($sql, $params);
            } else {
                $this->db->exec($sql);
            }

            if ($this->needCache()) {
                // 删除单条cache
                $ns = $this->table.'_id';
                $this->cache->ns($ns)->delete($val[$this->pri]);
                $writeCache = true;
            }


            // 获取最新的记录

            $new = $this->getDataByPRI($val[$this->pri]);

            if (empty($new)) {
                throw new Exception("cant load data by: {$val[$this->pri]}, table : {$this->table}");
            }

            $this->afterUpdate($new, $val);
            $num++;
        }

        if ($num && $writeCache) {
            // 清空表缓存
            $this->cache->ns($this->table)->delete();
        }

        return $num;
    }

    public function delete()
    {
        $writeCache = false;
        $params = func_get_args();
        $sql = array_shift($params);

        // 主键 id 特殊处理
        if (is_numeric($sql)) {
            $sql = array($this->pri => $sql);
        }

        if (is_string($sql)) {
            $sql = array($sql);
        }

        if ($params && is_array($params[0])) {
            $params = $params[0];
        }

        list($ap, $p) = $this->getSql($sql);
        $params = array_merge($p, $params);

        $sql = "SELECT * FROM `{$this->table}` {$ap}";

        if ($params) {
            $result = $this->db->getAll($sql, $params);
        } else {
            $result = $this->db->getAll($sql);
        }

        $num = 0;
        foreach ($result as $val) {
            // beforeDelete 终止删除
            if ($this->beforeDelete($val) === false) {
                continue;
            }
            $sql = "DELETE FROM `{$this->table}` WHERE {$this->pri} = {$val[$this->pri]}";
            $this->db->exec($sql);

            if ($this->needCache()) {
                // 删除单条cache
                $ns = $this->table.'_id';
                $this->cache->ns($ns)->delete($val[$this->pri]);
                $writeCache = true;
            }


            $this->afterDelete($val);
            $num++;
        }

        if ($num && $writeCache) {
            // 清空表缓存
            $this->cache->ns($this->table)->delete();
        }

        return $num;
    }

    public function getCount()
    {
        $params = $key = func_get_args();
        $sql = array_shift($params);
        $writeCache = false;

        $data = false;
        if ($this->needCache()) {
            // 删除单条cache
            $key = $this->genKey('getCount_', $key);
            $ns = $this->table;
            $data = $this->cache->ns($ns)->get($key);
            $writeCache = true;
        }
    
        if ($data === false) {
            if (is_string($sql)) {
                $sql = array($sql);
            }

            if ($params && is_array($params[0])) {
                $params = $params[0];
            }

            list($ap, $p) = $this->getSql($sql);
            $params = array_merge($p, $params);

            $sql = "SELECT COUNT(*) FROM `{$this->table}` $ap";

            if ($params) {
                $data = $this->db->getOne($sql, $params);
            } else {
                $data = $this->db->getOne($sql);
            }
        }

        $writeCache && $this->cache->ns($this->table)->set($key, $data);

        return $data;
    }

    public function getCol()
    {
        $params = func_get_args();
        $writeCache = false;
        $data = false;

        if ($this->needCache()) {
            // 删除单条cache
            $key = $this->genKey('getCount_', $params);
            $ns = $this->table;
            $data = $this->cache->ns($ns)->get($key);
            $writeCache = true;
        }

        if ($data === false) {
            $field = array_shift($params);
            if (empty($field)) {
                throw new Exception("You must set getCol field!");
            }
            $sql = array_shift($params);

            if (is_string($sql)) {
                $sql = array($sql);
            }

            if ($params && is_array($params[0])) {
                $params = $params[0];
            }

            list($ap, $p) = $this->getSql($sql);
            $params = array_merge($p, $params);
            
            $sql = "SELECT {$field} FROM `{$this->table}` $ap";

            if ($params) {
                $data = $this->db->getCol($sql, $params);
            } else {
                $data = $this->db->getCol($sql);
            }
        }

        $writeCache && $this->cache->ns($ns)->set($key, $data);

        return $data;
    }

    public function getSum()
    {
        $params = func_get_args();
        $writeCache = false;
        $data = false;

        if ($this->needCache()) {
            // 删除单条cache
            $key = $this->genKey('getSum_', $params);
            $ns = $this->table;
            $data = $this->cache->ns($ns)->get($key);
            $writeCache = true;
        }

        if ($data === false) {
            $field = array_shift($params);
            if (empty($field)) {
                throw new Exception("You must set getSum field!");
            }
            $sql = array_shift($params);

            if (is_string($sql)) {
                $sql = array($sql);
            }

            if ($params && is_array($params[0])) {
                $params = $params[0];
            }

            list($ap, $p) = $this->getSql($sql);
            $params = array_merge($p, $params);
            
            $sql = "SELECT SUM({$field}) FROM `{$this->table}` $ap";

            if ($params) {
                $data = $this->db->getOne($sql, $params);
            } else {
                $data = $this->db->getOne($sql);
            }
        }

        $writeCache && $this->cache->ns($ns)->set($key, $data);

        return $data;
    }

    protected function beforeCreate($new)
    {
        return $new;
    }

    protected function afterCreate($new)
    {

    }

    protected function beforeUpdate($new, $old)
    {
        return $new;
    }

    protected function afterUpdate($new, $old)
    {
        
    }

    protected function beforeDelete($old)
    {
        
    }

    protected function afterDelete($old)
    {
        
    }
}
