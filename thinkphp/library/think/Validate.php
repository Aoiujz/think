<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think;

class Validate
{
    // 预定义正则验证规则
    protected static $rule = [
        'require'  => '/\S+/',
        'email'    => '/^\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*$/',
        'url'      => '/^http(s?):\/\/(?:[A-za-z0-9-]+\.)+[A-za-z]{2,4}(:\d+)?(?:[\/\?#][\/=\?%\-&~`@[\]\':+!\.#\w]*)?$/',
        'currency' => '/^\d+(\.\d+)?$/',
        'number'   => '/^\d+$/',
        'zip'      => '/^\d{6}$/',
        'integer'  => '/^[-\+]?\d+$/',
        'double'   => '/^[-\+]?\d+(\.\d+)?$/',
        'english'  => '/^[A-Za-z]+$/',
    ];

    // 验证失败错误信息
    protected static $error = [];

    /**
     * 设置正则验证规则
     * @access protected
     * @param string|array $name  规则名称或者规则数组
     * @param string $rule  正则规则
     * @return void
     */
    public static function rule($name, $rule = '')
    {
        if (is_array($name)) {
            self::$rule = array_merge(self::$rule, $name);
        } else {
            self::$rule[$name] = $rule;
        }
    }

    /**
     * 数据自动验证
     * @access protected
     * @param array $data  数据
     * @param array $rules  验证规则
     * @param string $config  规则配置名 验证规则不是数组的话读取配置参数
     * @return void
     */
    public static function check(&$data, $rules, $config = 'validate')
    {
        // 获取自动验证规则
        list($rules, $options, $scene) = self::getDataRule($rules, $config);
        if (!isset($options['value_validate'])) {
            $options['value_validate'] = [];
        } elseif (is_string($options['value_validate'])) {
            $options['value_validate'] = explode(',', $options['value_validate']);
        }
        if (!isset($options['exists_validate'])) {
            $options['exists_validate'] = [];
        } elseif (is_string($options['exists_validate'])) {
            $options['exists_validate'] = explode(',', $options['exists_validate']);
        }

        foreach ($rules as $key => $val) {
            if (is_numeric($key) && is_array($val)) {
                $key = array_shift($val);
            }
            if (!empty($scene) && !in_array($key, $scene)) {
                continue;
            }
            // 获取数据 支持二维数组
            $value = self::getDataValue($data, $key);

            if ((in_array($key, $options['value_validate']) && '' == $value)
                || (in_array($key, $options['exists_validate']) && is_null($value))) {
                // 不满足自动验证条件
                continue;
            }
            $result = true;
            if ($val instanceof \Closure) {
                // 匿名函数验证 支持传入当前字段和所有字段两个数据
                $result = self::callback($value, $val, $data);
            } elseif (is_string($val)) {
                // 行为验证 用于一次性批量验证
                $result = self::behavior($val, $data);
            } else {
                // 验证字段规则
                $result = self::checkItem($value, $val, $data);
            }
            if (true !== $result) {
                // 没有返回true 则表示验证失败
                if (!empty($options['patch'])) {
                    // 批量验证
                    if (is_array($result)) {
                        self::$error[] = $result;
                    } else {
                        self::$error[$key] = $result;
                    }
                } else {
                    self::$error = $result;
                    return false;
                }
            }
        }
        return !empty(self::$error) ? false : true;
    }

    // 自动填充
    public static function fill(&$data, $rules, $config = 'auto')
    {
        // 获取自动完成规则
        list($rules, $options, $scene) = self::getDataRule($rules, $config);
        if (!isset($options['value_fill'])) {
            $options['value_fill'] = [];
        } elseif (is_string($options['value_fill'])) {
            $options['value_fill'] = explode(',', $options['value_fill']);
        }
        if (!isset($options['exists_fill'])) {
            $options['exists_fill'] = [];
        } elseif (is_string($options['exists_fill'])) {
            $options['exists_fill'] = explode(',', $options['exists_fill']);
        }

        foreach ($rules as $key => $val) {
            if (is_numeric($key) && is_array($val)) {
                $key = array_shift($val);
            }
            if (!empty($scene) && !in_array($key, $scene)) {
                continue;
            }
            // 数据自动填充
            self::fillItem($key, $val, $data, $options);
        }
        return $data;
    }

