<?php

namespace JohnStevenson\JsonWorks;

class Utils
{
    public static function get($container, $key, $default = null)
    {
        $result = $default;

        if (is_object($container)) {
            $result = isset($container->$key) ? $container->$key : $default;
        } elseif (is_array($container)) {
            $result = isset($container[$key]) ? $container[$key] : $default;
        }

        return $result;
    }

    public static function checkType($type, $value)
    {
        $result = false;

        if ('number' === $type) {
            $result = is_float($value) || is_integer($value);
        } elseif ('boolean' === $type) {
            $result = is_bool($value);
        } elseif (function_exists($func = 'is_'.$type)) {
            $result = call_user_func($func, $value);
        }

        return $result;
    }

    public static function equals($var1, $var2)
    {
        $type1 = gettype($var1);
        $type2 = gettype($var2);

        if ('integer' === $type1 && 'double' === $type2) {
            $var1 = floatval($var1);
            $type1 = 'double';
        } elseif ('integer' === $type2 && 'double' === $type1) {
            $var2 = floatval($var2);
            $type2 = 'double';
        }

        if ($type1 !== $type2) {
            return false;
        }

        if ('object' === $type1) {
            return static::equalsObject($var1, $var2);
        } elseif ('array' === $type1) {
            return static::equalsArray($var1, $var2);
        } elseif ('double' === $type1) {
            return 0 === bccomp($var1, $var2, 16);
        } else {
            return $var1 === $var2;
        }
    }

    public static function equalsObject($obj1, $obj2)
    {
        $props = get_object_vars($obj1);

        if (count($props) !== count(get_object_vars($obj2))) {
            return false;
        }

        foreach ($props as $key => $value) {
            if (!isset($obj2->$key) || !static::equals($value, $obj2->$key)) {
                return false;
            }
         }

        return true;
    }

    public static function equalsArray($arr1, $arr2)
    {
        $count = count($arr1);

        if ($count !== count($arr2)) {
            return false;
        }

        for ($i = 0; $i < $count; ++$i) {
            if (!static::equals($arr1[$i], $arr2[$i])) {
                return false;
            }
         }

        return true;
    }

    public static function addToPath($path, $key)
    {
        if (strlen($encoded = static::encodePathKey($key))) {
            $encoded = '/'.$encoded;
        }

        return $path.$encoded;
    }

    public static function decodePath($path)
    {
        $keys = explode('/', $path);
        array_shift($keys);

        foreach ($keys as &$value) {
            $value = str_replace('~0', '~', str_replace('~1', '/', $value));
        }

        return $keys;
    }

    public static function encodePath($keys)
    {
        $result = '';

        foreach ((array) $keys as $value) {
            $result = static::addToPath($result, $value);
        }

        return $result;
    }

    public static function encodePathKey($key)
    {
        return str_replace('/', '~1', str_replace('~', '~0', strval($key)));
    }

    public static function encodeDataKeys($data)
    {
        return static::copyData($data, true, array('\\'.get_called_class(), 'encodeCallback'));
    }

    public static function encodeCallback($data)
    {
        if (is_object($data) || (is_array($data) && !static::indexedArray($data))) {
            $result = array();

            foreach ($data as $key => $value) {
                $key = static::encodePathKey($key);
                $result[$key] = $value;
            }
            $data = (object) $result;
        }

        return $data;
    }

    public static function copyData($data, $fromAssoc = false, $callback = null)
    {
        if ($callback) {
            $data = call_user_func_array($callback, array($data));
        }

        if (($object = is_object($data)) || is_array($data)) {
            $result = array();

            foreach ($data as $key => $value) {
                if (is_object($value) || is_array($value)) {
                   $value = static::copyData($value, $fromAssoc, $callback);
                }
                $result[$key] = $value;
            }

            if ($object || ($fromAssoc && !static::indexedArray($data))) {
                $result = (object) $result;
            }

        } else {
            $result = $data;
        }

        return $result;
    }

    public static function indexedArray($array) {
        for (reset($array), $base = 0; key($array) === $base++; next($array));
        return is_null(key($array));
    }

    public static function uniqueArray($data, $check = false)
    {
        $equals = array();
        $count = count($data);

        for ($i = 0; $i < $count; ++$i) {
            if (in_array($i, $equals)) {
                continue;
            }
            for ($j = $i + 1; $j < $count; ++$j) {
                if (Utils::equals($data[$i], $data[$j])) {
                    $equals[] = $j;
                    if ($check) {
                        break;
                    }
                }
            }
        }

        if ($check) {
            $result = empty($equals);
        } else {
            $result = array();
            foreach ($data as $key => $value) {
                if (!in_array($key, $equals)) {
                    $result[] = $value;
                }
            }
        }

        return $result;
     }
}
