<?php
/**
 * Z-Engine framework
 *
 * @copyright Copyright 2020, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Reflection;

use FFI;
use FFI\CData;
use ReflectionProperty as NativeReflectionProperty;
use ZEngine\Core;
use ZEngine\Type\HashTable;
use ZEngine\Type\ObjectEntry;
use ZEngine\Type\StringEntry;

/**
 * Class ReflectionProperty
 *
 * typedef struct _zend_property_info {
 *     uint32_t offset; // property offset for object properties or property index for static properties
 *     uint32_t flags;
 *     zend_string *name;
 *     zend_string *doc_comment;
 *     zend_class_entry *ce;
 *     zend_type type;
 * } zend_property_info;
 */
class ReflectionProperty extends NativeReflectionProperty
{
    private CData $pointer;

    /**
     * Flag to track if readonly restriction should be bypassed
     */
    private bool $bypassReadonly = false;

    public function __construct(string $className, string $propertyName)
    {
        parent::__construct($className, $propertyName);

        $normalizedName  = strtolower($className);
        $classEntryValue = Core::$executor->classTable->find($normalizedName);
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$className} should be in the engine.");
        }
        $classEntry      = $classEntryValue->getRawClass();
        $propertiesTable = new HashTable(Core::addr($classEntry->properties_info));
        $propertyEntry = $propertiesTable->find($propertyName);
        if ($propertyEntry === null) {
            throw new \ReflectionException("Property {$propertyName} was not found in the class.");
        }
        $propertyPointer = $propertyEntry->getRawPointer();
        $this->pointer   = Core::cast('zend_property_info *', $propertyPointer);
    }

    /**
     * Creates a reflection from the zend_property_info structure
     *
     * @param CData $propertyEntry Pointer to the structure
     */
    public static function fromCData(CData $propertyEntry): ReflectionProperty
    {
        /** @var ReflectionProperty $reflectionProperty */
        $reflectionProperty = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $propertyName       = StringEntry::fromCData($propertyEntry->name);
        call_user_func(
            [$reflectionProperty, 'parent::__construct'],
            $propertyName->getStringValue()
        );
        $reflectionProperty->pointer = $propertyEntry;

        return $reflectionProperty;
    }

    /**
     * Returns an offset of this property
     */
    public function getOffset(): int
    {
        return $this->pointer->offset;
    }

    /**
     * Declares property as public
     */
    public function setPublic(): void
    {
        $this->pointer->flags &= (~Core::ZEND_ACC_PPP_MASK);
        $this->pointer->flags |= Core::ZEND_ACC_PUBLIC;
    }

    /**
     * Declares property as protected
     */
    public function setProtected(): void
    {
        $this->pointer->flags &= (~Core::ZEND_ACC_PPP_MASK);
        $this->pointer->flags |= Core::ZEND_ACC_PROTECTED;
    }

    /**
     * Declares property as private
     */
    public function setPrivate(): void
    {
        $this->pointer->flags &= (~Core::ZEND_ACC_PPP_MASK);
        $this->pointer->flags |= Core::ZEND_ACC_PRIVATE;
    }

    /**
     * Declares property as static/non-static
     */
    public function setStatic(bool $isStatic = true): void
    {
        if ($isStatic) {
            $this->pointer->flags |= Core::ZEND_ACC_STATIC;
        } else {
            $this->pointer->flags &= (~Core::ZEND_ACC_STATIC);
        }
    }

    /**
     * Sets whether readonly restriction should be bypassed
     *
     * When set to false, setValue() will use FFI to directly write to the property,
     * bypassing PHP's readonly checks without modifying internal structures.
     *
     * @param bool $isReadOnly Whether the property should be treated as readonly
     */
    public function setReadOnly(bool $isReadOnly = true): void
    {
        $this->bypassReadonly = !$isReadOnly;
    }

    /**
     * Sets the value of a property, bypassing readonly restrictions if configured
     *
     * If setReadOnly(false) was called, this method will use FFI to directly write
     * to the property's memory location, bypassing PHP's readonly checks.
     * Otherwise, it delegates to the parent implementation.
     *
     * @param mixed $objectOrValue The object instance, or the value for static properties
     * @param mixed $value The value to set (optional for static properties)
     */
    public function setValue(mixed $objectOrValue, mixed $value = null): void
    {
        // Handle static properties - if this is a static property and only one arg is passed
        if ($this->isStatic() && func_num_args() === 1) {
            parent::setValue($objectOrValue);
            return;
        }

        $object = $objectOrValue;

        if (!is_object($object)) {
            // For static properties with two args
            parent::setValue($objectOrValue, $value);
            return;
        }

        // If readonly bypass is not enabled, use parent implementation
        if (!$this->bypassReadonly) {
            parent::setValue($object, $value);
            return;
        }

        // Use FFI to bypass readonly restriction
        // Get the object's internal structure
        $objectEntry = new ObjectEntry($object);
        $reflObj = new \ReflectionClass($objectEntry);
        $ptrProp = $reflObj->getProperty('pointer');
        $objPointer = $ptrProp->getValue($objectEntry);

        // Calculate the address of the property's zval
        // The offset is in bytes from the start of the zend_object structure
        $objectBase = Core::cast('char*', $objPointer);
        $propertyZvalPtr = Core::cast('zval*', $objectBase + $this->pointer->offset);

        // Create a ReflectionValue for the new value and copy it to the property
        $newValueReflection = new ReflectionValue($value);

        // Clean the target zval first (important for refcounted values)
        $propertyZvalPtr->u1->type_info = ReflectionValue::IS_NULL;

        // Copy the new value
        $newValueReflection->copy($propertyZvalPtr);
    }

    /**
     * Gets the declaring class
     */
    public function getDeclaringClass(): ReflectionClass
    {
        return ReflectionClass::fromCData($this->pointer->ce);
    }

    /**
     * Changes the declaring class name for this property
     *
     * @param string $className New class name for this property
     * @internal
     */
    public function setDeclaringClass(string $className): void
    {
        $lcName = strtolower($className);

        $classEntryValue = Core::$executor->classTable->find($lcName);
        if ($classEntryValue === null) {
            throw new \ReflectionException("Class {$className} was not found");
        }
        $this->pointer->ce = $classEntryValue->getRawClass();
    }

    /**
     * Returns a user-friendly representation of internal structure to prevent segfault
     */
    public function __debugInfo(): array
    {
        return [
            'name'   => $this->getName(),
            'offset' => $this->getOffset(),
            'type'   => $this->getType(),
            'class'  => $this->getDeclaringClass()->getName()
        ];
    }
}
