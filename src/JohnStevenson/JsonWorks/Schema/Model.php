<?php

namespace JohnStevenson\JsonWorks\Schema;

use \JohnStevenson\JsonWorks\Utils as Utils;

class Model
{
    public $data;
    /**
    * @var array
    */
    protected $references = array();

    public function __construct($input)
    {
        $this->data = Utils::copyData((object) $input, array($this, 'initCallback'));
        $this->resolveReferences();
    }

    public function find($schema, array $keys)
    {
        while ($keys && $schema) {
            $type = gettype($schema);
            $key = array_shift($keys);

            if ('array' === $type) {
                $key = (int) $key;
            }
            $schema = Utils::get($schema, $key);
         }

        return $schema;
    }

    public function initCallback(\RecursiveArrayIterator $data)
    {
        if ('$ref' === $data->key()) {
            $ref = $data->current();

            if (is_string($ref) && 0 === strpos($ref, '#')) {
                $this->references[$ref] = null;
            } else {
                throw new \RuntimeException('Invalid reference');
            }
        }

        return $data;
    }

    public function resolveCallback(\RecursiveArrayIterator $data)
    {
        if ('$ref' === $data->key()) {
            $schema = Utils::get($this->references, $data->current());
            $data = new \RecursiveArrayIterator($schema);
        }

        return $data;
    }

    private function resolveReferences()
    {
        if ($this->references) {

            foreach (array_keys($this->references) as $ref) {
                $keys = Utils::decodePath($ref);

                if ($schema = $this->find($this->data, $keys)) {
                    $this->references[$ref] = $schema;
                } else {
                    throw new \RuntimeException('Unable to find ref '.$ref);
                }
            }

            foreach ($this->references as $ref => $schema) {
                $this->references[$ref] = $this->resolve($schema);
            }

            $this->data = Utils::copyData($this->data, array($this, 'resolveCallback'));
            $this->references = array();
        }
    }

    private function resolve($schema, $parents = array())
    {
        $result = $schema;

        if ($ref = Utils::get($schema, '$ref')) {
            $refSchema = Utils::get($this->references, $ref);

            if (in_array($ref, $parents)) {
                throw new \RuntimeException('Circular reference to ref '.$ref);
            } elseif (Utils::get($refSchema, '$ref')) {
                $parents[] = $ref;
                $result = $this->resolve($refSchema, $parents);
            } else {
                $result = $refSchema;
            }
        }

        return $result;
    }
}
