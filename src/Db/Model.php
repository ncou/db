<?php
/**
 * @author ueaner <ueaner@gmail.com>
 */
namespace Soli\Db;

use Soli\Di\Container;
use Exception;

/**
 * 模型
 *
 * @property \Soli\Db\Connection $db
 */
abstract class Model
{
    /** @var string $connection Container 中的数据库连接服务名称 */
    protected $connection = 'db';

    protected $table;
    protected $primaryKey = 'id';
    protected $columns;

    /**
     * 获取 Model 对象实例
     *
     * @return static
     */
    public static function instance()
    {
        return Container::instance()->get(get_called_class());
    }

    /**
     * 获取数据库连接服务名称
     *
     * @return string
     */
    public function connection()
    {
        return $this->connection;
    }

    /**
     * 获取表名称
     */
    public function table()
    {
        if ($this->table === null) {
            $this->table = uncamelize(basename(str_replace('\\', '/', get_called_class())));
        }
        return $this->table;
    }

    /**
     * 获取当前 table 的全部字段信息
     */
    public function columns()
    {
        if ($this->columns === null) {
            $sql = 'DESCRIBE ' . $this->table();
            $this->columns = $this->db->query($sql);
        }

        return $this->columns;
    }

    /**
     * 获取主键名称
     */
    public function primaryKey()
    {
        return $this->primaryKey;
    }

    /**
     * 新增一条纪录
     *
     * @example
     *  $data = [
     *      'name' => 'ueaner',
     *      'age' => 28,
     *      'email' => 'ueaner@gmail.com'
     *  ];
     *  $model::create($data);
     *
     * @param array|\ArrayAccess $fields 新增纪录的字段列表与值的键值对
     * @return int|bool 新增成功返回插入的主键值，失败返回 false
     */
    public static function create($fields)
    {
        if (empty($fields)) {
            return false;
        }

        $model = static::instance();

        $binds = [];
        foreach ($fields as $field => $value) {
            $binds[":$field"] = $value;
        }

        $fields     = implode(',', array_keys($fields));
        $fieldBinds = implode(',', array_keys($binds));

        $sql = "INSERT INTO {$model->table()}($fields) VALUES($fieldBinds)";

        return $model->db->query($sql, $binds);
    }

    /**
     * 通过条件删除纪录
     *
     * @example
     *  1. 删除主键为 123 的纪录
     *  $model::delete(123);
     *  2. 按传入的条件删除
     *  $model::delete("age > 20 and email == ''");
     *  3. 按传入的条件删除, 并过滤传入的删除条件
     *  $binds = [':created_at' => '2015-10-27 07:16:16'];
     *  $model::delete("created_at < :created_at", $binds);
     *
     * @param int|string $params 条件, 不可为空
     * @param array $binds 绑定条件
     * @return int|bool 成功返回影响行数，失败返回 false
     */
    public static function delete($params, $binds = [])
    {
        if (empty($params)) {
            return false;
        }

        $model = static::instance();

        // 通过主键删除一条数据
        if (is_numeric($params)) {
            $params = $model->primaryKey() . ' = ' . $params;
        }

        $sql = "DELETE FROM {$model->table()} WHERE $params";

        return $model->db->query($sql, $binds);
    }

    /**
     * 更新一条数据
     * 但对于 hits = hits+1 这样的语句需要使用 query 方法来做
     *
     * @example
     *  $data = [
     *      'name' => 'jack',
     *      'age' => 20,
     *      'email' => ':email'
     *  ];
     *  $binds = [
     *      ':email' => 'mail@domain.com',
     *      ':created_at' => '2015-10-27 08:36:42'
     *  ];
     *
     *  $rowCount = $model::update($data, 12);
     *  $rowCount = $model::update($data, 'created_at = :created_at', $binds);
     *
     * @param array|\ArrayAccess $fields 更新纪录的字段列表与值的键值对, 不可为空
     * @param int|string $params 更新条件
     * @param array $binds 绑定条件
     * @return int|bool 更新成功返回影响行数，失败返回false
     */
    public static function update($fields, $params, array $binds = [])
    {
        if (empty($fields)) {
            return false;
        }

        $model = static::instance();

        // 通过主键更新一条数据
        if (is_numeric($params)) {
            $params = $model->primaryKey() . ' = ' . $params;
        }

        // 自动绑定参数
        $sets = [];
        foreach ($fields as $field => $value) {
            if (!isset($binds[":$field"])) {
                $binds[":$field"] = $value;
                $sets[] = "$field = :$field";
            }
        }

        $sets = implode(',', $sets);
        $sql = "UPDATE {$model->table()} SET $sets WHERE $params";

        return $model->db->query($sql, $binds);
    }