    /**
     * 数据自动填充
     * @access protected
     * @param string $key  字段名
     * @param mixed $val  填充规则
     * @param array $data  数据
     * @param array $options  参数
     * @return void
     */
    protected static function fillItem($key, $val, &$data, $options = [])
    {
        // 获取数据 支持二维数组
        $value = self::getDataValue($data, $key);
        if (strpos($key, '.')) {
            list($name1, $name2) = explode('.', $key);
        }
        if ((in_array($key, $options['value_fill']) && '' == $value)
            || (in_array($key, $options['exists_fill']) && is_null($value))) {
            // 不满足自动填充条件
            return;
        }
        if ($val instanceof \Closure) {
            $result = self::callback($value, $val, $data);
        } elseif (isset($val[0]) && $val[0] instanceof \Closure) {
            $result = self::callback($value, $val[0], $data);
        } elseif (!is_array($val)) {
            $result = $val;
        } else {
            $rule   = isset($val[0]) ? $val[0] : $val;
            $type   = isset($val[1]) ? $val[1] : 'value';
            $params = isset($val[2]) ? (array) $val[2] : [];
            switch ($type) {
                case 'behavior':
                    self::behavior($rule, $data);
                    return;
                case 'callback':
                    $result = self::callback($value, $rule, $data, $params);
                    break;
                case 'serialize':
                    $result = self::serialize($value, $rule, $data, $params);
                    break;
                case 'ignore':
                    if ($rule === $value) {
                        if (strpos($key, '.')) {
                            unset($data[$name1][$name2]);
                        } else {
                            unset($data[$key]);
                        }
                    }
                    return;
                case 'value':
                default:
                    $result = $rule;
                    break;
            }
        }
        if (strpos($key, '.')) {
            $data[$name1][$name2] = $result;
        } else {
            $data[$key] = $result;
        }
    }

    /**
     * 验证字段规则
     * @access protected
     * @param mixed $value  字段值
     * @param mixed $val  验证规则
     * @param array $data  数据
     * @return string|true
     */
    protected static function checkItem($value, $val, &$data)
    {
        $rule    = $val[0];
        $msg     = isset($val[1]) ? $val[1] : '';
        $type    = isset($val[2]) ? $val[2] : 'regex';
        $options = isset($val[3]) ? (array) $val[3] : [];
        if ($rule instanceof \Closure) {
            // 匿名函数验证 支持传入当前字段和所有字段两个数据
            $result = self::callback($value, $rule, $data, $options);
        } else {
            switch ($type) {
                case 'callback':
                    $result = self::callback($value, $rule, $data, $options);
                    break;
                case 'behavior':
                    // 行为验证
                    $result = self::behavior($rule, $data);
                    break;
                case 'filter': // 使用filter_var验证
                    $result = self::filter($value, $rule, $options);
                    break;
                case 'confirm':
                    $result = self::confirm($value, $rule, $data);
                    break;
                case 'in':
                    $result = self::in($value, $rule);
                    break;
                case 'notin':
                    $result = self::notin($value, $rule);
                    break;
                case 'between': // 验证是否在某个范围
                    $result = self::between($value, $rule);
                    break;
                case 'notbetween': // 验证是否不在某个范围
                    $result = self::notbetween($value, $rule);
                    break;
                case 'regex':
                default:
                    $result = self::regex($value, $rule);
                    break;
            }
        }
        // 验证失败返回错误信息
        return (false !== $result) ? $result : $msg;
    }

    /**
     * 验证是否和某个字段的值一致
     * @access public
     * @param mixed $value  字段值
     * @param mixed $rule  验证规则
     * @param array $data  数据
     * @return bool
     */
    public static function confirm($value, $rule, $data)
    {
        return $value == $data[$rule];
    }

    /**
     * 使用callback方式验证或者填充
     * @access public
     * @param mixed $value  字段值
     * @param mixed $rule  验证规则
     * @param array $data  数据
     * @param array $params  参数
     * @return mixed
     */
    public static function callback($value, $rule, &$data, $params = [])
    {
        if ($rule instanceof \Closure) {
            return call_user_func_array($rule, [$value, &$data]);
        }
        array_unshift($params, $value);
        return call_user_func_array($rule, $params);
    }

    /**
     * 使用行为类验证或者填充
     * @access public
     * @param mixed $rule  验证规则
     * @param array $data  数据
     * @return mixed
     */
    public static function behavior($rule, $data)
    {
        // 行为验证
        return Hook::exec($rule, '', $data);
    }

