<?php

namespace JohnStevenson\JsonWorks;

class Document
{
    public $data;
    public $schema;
    public $error;
    public $lastPushIndex;
    private $element;
    private $workingData;

    public function __construct($data = null, $schema = null)
    {
        if ($data) {
            $this->loadData($data);
        }

        if ($schema) {
            $this->loadSchema($schema);
        }
    }

    public function loadData($data)
    {
        $this->data = $this->checkInput($data, true);
        $this->workingData = null;
    }

    public function loadSchema($schema)
    {
        $schema = $this->checkInput($schema, false);
        $this->schema = new Schema\Model($schema);
    }

    public function addValue($path, $value)
    {
        $this->lastPushIndex = 0;
        $pointers = is_array($path) ? $path : Utils::pathDecode($path);
        $value = Utils::dataCopy($value);

        if (!$pointers) {
            # empty path, add value to root
            if ($result = (is_object($value) || is_array($value)) && $this->checkData($value)) {
                $this->data = $value;
            } else {
                $this->error = $this->error ?: 'Value must be an object or array';
            }

            return $result;
        }

        if (is_null($this->data)) {
            # data not initiated
            $this->workingData = null;
        } else {
            # data exists so copy it
            $this->workingData = Utils::dataCopy($this->data);
        }

        # create any new keys and get referenced element
        if (!$this->workAdd($pointers, $arrayPush, $addKey)) {
            return false;
        }

        # finally add passed-in value to referenced element
        if ($arrayPush) {
            $this->lastPushIndex = array_push($this->element, $value) - 1;
        } elseif (null !== $addKey) {
            $this->element->$addKey = $value;
        } else {
            $this->element = $value;
        }

        if ($result = $this->checkData($this->workingData, true)) {
            $this->data = $this->workingData;
        }

        return $result;
    }

    public function copyValue($fromPath, $toPath)
    {
        return $this->workMove($fromPath, $toPath, false);
    }

    public function deleteValue($path)
    {
        $pointers = is_array($path) ? $path : Utils::pathDecode($path);

        if ($result = $this->hasValue($pointers, $dummy)) {

            $key = array_pop($pointers);
            $this->hasValue($pointers, $dummy);

            if (0 === strlen($key)) {
                $this->loadData(null);
            } elseif (is_array($this->element)) {
                $key = (int) $key;
                array_splice($this->element, $key, 1);
            } elseif (is_object($this->element)) {
                unset($this->element->$key);
            }
         }

        return $result;
    }

    public function getValue($path, $default = null)
    {
        if (!$this->hasValue($path, $value)) {
            $value = $default;        }

        return $value;
    }

    public function hasValue($path, &$value)
    {
        $result = false;
        $value = null;

        $pointers = is_array($path) ? $path : Utils::pathDecode($path);

        if ($this->workGet($pointers, false)) {
            $value = Utils::dataCopy($this->element);
            $result = true;
        }

        return $result;
    }

    public function moveValue($fromPath, $toPath)
    {
        return $this->workMove($fromPath, $toPath, true);
    }

    public function tidy($order = false)
    {
        $this->data = Utils::dataPrune($this->data);
        if ($order) {
            $this->data = Utils::dataOrder($this->data, $this->schema->data);
        }
    }

    public function toJson($pretty, $tabs = false)
    {
        $json = Utils::dataToJson($this->data, $pretty);
        if ($tabs && $pretty) {
            $json = preg_replace_callback('/^( +)/m', function($m) {
                return str_repeat("\t", (int) strlen($m[1]) / 4);
            }, $json);
        }

        return $json;
    }

    public function validate($lax = false)
    {
        return $this->checkData($this->data, $lax);
    }

    protected function checkInput($input, $isData)
    {
        if (is_string($input)) {

             if (preg_match('/^(\{|\[)/', $input, $match)) {
                $input = json_decode($input);
            } else {
                $input = json_decode(@file_get_contents($input));
            }
        }

        if (is_array($input) || is_null($input)) {
            $result = $isData;
        } else {
            $result = is_object($input);
        }

        if (!$result) {
            throw new \Exception('Invalid input');
        }

        return $input;
    }

    protected function pushKey($value)
    {
       return (bool) preg_match('/^((-)|(0+))$/', $value);
    }

    protected function arrayKey($value, &$index, $any = false)
    {
        $index = null;

        if ($any && '-' === $value) {
            $index = '-';
        } elseif (preg_match('/^0*\d+$/', $value)) {
            $index = (int) $value;
        }

        return null !== $index;
    }

    protected function workAdd($pointers, &$arrayPush, &$addKey)
    {
        $this->workGet($pointers, true);
        $arrayPush = false;
        $addKey  = null;

        if (is_null($this->element)) {
            if (!$this->workAddElement($pointers)) {
                return;
            }
        }

        while ($pointers) {

            $key = array_shift($pointers);

            if ($pointers) {

                if (is_array($this->element)) {

                    if (!$this->pushKey($key)) {
                        $this->error = 'Invalid array key';
                        return;
                    }

                    $this->element[0] = null;
                    $this->element = &$this->element[0];
                    if (!$this->workAddElement($pointers)) {
                        return;
                    }

                } else {

                    $this->element->$key = null;
                    $this->element = &$this->element->$key;

                    if (!$this->workAddElement($pointers)) {
                        return;
                    }

                 }

            } else {
                 # no more pointers. First check for array with final array key

                if (is_array($this->element)) {

                    if ($this->arrayKey($key, $index, true)) {
                        $index = is_int($index) ? $index : count($this->element);
                        $arrayPush = $index === count($this->element);
                    }

                    if (!$arrayPush) {
                        $this->error = 'Bad array index';
                        return;
                    }

                } else {
                    $addKey = $key;
                }
            }
        }

        return true;
    }

    protected function workAddElement($pointers)
    {
        $arrayFirst = $this->pushKey($pointers[0]);
        $this->element = $arrayFirst ? array() : new \stdClass();

        if (!$result = $this->checkData($this->workingData, true)) {
            $this->element = !$arrayFirst ? array() : new \stdClass();
            $result = $this->checkData($this->workingData, true);
        }

        return $result;
    }

    protected function workGet(&$pointers, $forEdit)
    {
        if ($forEdit) {
            $this->element = &$this->workingData;
        } else {
            $this->element = &$this->data;
        }

        if (is_null($this->element)) {
            return false;
        }

        while ($pointers) {
            $type = gettype($this->element);
            $test = $pointers;
            $key = array_shift($test);
            $result = false;

            if ('object' === $type) {

                if ($result = property_exists($this->element, $key)) {
                    $this->element = &$this->element->$key;
                }

            } elseif ('array' === $type) {

                if ($result = $this->arrayKey($key, $index)) {
                    if ($result = array_key_exists($index, $this->element)) {
                        $this->element = &$this->element[$index];
                    }
                }
            }

            if (!$result) {
                return false;
            }

            array_shift($pointers);
         }

         return true;
    }

    protected function workMove($fromPath, $toPath, $delete)
    {
        $result = false;

        if ($this->hasValue($fromPath, $value)) {
            if ($result = $this->addValue($toPath, $value)) {
                if ($delete) {
                    $this->deleteValue($fromPath);
                }
             }
        }

        return $result;
    }

    protected function checkData($data, $lax = false)
    {
        if (!$this->schema) {
            throw new \Exception('Schema has not been loaded');
        }

        $this->error = null;
        $validator = new Schema\Validator();

        if (!$result = $validator->check($data, $this->schema, $lax)) {
            $this->error = $validator->error;
        }

        return $result;
    }
}
