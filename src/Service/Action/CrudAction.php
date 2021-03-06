<?php
/**
 * Copyright 2016, Cake Development Corporation (http://cakedc.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2016, Cake Development Corporation (http://cakedc.com)
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

namespace CakeDC\Api\Service\Action;

use CakeDC\Api\Exception\ValidationException;
use CakeDC\Api\Service\CrudService;
use CakeDC\Api\Service\Utility\ReverseRouting;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\Exception\InvalidPrimaryKeyException;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;

/**
 * Class CrudAction
 *
 * @package CakeDC\Api\Service\Action
 */
abstract class CrudAction extends Action
{
    /**
     * @var Table
     */
    protected $_table = null;

    /**
     * Object Identifier
     *
     * @var string
     */
    protected $_id = null;

    protected $_idName = 'id';

    /**
     * Crud service.
     *
     * @var CrudService
     */
    protected $_service;

    /**
     * Parent Object Identifier
     *
     * Used for nested services
     *
     * @var string
     */
    protected $_parentId = null;

    protected $_parentIdName = null;

    /**
     * Action constructor.
     *
     * @param array $config Configuration options passed to the constructor
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        if (!empty($config['table'])) {
            $tableName = $config['table'];
        } else {
            $tableName = $this->service()->table();
        }
        if ($tableName instanceof Table) {
            $this->table($tableName);
        } else {
            $table = TableRegistry::get($tableName);
            $this->table($table);
        }
        if (!empty($config['id'])) {
            $this->_id = $config['id'];
        }
        if (!empty($config['idName'])) {
            $this->_idName = $config['idName'];
        }
        if (!empty($config['parentId'])) {
            $this->_parentId = $config['parentId'];
        }
        if (!empty($config['parentIdName'])) {
            $this->_parentIdName = $config['parentIdName'];
        }
        if (!empty($config['table'])) {
            $this->table($config['table']);
        }
    }

    /**
     * Api method for table.
     *
     * @param Table $table A Table instance.
     * @return Table
     */
    public function table($table = null)
    {
        if ($table === null) {
            return $this->_table;
        }
        $this->_table = $table;

        return $this->_table;
    }

    /**
     * Builds new entity instance.
     *
     * @return \Cake\Collection\Collection
     */
    protected function _newEntity()
    {
        $entity = $this->table()->newEntity();

        return $entity;
    }

    /**
     * Patch entity.
     *
     * @param EntityInterface $entity An Entity instance.
     * @param array $data Entity data.
     * @return \Cake\Datasource\EntityInterface|mixed
     */
    protected function _patchEntity($entity, $data)
    {
        $entity = $this->table()->patchEntity($entity, $data);
        $event = $this->dispatchEvent('Action.Crud.onPatchEntity', compact('entity'));
        if ($event->result) {
            $entity = $event->result;
        }

        return $entity;
    }

    /**
     * Builds entities list
     *
     * @return \Cake\Collection\Collection
     */
    protected function _getEntities()
    {
        $query = $this->table()->find();
        $event = $this->dispatchEvent('Action.Crud.onFindEntities', compact('query'));
        if ($event->result) {
            $query = $event->result;
        }
        $records = $query->all();
        $event = $this->dispatchEvent('Action.Crud.afterFindEntities', compact('query', 'records'));

        return $records;
    }

    /**
     * Returns single entity by id.
     *
     * @param mixed $primaryKey Primary key.
     * @return \Cake\Collection\Collection
     */
    protected function _getEntity($primaryKey)
    {
        $table = $this->table();
        $key = (array)$table->primaryKey();
        $alias = $table->alias();
        foreach ($key as $index => $keyname) {
            $key[$index] = $alias . '.' . $keyname;
        }
        $primaryKey = (array)$primaryKey;
        if (count($key) !== count($primaryKey)) {
            $primaryKey = $primaryKey ?: [null];
            $primaryKey = array_map(function ($key) {
                return var_export($key, true);
            }, $primaryKey);

            throw new InvalidPrimaryKeyException(sprintf('Record not found in table "%s" with primary key [%s]', $table->table(), implode($primaryKey, ', ')));
        }
        $conditions = array_combine($key, $primaryKey);
        $query = $table->find('all')->where($conditions);
        $event = $this->dispatchEvent('Action.Crud.onFindEntity', compact('query'));
        if ($event->result) {
            $query = $event->result;
        }
        $entity = $query->firstOrFail();

        return $entity;
    }