    /**
     * 保存(更新或者新增)一条数据
     *
     * @example
     *  $data = [
     *      'id' => 12, // 保存的数据中有主键，则按主键更新，否则新增一条数据
     *      'name' => 'ueaner',
     *      'age' => 28,
     *      'email' => ':email'
     *  ];
     *  $binds = [
     *      ':email' => 'ueaner@gmail.com',
     *      ':created_at' => '2015-10-27 08:36:42'
     *  ];
     *
     *  $rowCount = $model::save($data);
     *  // 相当于：
     *  $rowCount = $model::update($data, 12);
     *
     *  $rowCount = $model::save($data, 'created_at = :created_at', $binds);
     *
     * @param array|\ArrayAccess $fields 更新纪录的字段列表与值的键值对, 不可为空
     * @param bool $checkPrimaryKey 检查主键是否存在，再确实是执行更新还是新增
     * @return int|bool 更新成功返回影响行数，失败返回false
     */
    public static function save($fields, $checkPrimaryKey = false)
    {
        if (empty($fields)) {
            return false;
        }

        $model = static::instance();

        $id = isset($fields[$model->primaryKey()]) ? $fields[$model->primaryKey()] : 0;

        if ($id) {
            if (!$checkPrimaryKey) {
                return $model::update($fields, $id);
            }
            if ($model::findById($id)) {
                return $model::update($fields, $id);
            }
        }

        return $model::create($fields);
    }

    /**
     * 通过条件查询纪录
     *
     * @example
     *  1. 获取全部纪录
     *  $model::find();
     *  2. 获取主键为 123 的纪录
     *  $model::find(123);
     *  3. 按传入的条件查询
     *  $model::find("age > 20 and email == ''");
     *  4. 按传入的条件查询, 并过滤传入的查询条件
     *  $binds = [':created_at' => '2015-10-27 07:16:16'];
     *  $model::find("created_at < :created_at", $binds);
     *
     * @param int|string $params 查询条件
     * @param array $binds 绑定条件
     * @param string $fields 返回的字段列表
     * @return array 返回记录列表
     */
    public static function find($params = null, $binds = [], $fields = '*')
    {
        $model = static::instance();

        $fields = $model->normalizeFields($fields);

        // 获取某个主键ID的数据
        if (is_numeric($params)) {
            $params = $model->primaryKey() . ' = ' . $params;
        }

        if (!empty($params)) {
            $params = " WHERE $params ";
        }

        $sql = "SELECT {$fields} FROM {$model->table()} $params";

        $data = $model->db->query($sql, $binds);

        if (empty($data)) {
            return $data;
        }

        // 结果集中含有主键则用主键做下标
        $first = reset($data);
        if (isset($first[$model->primaryKey()])) {
            return array_column($data, null, $model->primaryKey());
        }

        return $data;
    }

    /**
     * 通过条件查询纪录的第一条数据
     *
     * @example
     *  1. 获取默认第一条纪录
     *  $model::findFirst();
     *  2. 获取主键为 123 的纪录
     *  $model::findFirst(123);
     *  3. 按传入的条件查询
     *  $model::findFirst("age > 20 and email == ''");
     *  4. 按传入的条件查询, 并过滤传入的查询条件
     *  $binds = [':created_at' => '2015-10-27 07:16:16'];
     *  $model::findFirst("created_at < :created_at", $binds);
     *
     * @param int|string $params 查询条件
     * @param array $binds 绑定条件
     * @param string $fields 返回的字段列表
     * @return array 返回记录列表
     */
    public static function findFirst($params = null, $binds = [], $fields = '*')
    {
        $model = static::instance();

        $fields = $model->normalizeFields($fields);

        // 获取某个主键ID的数据
        if (is_numeric($params)) {
            $params = $model->primaryKey() . ' = ' . $params;
        }

        if (!empty($params)) {
            $params = " WHERE $params ";
        }

        $sql = "SELECT {$fields} FROM {$model->table()} $params";

        return $model->db->queryRow($sql, $binds);
    }

    /**
     * 通过ID查询一条记录
     *
     * @param int $id
     * @param string $fields
     * @return array|false
     */
    public static function findById($id, $fields = '*')
    {
        if (empty($id)) {
            return false;
        }

        $model = static::instance();

        $fields = $model->normalizeFields($fields);

        $sql = "SELECT {$fields} FROM {$model->table()} WHERE {$model->primaryKey()} = :id";
        $binds = [':id' => $id];

        return $model->db->queryRow($sql, $binds);
    }

    /**
     * 通过ID列表获取多条记录，
     * 注意，返回结果不一定按传入的ID列表顺序排序
     *
     * @param array $ids
     * @param string $fields
     * @return array|false
     */
    public static function findByIds(array $ids, $fields = '*')
    {
        if (empty($ids)) {
            return false;
        }

        /** @var static $model */
        $model = static::instance();

        $fields = $model->normalizeFields($fields);

        $binds = [];
        foreach ($ids as $id) {
            $binds[":id_{$id}"] = $id;
        }

        $fieldBinds = implode(',', array_keys($binds));
        $number = count($ids);

        $sql = "SELECT {$fields} FROM {$model->table()} WHERE {$model->primaryKey()} IN ($fieldBinds)"
          . " ORDER BY FIELD(id, $fieldBinds) LIMIT {$number}";

        $data = $model->db->query($sql, $binds);
        if (empty($data)) {
            return $data;
        }

        // 以主键为下标
        return array_column($data, null, $model->primaryKey());
    }