    /**
     * 序列化填充
     * @access public
     * @param mixed $value  字段值
     * @param mixed $rule  验证规则
     * @param array $data  数据
     * @param array $params  参数
     * @return mixed
     */
    public static function serialize($value, $rule, &$data, $params = [])
    {
        if (is_string($rule)) {
            $rule = explode(',', $rule);
        }
        $serialize = [];
        foreach ($rule as $name) {
            if (isset($data[$name])) {
                $serialize[$name] = $data[$name];
                unset($data[$name]);
            }
        }
        $fun = !empty($params['type']) ? $params['type'] : 'serialize';
        return $fun($serialize);
    }

    /**
     * 使用filter_var方式验证
     * @access public
     * @param mixed $value  字段值
     * @param mixed $rule  验证规则
     * @param array $params  参数
     * @return bool
     */
    public static function filter($value, $rule, $params = [])
    {
        return false !== filter_var($value, is_int($rule) ? $rule : filter_id($rule), $params);
    }

    /**
     * 验证是否在范围内
     * @access public
     * @param mixed $value  字段值
     * @param mixed $rule  验证规则
     * @return bool
     */
    public static function in($value, $rule)
    {
        $range = is_array($rule) ? $rule : explode(',', $rule);
        return in_array($value, $range);
    }

    /**
     * 验证是否不在某个范围
     * @access public
     * @param mixed $value  字段值
     * @param mixed $rule  验证规则
     * @return bool
     */
    public static function notin($value, $rule)
    {
        $range = is_array($rule) ? $rule : explode(',', $rule);
        return !in_array($value, $range);
    }

    /**
     * between验证数据
     * @access public
     * @param mixed $value  字段值
     * @param mixed $rule  验证规则
     * @return mixed
     */
    public static function between($value, $rule)
    {
        if (is_string($rule)) {
            $rule = explode(',', $rule);
        }
        list($min, $max) = $rule;
        return $value >= $min && $value <= $max;
    }

    /**
     * 使用notbetween验证数据
     * @access public
     * @param mixed $value  字段值
     * @param mixed $rule  验证规则
     * @return mixed
     */
    public static function notbetween($value, $rule)
    {
        if (is_string($rule)) {
            $rule = explode(',', $rule);
        }
        list($min, $max) = $rule;
        return $value < $min || $value > $max;
    }

    /**
     * 使用正则验证数据
     * @access public
     * @param mixed $value  字段值
     * @param mixed $rule  验证规则
     * @return mixed
     */
    public static function regex($value, $rule)
    {
        if (isset(self::$rule[$rule])) {
            $rule = self::$rule[$rule];
        }
        if (!(0 === strpos($rule, '/') && preg_match('/\/[imsU]{0,4}$/', $rule))) {
            // 不是正则表达式则两端补上/
            $rule = '/^' . $rule . '$/';
        }
        return 1 === preg_match($rule, (string) $value);
    }

    // 获取错误信息
    public static function getError()
    {
        return self::$error;
    }

    /**
     * 获取数据值
     * @access protected
     * @param array $data  数据
     * @param string $key  数据标识 支持二维
     * @return mixed
     */
    protected static function getDataValue($data, $key)
    {
        if (strpos($key, '.')) {
            // 支持二维数组验证
            list($name1, $name2) = explode('.', $key);
            $value               = isset($data[$name1][$name2]) ? $data[$name1][$name2] : null;
        } else {
            $value = isset($data[$key]) ? $data[$key] : null;
        }
        return $value;
    }

    /**
     * 获取数据自动验证或者完成的规则定义
     * @access protected
     * @param mixed $rules  数据规则
     * @param string $config  配置参数
     * @return array
     */
    protected static function getDataRule($rules, $config)
    {
        if (!is_array($rules)) {
            // 读取配置文件中的数据类型定义
            $config = Config::get($config);
            if (isset($config['__pattern__'])) {
                // 全局字段规则
                self::$rule = $config['__pattern__'];
            }
            if (strpos($rules, '.')) {
                list($name, $group) = explode('.', $rules);
            } else {
                $name = $rules;
            }
            $rules = isset($config[$name]) ? $config[$name] : [];
            if (isset($config['__all__'])) {
                $rules = array_merge($config['__all__'], $rules);
            }
        }
        if (isset($rules['__option__'])) {
            // 参数设置
            $options = $rules['__option__'];
            unset($rules['__option__']);
        } else {
            $options = [];
        }
        if (isset($group) && isset($options['scene'][$group])) {
            // 如果设置了验证适用场景
            $scene = $options['scene'][$group];
            if (is_string($scene)) {
                $scene = explode(',', $scene);
            }
        } else {
            $scene = [];
        }
        return [$rules, $options, $scene];
    }
}
