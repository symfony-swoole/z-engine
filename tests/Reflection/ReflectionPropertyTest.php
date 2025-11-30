<?php

declare(strict_types=1);

namespace Reflection;

use FFI\CData;
use PHPUnit\Framework\TestCase;
use ZEngine\Reflection\ReflectionClass;
use ZEngine\Stub\TestClass;

class ReflectionPropertyTest extends TestCase
{
     public function testOverrideReadOnlyProperty()
    {
        $reflClass = new ReflectionClass(TestClass::class);
        $reflProperty = $reflClass->getProperty('readOnlyProperty');
        $reflProperty->setReadOnly(false);
        $instance = new TestClass();
        $reflProperty->setValue($instance, 10);
        $this->assertSame(10, $instance->readOnlyProperty);
    }
}
