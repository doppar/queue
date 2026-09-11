<?php

namespace Doppar\Queue;

use Phaseolies\Database\Entity\Model;
use Phaseolies\Support\Collection;

// Provides secure serialization of Entity models in queue jobs.
// Instead of serializing entire model object
// This trait stores only the model's identifier
// Re-fetches it from the database when the job is unserialized.

/*
 * Security Benefits:
 * - Prevents exposure of hidden/protected attributes (passwords, tokens, etc.)
 * - Always fetches fresh data from database
 * - Reduces queue payload size
 * - Handles deleted models gracefully (returns null)
 */

trait InteractsWithModelSerialization
{
    /**
     * Prepare the instance for serialization.
     *
     * @return array
     */
    public function __serialize(): array
    {
        $values = [];

        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {

            if (!$property->isInitialized($this)) {
                continue;
            }

            $value = $property->getValue($this);
            $name = $property->getName();

            // If the value is Entity Model
            if ($value instanceof Model) {
                $values[$name] = $this->getSerializedPropertyValue($value);
            }
            // If the value is Collection
            elseif ($value instanceof Collection) {
                $values[$name] = $this->serializeCollection($value);
            }
            // Handle arrays that might contain models
            elseif (is_array($value)) {
                $values[$name] = $this->serializeArray($value);
            }
            // Handle standard values
            else {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * Restore the model after unserialization.
     *
     * @param array $values
     * @return void
     */
    public function __unserialize(array $values): void
    {
        $reflection = new \ReflectionClass($this);

        foreach ($values as $name => $value) {
            if (!$reflection->hasProperty($name)) {
                continue;
            }

            $property = $reflection->getProperty($name);

            // Restore serialized models
            if (is_array($value) && isset($value['__serialized_model__'])) {
                $property->setValue($this, $this->restoreModel($value));
            }
            // Restore serialized collections
            elseif (is_array($value) && isset($value['__serialized_collection__'])) {
                $property->setValue($this, $this->restoreCollection($value));
            }
            // Restore arrays that might contain models
            elseif (is_array($value)) {
                $property->setValue($this, $this->restoreArray($value));
            }
            // Restore standard values
            else {
                $property->setValue($this, $value);
            }
        }
    }

    /**
     * Get the serialized representation of a model.
     *
     * @param Model $model
     * @return array
     */
    protected function getSerializedPropertyValue(Model $model): array
    {
        return [
            '__serialized_model__' => true,
            'class' => get_class($model),
            'id' => $model->getKey(),
            'relations' => $this->serializeRelations($model),
            'connection' => $model instanceof Model ? $this->getModelConnection($model) : null,
        ];
    }

    /**
     * Get the connection name from a model
     *
     * @param Model $model
     * @return string|null
     */
    protected function getModelConnection(Model $model): ?string
    {
        try {
            $reflection = new \ReflectionClass($model);
            $property = $reflection->getProperty('connection');
            return $property->getValue($model);
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    /**
     * Serialize a collection of models.
     *
     * @param Collection $collection
     * @return array
     */
    protected function serializeCollection(Collection $collection): array
    {
        $items = [];

        foreach ($collection->all() as $item) {
            if ($item instanceof Model) {
                $items[] = $this->getSerializedPropertyValue($item);
            } else {
                $items[] = $item;
            }
        }

        return [
            '__serialized_collection__' => true,
            'class' => get_class($collection),
            'modelClass' => $this->getCollectionModelClass($collection),
            'items' => $items,
        ];
    }

    /**
     * Get the model class from a collection
     *
     * @param Collection $collection
     * @return string|null
     */
    protected function getCollectionModelClass(Collection $collection): ?string
    {
        try {
            $reflection = new \ReflectionClass($collection);
            $property = $reflection->getProperty('modelClass');
            return $property->getValue($collection);
        } catch (\ReflectionException $e) {
            // If we can't get the modelClass, try to infer from first item
            $items = $collection->all();
            if (!empty($items) && $items[0] instanceof Model) {
                return get_class($items[0]);
            }
            return null;
        }
    }

    /**
     * Serialize an array that might contain models.
     *
     * @param array $array
     * @return array
     */
    protected function serializeArray(array $array): array
    {
        return array_map(function ($value) {
            if ($value instanceof Model) {
                return $this->getSerializedPropertyValue($value);
            } elseif ($value instanceof Collection) {
                return $this->serializeCollection($value);
            } elseif (is_array($value)) {
                return $this->serializeArray($value);
            }
            return $value;
        }, $array);
    }

    /**
     * Serialize the model's loaded relationships.
     *
     * @param Model $model
     * @return array
     */
    protected function serializeRelations(Model $model): array
    {
        $relations = [];

        // Get loaded relations from the model using the public method
        foreach ($model->getRelations() as $name => $relation) {
            if ($relation instanceof Model) {
                $relations[$name] = $this->getSerializedPropertyValue($relation);
            } elseif ($relation instanceof Collection) {
                $relations[$name] = $this->serializeCollection($relation);
            } elseif (is_array($relation)) {
                // Handle array of models
                $relations[$name] = $this->serializeArray($relation);
            }
        }

        return $relations;
    }

    /**
     * Restore a serialized model.
     *
     * @param array $data
     * @return Model|null
     */
    protected function restoreModel(array $data): ?Model
    {
        if (!isset($data['class']) || !isset($data['id'])) {
            return null;
        }

        $class = $data['class'];

        // Check if class exists and is a Model
        if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
            return null;
        }

        try {
            // Use the connection if specified
            if (!empty($data['connection'])) {
                $model = $class::connection($data['connection'])
                    ->where((new $class)->getKeyName(), $data['id'])
                    ->first();
            } else {
                // Use static query method from your Model
                $model = $class::query()
                    ->where((new $class)->getKeyName(), $data['id'])
                    ->first();
            }

            // Restore relationships if the model was found
            if ($model && !empty($data['relations'])) {
                $this->restoreRelations($model, $data['relations']);
            }

            return $model;
        } catch (\Throwable $e) {
            // Log error if needed
            error("Failed to restore model {$class}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Restore a serialized collection.
     *
     * @param array $data
     * @return Collection
     */
    protected function restoreCollection(array $data): Collection
    {
        $modelClass = $data['modelClass'] ?? null;
        $restoredItems = [];

        foreach ($data['items'] as $itemData) {
            if (is_array($itemData) && isset($itemData['__serialized_model__'])) {
                $restored = $this->restoreModel($itemData);
                if ($restored !== null) {
                    $restoredItems[] = $restored;
                }
            } else {
                $restoredItems[] = $itemData;
            }
        }

        // Create a new Collection with the model class and items
        return new Collection($modelClass ?? 'array', $restoredItems);
    }

    /**
     * Restore an array that might contain serialized models.
     *
     * @param array $array
     * @return array
     */
    protected function restoreArray(array $array): array
    {
        return array_map(function ($value) {
            if (is_array($value) && isset($value['__serialized_model__'])) {
                return $this->restoreModel($value);
            } elseif (is_array($value) && isset($value['__serialized_collection__'])) {
                return $this->restoreCollection($value);
            } elseif (is_array($value)) {
                return $this->restoreArray($value);
            }
            return $value;
        }, $array);
    }

    /**
     * Restore the model relations.
     *
     * @param Model $model
     * @param array $relations
     * @return void
     */
    protected function restoreRelations(Model $model, array $relations): void
    {
        foreach ($relations as $name => $relationData) {
            if (is_array($relationData)) {
                if (isset($relationData['__serialized_model__'])) {
                    $restored = $this->restoreModel($relationData);
                    if ($restored) {
                        $model->setRelation($name, $restored);
                    }
                } elseif (isset($relationData['__serialized_collection__'])) {
                    $restored = $this->restoreCollection($relationData);
                    if ($restored) {
                        $model->setRelation($name, $restored);
                    }
                }
            }
        }
    }

    /**
     * Get the property value prepared for serialization.
     *
     * @return array
     */
    public function __sleep(): array
    {
        $serialized = $this->__serialize();

        foreach ($serialized as $key => $value) {
            $this->$key = $value;
        }

        return array_keys($serialized);
    }

    /**
     * Restore the model after unserialization.
     *
     * @return void
     */
    public function __wakeup(): void
    {
        $values = [];
        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getProperties() as $property) {
            if ($property->isInitialized($this)) {
                $values[$property->getName()] = $property->getValue($this);
            }
        }

        $this->__unserialize($values);
    }
}
