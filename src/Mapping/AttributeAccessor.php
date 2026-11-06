<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use UniMethod\Frame\Mapping\Exception\{
    AttributeNotFoundException,
    GetterNotCallableException,
    SetterNotCallableException
};

/**
 * Service for working with attribute getters/setters
 * Encapsulates the logic of reading and writing values via methods or public properties
 *
 * Сервис для работы с getter/setter атрибутов
 * Инкапсулирует логику чтения и записи значений через методы или публичные свойства
 */
class AttributeAccessor
{
    /** @var array<string, array<string, ReflectionMethod>> */
    private array $methods = [];

    /** @var array<string, array<string, ?ReflectionProperty>> null — the property is not declared (dynamic) */
    private array $properties = [];

    /**
     * Determines the actual getter (method or property)
     *
     * Определяет реальный getter (метод или свойство)
     *
     * @param object $instance Object instance
     * @param string|null|false $configGetter Getter configuration from Attribute
     * @param string|null $propertyName Property name for auto-detection
     * @return string|null|false Getter name or false/null if not found
     */
    public function resolveGetter(
        object $instance,
        string|null|false $configGetter,
        ?string $propertyName,
    ): string|null|false {
        if ($configGetter === false) {
            return false;
        }

        if (is_string($configGetter)) {
            return $configGetter;
        }

        // Auto-detection: look for a public property
        //
        // Автоопределение: ищем публичное свойство
        if ($propertyName !== null && $this->hasPublicProperty($instance, $propertyName)) {
            return $propertyName;
        }

        return null;
    }

    /**
     * Determines the actual setter (method or property)
     *
     * Определяет реальный setter (метод или свойство)
     *
     * @param object $instance Object instance
     * @param string|null|false $configSetter Setter configuration from Attribute
     * @param string|null $propertyName Property name for auto-detection
     * @return string|null|false Setter name or false/null if not found
     */
    public function resolveSetter(
        object $instance,
        string|null|false $configSetter,
        ?string $propertyName,
    ): string|null|false {
        if ($configSetter === false) {
            return false;
        }

        if (is_string($configSetter)) {
            return $configSetter;
        }

        // Auto-detection: look for a public property
        //
        // Автоопределение: ищем публичное свойство
        if ($propertyName !== null && $this->hasPublicProperty($instance, $propertyName)) {
            return $propertyName;
        }

        return null;
    }

    /**
     * Reads the attribute value via the getter (method or property)
     *
     * Читает значение атрибута через getter (метод или свойство)
     *
     * @param object $instance Object instance
     * @param string $getter Getter name (method or property)
     * @return mixed Attribute value
     * @throws GetterNotCallableException
     */
    public function getValue(object $instance, string $getter): mixed
    {
        // First check the method
        //
        // Сначала проверяем метод
        if (method_exists($instance, $getter)) {
            $method = $this->findMethod($instance, $getter);
            if (!$this->isMethodCallable($instance, $method)) {
                throw new GetterNotCallableException(
                    $this->notPublicMethodMessage($instance, $method),
                );
            }

            if (
                $method->isPublic() &&
                $method->getNumberOfRequiredParameters() > 0
            ) {
                throw new GetterNotCallableException(
                    sprintf('Getter %s must take no parameters', $getter),
                );
            }

            return $instance->{$getter}();
        }

        // Then try the property
        //
        // Затем пробуем свойство
        if (property_exists($instance, $getter)) {
            $property = $this->findProperty($instance, $getter);
            if (!$this->isPropertyAccessible($instance, $property, '__get')) {
                throw new GetterNotCallableException(
                    sprintf('Property %s is not public', $getter),
                );
            }

            if (
                $property instanceof ReflectionProperty &&
                $property->isPublic() &&
                !$property->isInitialized($instance) &&
                (!method_exists($property, 'isLazy') || !$property->isLazy($instance)) &&
                !method_exists($instance, '__get')
            ) {
                throw new GetterNotCallableException(
                    sprintf('Property %s is not initialized', $getter),
                );
            }

            return $instance->{$getter};
        }

        throw new AttributeNotFoundException($instance::class, $getter);
    }