    /**
     * @param $name
     * @param $parameters
     * @return mixed
     * @throws Exception
     */
    public function __call($name, $parameters)
    {
        return static::__callStatic($name, $parameters);
    }

    /**
     * @param $name
     * @param $parameters
     * @return mixed
     * @throws Exception
     */
    public static function __callStatic($name, $parameters)
    {
        //$pattern = "/^(findBy|findFirstBy)((\w+)And(\w+)|\w+)$/";
        $pattern = "/^(?<func>findBy|findFirstBy)(?<column>(?<column1>\w+)And(?<column2>\w+)|\w+)$/";

        preg_match($pattern, $name, $matches);

        if (empty($matches)) {
            throw new Exception("Call to undefined method '$name'");
        }

        $model = static::instance();

        // 表字段列表
        $columns = array_column($model->columns(), 'Field');

        if (isset($matches['column2'])) {
            $func = "static::{$matches['func']}ColumnAndColumn";
            $by = [
                $matches['column1'],
                $matches['column2'],
            ];
        } else {
            $func = "static::{$matches['func']}Column";
            $by = [
                $matches['column'],
            ];
        }

        foreach ($by as &$column) {
            $column = uncamelize($column);

            if (!in_array($column, $columns)) {
                throw new Exception("Call to undefined method '$name'");
            }
        }

        $parameters = array_merge($by, $parameters);

        return call_user_func_array($func, $parameters);
    }

    /**
     * 通过某个字段获取多条记录
     *
     * @param string $column 字段名
     * @param string $value 字段值
     * @param string $fields
     * @return array|false
     */
    protected static function findByColumn($column, $value, $fields = '*')
    {
        $model = static::instance();

        $fields = $model->normalizeFields($fields);

        $binds = [];
        $binds[":$column"] = $value;

        $sql = "SELECT {$fields} FROM {$model->table()} WHERE $column = :$column";

        return $model->db->queryAll($sql, $binds);
    }

    /**
     * 通过某个字段获取一条记录
     *
     * @param string $column 字段名
     * @param string $value 字段值
     * @param string $fields
     * @return array|false
     */
    protected static function findFirstByColumn($column, $value, $fields = '*')
    {
        $model = static::instance();

        $fields = $model->normalizeFields($fields);

        $binds = [];
        $binds[":$column"] = $value;

        $sql = "SELECT {$fields} FROM {$model->table()} WHERE $column = :$column";

        return $model->db->queryRow($sql, $binds);
    }

    /**
     * 通过某两个字段获取多条记录
     *
     * @param string $column1 字段名1
     * @param string $column2 字段名2
     * @param string $value1 字段值1
     * @param string $value2 字段值2
     * @param string $fields
     * @return array|false
     */
    protected static function findByColumnAndColumn($column1, $column2, $value1, $value2, $fields = '*')
    {
        $model = static::instance();

        $fields = $model->normalizeFields($fields);

        $binds = [];
        $binds[":$column1"] = $value1;
        $binds[":$column2"] = $value2;

        $sql = "SELECT {$fields} FROM {$model->table()} WHERE $column1 = :$column1 AND $column2 = :$column2";

        return $model->db->queryAll($sql, $binds);
    }

    /**
     * 通过某两个字段获取一条记录
     *
     * @param string $column1 字段名1
     * @param string $column2 字段名2
     * @param string $value1 字段值1
     * @param string $value2 字段值2
     * @param string $fields
     * @return array|false
     */
    protected static function findFirstByColumnAndColumn($column1, $column2, $value1, $value2, $fields = '*')
    {
        $model = static::instance();

        $fields = $model->normalizeFields($fields);

        $binds = [];
        $binds[":$column1"] = $value1;
        $binds[":$column2"] = $value2;

        $sql = "SELECT {$fields} FROM {$model->table()} WHERE $column1 = :$column1 AND $column2 = :$column2";

        return $model->db->queryRow($sql, $binds);
    }

    protected function normalizeFields($fields)
    {
        if ($fields != '*') {
            return $fields;
        }

        if (empty($this->fields)) {
            $columns = array_column($this->columns(), 'Field');
            $this->fields = implode(', ', $columns);
        }

        return $this->fields;
    }

    /**
     * 获取 Db 连接或 Container 中的某个 Service
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        $container = Container::instance();

        if ($name == 'db') {
            $this->db = $container->get($this->connection());
            return $this->db;
        }

        if ($container->has($name)) {
            $this->$name = $container->get($name);
            // 将找到的服务添加到属性, 以便下次直接调用
            return $this->$name;
        }

        trigger_error("Access to undefined property $name");
        return null;
    }
}