    /**
     * Save entity.
     *
     * @param EntityInterface $entity An Entity instance.
     * @return mixed
     */
    protected function _save($entity)
    {
        if ($this->table()->save($entity)) {
            return $entity;
        } else {
            throw new ValidationException(__('Validation on {0} failed', $this->table()->alias()), 0, null, $entity->errors());
        }
    }

    /**
     * Model id getter.
     *
     * @return mixed|string
     */
    public function id()
    {
        return $this->_id;
    }

    /**
     * Model id field name getter.
     *
     * @return mixed|string
     */
    public function idName()
    {
        return $this->_idName;
    }

    /**
     * Parent id getter.
     *
     * @return mixed|string
     */
    public function parentId()
    {
        return $this->_parentId;
    }

    /**
     * Parent model id field name getter.
     *
     * @return mixed|string
     */
    public function parentIdName()
    {
        return $this->_parentIdName;
    }

    /**
     * Describe table with full details.
     *
     * @return array
     */
    protected function _describe()
    {
        $table = $this->table();
        $schema = $table->schema();

        $entity = $this->_newEntity();
//		$q=$entity->visibleProperties();
        $reverseRouter = new ReverseRouting();
        $path = $reverseRouter->indexPath($this);
        $actions = [
            'index' => $reverseRouter->link('self', $path, 'GET'),
            'add' => $reverseRouter->link('add', $path, 'POST'),
            'edit' => $reverseRouter->link('edit', $path . '/{id}', 'PUT'),
            'delete' => $reverseRouter->link('delete', $path . '/{id}', 'DELETE'),
        ];

        $validators = [];
        foreach ($table->validator()
                       ->getIterator() as $name => $field) {
            $validators[$name] = [
                'validatePresence' => $field->isPresenceRequired(),
                'emptyAllowed' => $field->isEmptyAllowed(),
                'rules' => []
            ];
            foreach ($field->getIterator() as $ruleName => $rule) {
                $_rule = $rule->get('rule');
                if (is_callable($_rule)) {
                    continue;
                }
                $params = $rule->get('pass');
                if (is_callable($params)) {
                    $params = null;
                }
                if (is_array($params)) {
                    foreach ($params as &$param) {
                        if (is_callable($param)) {
                            $param = null;
                        }
                    }
                }
                $validators[$name]['rules'][$ruleName] = [
                    'message' => $rule->get('message'),
                    'on' => $rule->get('on'),
                    'rule' => $_rule,
                    'params' => $params,
                    'last' => $rule->get('last'),
                ];
            }
        }

        $labels = collection($schema->columns())
            ->map(function ($column) use ($schema) {
                return [
                    'name' => $column,
                    'label' => __(Inflector::humanize(preg_replace('/_id$/', '', $column)))
                ];
            })
            ->combine('name', 'label')
            ->toArray();

        $associationTypes = ['BelongsTo', 'HasOne', 'HasMany', 'BelongsToMany'];
        $associations = collection($associationTypes)
            ->map(function ($type) use ($table) {
                return [
                    'type' => $type,
                    'assocs' => collection($table->associations()->type($type))
                        ->map(function ($assoc) {
                            return $assoc->target()->table();
                        })
                        ->toArray()
                ];
            })
            ->combine('type', 'assocs')
            ->toArray();

        $fieldTypes = collection($schema->columns())
            ->map(function ($column) use ($schema) {
                return [
                    'name' => $column,
                    'column' => $schema->column($column)
                ];
            })
            ->combine('name', 'column')
            ->toArray();

        return [
            'entity' => [
                'hidden' => $entity->hiddenProperties(),

                // ... fields with data types
            ],
            'schema' => [
                'columns' => $fieldTypes,
                'labels' => $labels
            ],
            'validators' => $validators,
            'relations' => $associations,
            'actions' => $actions
        ];
    }
}