    /**
     * Writes the attribute value via the setter (method or property)
     *
     * Записывает значение атрибута через setter (метод или свойство)
     *
     * @param object $instance Object instance
     * @param string $setter Setter name (method or property)
     * @param mixed $value Value to write
     * @throws SetterNotCallableException
     * @throws AttributeNotFoundException
     */
    public function setValue(object $instance, string $setter, mixed $value): void
    {
        // First check the method
        //
        // Сначала проверяем метод
        if (method_exists($instance, $setter)) {
            $method = $this->findMethod($instance, $setter);
            if (!$this->isMethodCallable($instance, $method)) {
                throw new SetterNotCallableException(
                    $this->notPublicMethodMessage($instance, $method),
                );
            }

            if (
                $method->isPublic() &&
                $method->getNumberOfRequiredParameters() > 1
            ) {
                throw new SetterNotCallableException(
                    sprintf('Setter %s must take exactly 1 parameter', $setter),
                );
            }

            $instance->{$setter}($value);

            return;
        }

        // Then try the property
        //
        // Затем пробуем свойство
        if (property_exists($instance, $setter)) {
            $property = $this->findProperty($instance, $setter);
            if (!$this->isPropertyAccessible($instance, $property, '__set')) {
                throw new SetterNotCallableException(
                    sprintf('Property %s is not public', $setter),
                );
            }

            $instance->{$setter} = $value;

            return;
        }

        throw new AttributeNotFoundException($instance::class, $setter);
    }

    /**
     * Checks that a public property exists
     *
     * Проверяет существование публичного свойства
     *
     * @param object $instance Object instance
     * @param string $propertyName Property name
     * @return bool true if the property exists and is publicly accessible
     */
    private function hasPublicProperty(object $instance, string $propertyName): bool
    {
        return property_exists($instance, $propertyName) &&
            $this->isPropertyAccessible(
                $instance,
                $this->findProperty($instance, $propertyName),
                '__get',
            );
    }

    /**
     * A call from outside the class is possible: the method is public or intercepted by __call.
     *
     * Вызов снаружи класса возможен: метод публичный либо перехватывается __call.
     */
    private function isMethodCallable(object $instance, ReflectionMethod $method): bool
    {
        return $method->isPublic() || method_exists($instance, '__call');
    }

    /**
     * Access from outside the class is possible: the property is public (or dynamic)
     * or intercepted by a magic method.
     *
     * Доступ снаружи класса возможен: свойство публичное (или динамическое)
     * либо перехватывается магическим методом.
     */
    private function isPropertyAccessible(
        object $instance,
        ?ReflectionProperty $property,
        string $magicMethod,
    ): bool {
        return !$property instanceof ReflectionProperty ||
            $property->isPublic() ||
            method_exists($instance, $magicMethod);
    }

    private function findMethod(object $instance, string $name): ReflectionMethod
    {
        return $this->methods[$instance::class][$name] ??=
            new ReflectionMethod($instance, $name);
    }

    private function findProperty(object $instance, string $name): ?ReflectionProperty
    {
        $class = $instance::class;
        if (!array_key_exists($name, $this->properties[$class] ?? [])) {
            $reflection = new ReflectionClass($class);
            $this->properties[$class][$name] = $reflection->hasProperty($name)
                ? $reflection->getProperty($name)
                : null;
        }

        return $this->properties[$class][$name];
    }

    private function notPublicMethodMessage(object $instance, ReflectionMethod $method): string
    {
        return sprintf(
            'Call to %s method %s::%s() from outside its class',
            $method->isPrivate() ? 'private' : 'protected',
            $instance::class,
            $method->getName(),
        );
    }
}
