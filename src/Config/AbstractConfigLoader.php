<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config;

use UniMethod\Frame\Config\Common\Impl\{
    ImplAction,
    ImplAttribute,
    ImplConfig,
    ImplListConfig,
    ImplModel,
    ImplRelation,
};
use UniMethod\Frame\Config\Common\Merger;
use UniMethod\Frame\Config\Common\Schema\{
    SchemaAction,
    SchemaAttribute,
    SchemaConfig,
    SchemaListConfig,
    SchemaModel,
    SchemaRelation,
    SchemaWriteMode,
};
use UniMethod\Frame\Config\ListAction\{
    DefaultSortValue,
    FilterDefaultValue,
    FilterOperator,
    FilterOperatorConfig,
    FilterRule,
    PaginationRule,
    SortDirection,
    SortRule,
};
use UniMethod\Frame\Type\{TypeRegistry, TypeSpecParser, UnknownTypeException};
use UniMethod\Frame\Validator\ConfigValidator;
use UniMethod\Frame\Validator\Exception\{
    InvalidConfigStructureException,
    MissingRequiredFieldException,
};

abstract class AbstractConfigLoader
{
    private ConfigValidator $configValidator;

    private TypeSpecParser $typeSpecParser;

    /**
     * @param TypeRegistry|null $typeRegistry Custom attribute types and typeSpec
     */
    public function __construct(
        ?TypeRegistry $typeRegistry = null,
        ?ConfigValidator $configValidator = null,
    ) {
        $this->configValidator = $configValidator ?? new ConfigValidator();
        $this->typeSpecParser = new TypeSpecParser($typeRegistry);
    }

    /**
     * Loads the configuration from a file.
     *
     * Загружает конфигурацию из файла.
     *
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    abstract public function load(string $filePath): Config;

    /**
     * Loads the configuration from a string.
     *
     * Загружает конфигурацию из строки.
     *
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    abstract public function loadFromString(string $content): Config;

    /**
     * @param array<mixed> $data
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function createConfig(array $data): Config
    {
        return $this->createConfigFromSplit($data);
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidConfigStructureException
     */
    protected function validateRequiredField(
        array $data,
        string $field,
        string $path,
    ): void {
        if (!array_key_exists($field, $data)) {
            throw new MissingRequiredFieldException($field, $path);
        }

        if (!is_string($data[$field]) || trim($data[$field]) === "") {
            throw new InvalidConfigStructureException(
                sprintf(
                    "Field '%s' in %s must be a non-empty string",
                    $field,
                    $path,
                ),
            );
        }
    }

    /**
     * Checks that the string is a valid PHP property/method name.
     *
     * Проверяет, что строка является допустимым именем свойства/метода PHP.
     */
    protected function validateIdentifier(string $value, string $path): void
    {
        if (
            preg_match('/^[\p{L}_][\p{L}\p{M}\p{N}_]*$/u', $value) !== 1
        ) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "Invalid identifier '%s' in %s: only letters, digits, underscores and UTF-8 letters are allowed, " .
                    "and it must not start with a digit",
                    $value,
                    $path,
                ),
            );
        }
    }

    /**
     * Converts the value to string|null|false
     *
     * Преобразует значение в string|null|false
     */
    protected function castToStringOrNullOrFalse(mixed $data): string|null|false
    {
        if ($data === null) {
            return null;
        }

        if ($data === false) {
            return false;
        }

        if (is_string($data)) {
            if (strtolower($data) === "false") {
                return false;
            }

            return $data;
        }

        if (is_scalar($data)) {
            return (string) $data;
        }

        return null;
    }

    protected function castToStringOrNull(mixed $data): ?string
    {
        if ($data === null) {
            return null;
        }

        if (is_string($data)) {
            return $data;
        }

        if (is_scalar($data)) {
            return (string) $data;
        }

        return null;
    }

    protected function castToBool(mixed $data): bool
    {
        if (is_bool($data)) {
            return $data;
        }

        if (is_int($data)) {
            return $data !== 0;
        }

        if (is_string($data)) {
            return in_array(strtolower($data), ["true", "1", "yes"], true);
        }

        return false;
    }

    /**
     * @throws InvalidConfigStructureException
     */
    protected function parseActionType(string $type, string $path): ActionType
    {
        return ActionType::tryFrom($type) ??
            throw new InvalidConfigStructureException(
                sprintf(
                    "Invalid action type '%s' in %s. Allowed values: %s",
                    $type,
                    $path,
                    implode(
                        ", ",
                        array_map(
                            fn (ActionType $t) => $t->value,
                            ActionType::cases(),
                        ),
                    ),
                ),
            );
    }

    /**
     * @return array<string, WritableConfig>|null
     * @throws InvalidConfigStructureException
     */
    protected function loadWritable(mixed $data, string $path): ?array
    {
        if ($data === null) {
            return null;
        }

        if (!is_array($data)) {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s' must be an array or null", $path),
            );
        }

        if (array_is_list($data)) {
            $result = [];
            foreach ($data as $index => $item) {
                if (!is_string($item) || trim($item) === "") {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Item at index %d in '%s' must be a non-empty string",
                            $index,
                            $path,
                        ),
                    );
                }

                $result[$item] = new WritableConfig(
                    RelationWriteMode::Reference,
                );
            }

            return $result;
        }

        $result = [];
        foreach ($data as $relation => $modeData) {
            if (!is_string($relation) || trim($relation) === "") {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Invalid relation key in '%s': must be a non-empty string",
                        $path,
                    ),
                );
            }

            if (is_array($modeData) && !array_is_list($modeData)) {
                if (
                    !isset($modeData["mode"]) ||
                    !is_string($modeData["mode"]) ||
                    trim($modeData["mode"]) === ""
                ) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Field 'mode' in '%s[%s]' must be a non-empty string",
                            $path,
                            $relation,
                        ),
                    );
                }

                $enum = RelationWriteMode::tryFrom($modeData["mode"]);
                if ($enum === null) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Invalid write mode '%s' in %s[%s]. Allowed values: %s",
                            $modeData["mode"],
                            $path,
                            $relation,
                            implode(
                                ", ",
                                array_map(
                                    static fn (
                                        RelationWriteMode $m,
                                    ) => $m->value,
                                    RelationWriteMode::cases(),
                                ),
                            ),
                        ),
                    );
                }

                $access = $this->loadAccessConfig(
                    $modeData["access"] ?? null,
                    sprintf("%s[%s].access", $path, $relation),
                );

                $result[$relation] = new WritableConfig($enum, $access);
            } else {
                if (!is_string($modeData) || trim($modeData) === "") {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Mode for relation '%s' in '%s' must be a non-empty string",
                            $relation,
                            $path,
                        ),
                    );
                }

                $enum = RelationWriteMode::tryFrom($modeData);
                if ($enum === null) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Invalid write mode '%s' in %s[%s]. Allowed values: %s",
                            $modeData,
                            $path,
                            $relation,
                            implode(
                                ", ",
                                array_map(
                                    static fn (
                                        RelationWriteMode $m,
                                    ) => $m->value,
                                    RelationWriteMode::cases(),
                                ),
                            ),
                        ),
                    );
                }

                $result[$relation] = new WritableConfig($enum);
            }
        }

        return $result;
    }

    /**
     * @return list<string>|null
     * @throws InvalidConfigStructureException
     */
    protected function loadStringArray(mixed $data, string $path): ?array
    {
        if ($data === null) {
            return null;
        }

        if (!is_array($data)) {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s' must be an array or null", $path),
            );
        }

        if ($data !== [] && !array_is_list($data)) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "Field '%s' must be a list (not an associative array)",
                    $path,
                ),
            );
        }

        $result = [];

        foreach ($data as $index => $item) {
            if (!is_string($item)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Item at index %d in '%s' must be a string",
                        $index,
                        $path,
                    ),
                );
            }

            if (trim($item) === "") {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Item at index %d in '%s' must be a non-empty string",
                        $index,
                        $path,
                    ),
                );
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * @return array<string, AccessConfig|null>|null
     * @throws InvalidConfigStructureException
     */
    protected function loadIncludable(mixed $data, string $path): ?array
    {
        if ($data === null) {
            return null;
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s' must be an object or null", $path),
            );
        }

        $result = [];
        foreach ($data as $includePath => $accessData) {
            if (!is_string($includePath) || trim($includePath) === "") {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Invalid key in '%s': must be a non-empty string",
                        $path,
                    ),
                );
            }

            $result[$includePath] = $this->loadAccessConfig(
                $accessData,
                sprintf("%s[%s]", $path, $includePath),
            );
        }

        return $result;
    }

    /**
     * @throws InvalidConfigStructureException
     */
    protected function loadAccessConfig(
        mixed $data,
        string $path,
    ): ?AccessConfig {
        if ($data === null) {
            return null;
        }

        if (!is_array($data)) {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s' must be an object or null", $path),
            );
        }

        $authRequired = $this->castToBool($data["authRequired"] ?? false);
        $roles = null;

        if (isset($data["roles"])) {
            if (!is_array($data["roles"]) || !array_is_list($data["roles"])) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Field '%s.roles' must be a list of strings",
                        $path,
                    ),
                );
            }

            $roles = [];
            foreach ($data["roles"] as $i => $r) {
                if (!is_string($r) || trim($r) === "") {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Invalid role at index %d in '%s.roles'",
                            $i,
                            $path,
                        ),
                    );
                }

                $roles[] = $r;
            }
        }

        $config = new AccessConfig(authRequired: $authRequired, roles: $roles);

        if ($config->isTrivial()) {
            return null;
        }

        return $config;
    }

    /**
     * @throws InvalidConfigStructureException
     */
    protected function loadPaginationRule(
        mixed $data,
        string $path,
    ): ?PaginationRule {
        if ($data === null) {
            return null;
        }

        if (!is_array($data)) {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s' must be an object or null", $path),
            );
        }

        $maxSize = PaginationRule::DEFAULT_MAX_SIZE;
        if (array_key_exists("maxSize", $data)) {
            $value = $data["maxSize"];
            if ($value !== null && !is_int($value)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Field '%s.maxSize' must be an integer or null",
                        $path,
                    ),
                );
            }

            // An explicit null = a deliberate removal of the page size limit.
            //
            // Явный null = осознанное снятие лимита размера страницы.
            $maxSize = $value;
        }

        $defaultSize = 20;
        if (isset($data["defaultSize"])) {
            if (!is_int($data["defaultSize"])) {
                throw new InvalidConfigStructureException(
                    sprintf("Field '%s.defaultSize' must be an integer", $path),
                );
            }

            $defaultSize = $data["defaultSize"];
        }

        return new PaginationRule(maxSize: $maxSize, defaultSize: $defaultSize);
    }

    /**
     * @param array<mixed> $data
     * @return SortRule[]
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function loadSortRules(array $data, string $path): array
    {
        if ($data !== [] && !array_is_list($data)) {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s' must be a list", $path),
            );
        }

        $rules = [];

        foreach ($data as $index => $ruleData) {
            if (!is_array($ruleData)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Item at index %d in '%s' must be an object",
                        $index,
                        $path,
                    ),
                );
            }

            $this->validateRequiredField(
                $ruleData,
                "path",
                sprintf("%s[%d]", $path, $index),
            );
            /** @var string $sortPath */
            $sortPath = $ruleData["path"];

            $defaultDirection = null;
            if (isset($ruleData["defaultDirection"])) {
                $defaultDirection = $this->parseSortDirection(
                    $ruleData["defaultDirection"],
                    sprintf("%s[%d].defaultDirection", $path, $index),
                );
            }

            $required = $this->castToBool($ruleData["required"] ?? false);

            $defaultValue = null;
            if (isset($ruleData["defaultValue"])) {
                $defaultValue = $this->loadDefaultSortValue(
                    $ruleData["defaultValue"],
                    sprintf("%s[%d].defaultValue", $path, $index),
                );
            }

            $comment = $this->castToStringOrNull($ruleData["comment"] ?? null);

            $rules[] = new SortRule(
                path: $sortPath,
                defaultDirection: $defaultDirection,
                required: $required,
                defaultValue: $defaultValue,
                comment: $comment,
            );
        }

        return $rules;
    }

    /**
     * @throws InvalidConfigStructureException
     */
    protected function parseSortDirection(
        mixed $direction,
        string $path,
    ): SortDirection {
        if (!is_string($direction)) {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s' must be a string", $path),
            );
        }

        return SortDirection::tryFrom($direction) ??
            throw new InvalidConfigStructureException(
                sprintf(
                    "Invalid sort direction '%s' in %s. Allowed values: %s",
                    $direction,
                    $path,
                    implode(
                        ", ",
                        array_map(
                            fn (SortDirection $d) => $d->value,
                            SortDirection::cases(),
                        ),
                    ),
                ),
            );
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function loadDefaultSortValue(
        array $data,
        string $path,
    ): DefaultSortValue {
        $this->validateRequiredField($data, "path", $path);
        /** @var string $sortPath */
        $sortPath = $data["path"];

        $this->validateRequiredField($data, "direction", $path);
        /** @var string $directionString */
        $directionString = $data["direction"];

        $direction = $this->parseSortDirection(
            $directionString,
            sprintf("%s.direction", $path),
        );

        return new DefaultSortValue(path: $sortPath, direction: $direction);
    }

    /**
     * @param array<mixed> $data
     * @return FilterRule[]
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function loadFilterRules(array $data, string $path): array
    {
        if ($data !== [] && !array_is_list($data)) {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s' must be a list", $path),
            );
        }

        $rules = [];

        foreach ($data as $index => $ruleData) {
            if (!is_array($ruleData)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Item at index %d in '%s' must be an object",
                        $index,
                        $path,
                    ),
                );
            }

            $this->validateRequiredField(
                $ruleData,
                "path",
                sprintf("%s[%d]", $path, $index),
            );
            /** @var string $filterPath */
            $filterPath = $ruleData["path"];

            if (
                !isset($ruleData["operators"]) ||
                !is_array($ruleData["operators"])
            ) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Field '%s[%d].operators' is required and must be an array",
                        $path,
                        $index,
                    ),
                );
            }

            $operators = $this->loadFilterOperators(
                $ruleData["operators"],
                sprintf("%s[%d].operators", $path, $index),
            );

            $required = $this->castToBool($ruleData["required"] ?? false);

            $defaultValue = null;
            if (isset($ruleData["defaultValue"])) {
                $defaultValue = $this->loadFilterDefaultValue(
                    $ruleData["defaultValue"],
                    sprintf("%s[%d].defaultValue", $path, $index),
                );
            }

            $comment = $this->castToStringOrNull($ruleData["comment"] ?? null);

            $typeSpec = null;
            if (isset($ruleData["typeSpec"])) {
                if (!is_string($ruleData["typeSpec"])) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Field '%s[%d].typeSpec' must be a string",
                            $path,
                            $index,
                        ),
                    );
                }

                try {
                    $typeSpec = $this->typeSpecParser->parse(
                        $ruleData["typeSpec"],
                    );
                } catch (UnknownTypeException $e) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Field '%s[%d].typeSpec' contains unknown type: %s",
                            $path,
                            $index,
                            $e->getMessage(),
                        ),
                    );
                }
            }

            $rules[] = new FilterRule(
                path: $filterPath,
                operators: $operators,
                required: $required,
                defaultValue: $defaultValue,
                comment: $comment,
                typeSpec: $typeSpec,
            );
        }

        return $rules;
    }

    /**
     * @param array<mixed> $data
     * @return list<FilterOperator|FilterOperatorConfig>
     * @throws InvalidConfigStructureException
     */
    protected function loadFilterOperators(array $data, string $path): array
    {
        if (!array_is_list($data)) {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s' must be a list", $path),
            );
        }

        $operators = [];

        foreach ($data as $index => $item) {
            $itemPath = sprintf("%s[%d]", $path, $index);

            if (is_array($item) && !array_is_list($item)) {
                if (
                    !isset($item["operator"]) ||
                    !is_string($item["operator"]) ||
                    trim($item["operator"]) === ""
                ) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Field 'operator' in '%s' must be a non-empty string",
                            $itemPath,
                        ),
                    );
                }

                $operator = $this->parseFilterOperator(
                    $item["operator"],
                    sprintf("%s.operator", $itemPath),
                );

                $access = $this->loadAccessConfig(
                    $item["access"] ?? null,
                    sprintf("%s.access", $itemPath),
                );

                $operators[] = new FilterOperatorConfig($operator, $access);
                continue;
            }

            if (!is_string($item)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Item at index %d in '%s' must be a string",
                        $index,
                        $path,
                    ),
                );
            }

            $operator = $this->parseFilterOperator($item, $itemPath);
            $operators[] = $operator;
        }

        return $operators;
    }

    /**
     * @throws InvalidConfigStructureException
     */
    protected function parseFilterOperator(
        string $operator,
        string $path,
    ): FilterOperator {
        return FilterOperator::tryFrom($operator) ??
            throw new InvalidConfigStructureException(
                sprintf(
                    "Invalid filter operator '%s' in %s. Allowed values: %s",
                    $operator,
                    $path,
                    implode(
                        ", ",
                        array_map(
                            fn (FilterOperator $o) => $o->value,
                            FilterOperator::cases(),
                        ),
                    ),
                ),
            );
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function loadFilterDefaultValue(
        array $data,
        string $path,
    ): FilterDefaultValue {
        $this->validateRequiredField($data, "operator", $path);
        /** @var string $operatorString */
        $operatorString = $data["operator"];

        $operator = $this->parseFilterOperator(
            $operatorString,
            sprintf("%s.operator", $path),
        );

        if (!array_key_exists("value", $data)) {
            throw new MissingRequiredFieldException("value", $path);
        }

        return new FilterDefaultValue(
            operator: $operator,
            value: $data["value"],
        );
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function createConfigFromSplit(array $data): Config
    {
        if (!is_array($data["schema"] ?? null)) {
            throw new InvalidConfigStructureException(
                "Field 'schema' must be an object",
            );
        }

        if (!is_array($data["impl"] ?? null)) {
            throw new InvalidConfigStructureException(
                "Field 'impl' must be an object",
            );
        }

        $schema = $this->loadSchemaConfig($data["schema"]);
        $impl = $this->loadImplConfig($data["impl"]);

        $this->configValidator->validateImplDictionaries($impl);
        $this->configValidator->validateNoOrphanedImpl($schema, $impl);
        $this->configValidator->validateNoMissingImpl($schema, $impl);
        $this->configValidator->validateSchemaImplConsistency($schema, $impl);

        $config = (new Merger())->merge($schema, $impl);

        if ($config->userModel !== null) {
            $this->configValidator->validateUserModel(
                $config->models,
                $config->userModel,
            );
        }

        $this->configValidator->validateModelIds($config->models);
        $this->configValidator->validateWritablePaths($config->actions);
        $this->configValidator->validateUpdateCollectionSetters(
            $config->models,
            $config->actions,
        );
        $this->configValidator->validateListRules(
            $config->models,
            $config->actions,
        );
        $this->configValidator->validateBoolFilters(
            $config->models,
            $config->actions,
        );
        $this->configValidator->validateListFilters(
            $config->models,
            $config->actions,
            $config->defaultLocator,
        );
        $this->configValidator->validateLocators(
            $config->models,
            $config->actions,
            $config->locators,
            $config->defaultLocator,
        );
        $this->configValidator->validateSavers(
            $config->actions,
            $config->savers,
        );
        $this->configValidator->validateRemovers(
            $config->actions,
            $config->removers,
        );
        $this->configValidator->validateMutators(
            $config->models,
            $config->mutators,
        );
        $this->configValidator->validateNoOrphanedMutators(
            $config->models,
            $config->mutators,
        );
        $this->configValidator->validateRelationMutators(
            $config->models,
            $config->relationMutators,
        );
        $this->configValidator->validateNoOrphanedRelationMutators(
            $config->models,
            $config->relationMutators,
        );

        return $config;
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function loadSchemaConfig(array $data): SchemaConfig
    {
        return new SchemaConfig(
            models: $this->loadSchemaModels($data["models"] ?? []),
            actions: $this->loadSchemaActions($data["actions"] ?? []),
        );
    }

    /**
     * @param array<mixed> $data
     * @return SchemaModel[]
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function loadSchemaModels(array $data): array
    {
        if (array_is_list($data) && $data !== []) {
            throw new InvalidConfigStructureException(
                "schema.models must be an object, not an array",
            );
        }

        $models = [];

        foreach ($data as $type => $modelData) {
            if (!is_string($type) || trim($type) === "") {
                throw new InvalidConfigStructureException(
                    "Model key must be a non-empty string",
                );
            }

            if (!is_array($modelData)) {
                throw new InvalidConfigStructureException(
                    sprintf("schema.models[%s] must be an object", $type),
                );
            }

            $models[] = new SchemaModel(
                type: $type,
                attributes: $this->loadSchemaAttributes(
                    $modelData["attributes"] ?? [],
                    $type,
                ),
                relations: $this->loadSchemaRelations(
                    $modelData["relations"] ?? [],
                    $type,
                ),
                comment: $this->castToStringOrNull(
                    $modelData["comment"] ?? null,
                ),
            );
        }

        return $models;
    }

    /**
     * @param array<mixed> $data
     * @return SchemaAttribute[]
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function loadSchemaAttributes(
        array $data,
        string $modelType,
    ): array {
        if ($data === []) {
            return [];
        }

        if (array_is_list($data)) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "schema.models[%s].attributes must be an object, not an array",
                    $modelType,
                ),
            );
        }

        $attributes = [];

        foreach ($data as $name => $attrData) {
            if (!is_string($name) || trim($name) === "") {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Attribute key in schema.models[%s] must be a non-empty string",
                        $modelType,
                    ),
                );
            }

            if (!is_array($attrData)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "schema.models[%s].attributes[%s] must be an object",
                        $modelType,
                        $name,
                    ),
                );
            }

            $this->validateRequiredField(
                $attrData,
                "type",
                sprintf("schema.models[%s].attributes[%s]", $modelType, $name),
            );

            try {
                $this->typeSpecParser->parse($attrData["type"]);
            } catch (UnknownTypeException $e) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Field 'schema.models[%s].attributes[%s].type' contains unknown type: %s",
                        $modelType,
                        $name,
                        $e->getMessage(),
                    ),
                );
            }

            $writeMode = null;
            if (array_key_exists("writable", $attrData)) {
                $raw = $attrData["writable"];
                if ($raw === false || $raw === "false") {
                    $writeMode = SchemaWriteMode::ReadOnly;
                } elseif ($raw === true || $raw === "true") {
                    $writeMode = SchemaWriteMode::Write;
                } elseif ($raw === SchemaWriteMode::CreateOnly->value) {
                    $writeMode = SchemaWriteMode::CreateOnly;
                } else {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Invalid 'writable' value in schema.models[%s].attributes[%s]: use true, false, or " .
                            "'create-only'",
                            $modelType,
                            $name,
                        ),
                    );
                }
            }

            $mutators = null;
            if (
                isset($attrData["mutators"]) &&
                is_array($attrData["mutators"])
            ) {
                $mutators = $this->loadMutators(
                    $attrData["mutators"],
                    sprintf(
                        "schema.models[%s].attributes[%s].mutators",
                        $modelType,
                        $name,
                    ),
                );
            }

            $attributes[] = new SchemaAttribute(
                name: $name,
                type: $attrData["type"],
                writeMode: $writeMode,
                mutators: $mutators,
                comment: $this->castToStringOrNull(
                    $attrData["comment"] ?? null,
                ),
            );
        }

        return $attributes;
    }

    /**
     * @param array<mixed> $data
     * @return SchemaRelation[]
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function loadSchemaRelations(
        array $data,
        string $modelType,
    ): array {
        if ($data === []) {
            return [];
        }

        if (array_is_list($data)) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "schema.models[%s].relations must be an object, not an array",
                    $modelType,
                ),
            );
        }

        $relations = [];

        foreach ($data as $name => $relData) {
            if (!is_string($name) || trim($name) === "") {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Relation key in schema.models[%s] must be a non-empty string",
                        $modelType,
                    ),
                );
            }

            if (!is_array($relData)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "schema.models[%s].relations[%s] must be an object",
                        $modelType,
                        $name,
                    ),
                );
            }

            $this->validateRequiredField(
                $relData,
                "type",
                sprintf("schema.models[%s].relations[%s]", $modelType, $name),
            );

            $this->validateRequiredField(
                $relData,
                "targetModel",
                sprintf("schema.models[%s].relations[%s]", $modelType, $name),
            );

            $relationTypes = [
                Relation::ONE_TO_ONE,
                Relation::ONE_TO_MANY,
                Relation::MANY_TO_ONE,
                Relation::MANY_TO_MANY,
            ];
            if (!in_array($relData["type"], $relationTypes, true)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Invalid relation type '%s' in schema.models[%s].relations[%s]. Allowed values: %s",
                        $relData["type"],
                        $modelType,
                        $name,
                        implode(", ", $relationTypes),
                    ),
                );
            }

            $mutators = null;
            if (isset($relData["mutators"]) && is_array($relData["mutators"])) {
                $mutators = $this->loadMutators(
                    $relData["mutators"],
                    sprintf(
                        "schema.models[%s].relations[%s].mutators",
                        $modelType,
                        $name,
                    ),
                );
            }

            $relations[] = new SchemaRelation(
                name: $name,
                type: $relData["type"],
                targetModel: $relData["targetModel"],
                mutators: $mutators,
                comment: $this->castToStringOrNull($relData["comment"] ?? null),
            );
        }

        return $relations;
    }

    /**
     * @param array<mixed> $data
     * @return SchemaAction[]
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function loadSchemaActions(array $data): array
    {
        if ($data === []) {
            return [];
        }

        if (array_is_list($data)) {
            throw new InvalidConfigStructureException(
                "schema.actions must be an object, not an array",
            );
        }

        $actions = [];

        foreach ($data as $model => $actionsData) {
            if (!is_string($model) || trim($model) === "") {
                throw new InvalidConfigStructureException(
                    "Action model key must be a non-empty string",
                );
            }

            if (!is_array($actionsData) || array_is_list($actionsData)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "schema.actions[%s] must be an object, not an array",
                        $model,
                    ),
                );
            }

            foreach ($actionsData as $typeString => $actionData) {
                if (!is_array($actionData)) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "schema.actions[%s][%s] must be an object",
                            $model,
                            $typeString,
                        ),
                    );
                }

                $type = $this->parseActionType(
                    $typeString,
                    sprintf("schema.actions[%s][%s]", $model, $typeString),
                );

                $listConfig = null;
                if ($type === ActionType::List) {
                    $listConfig = $this->loadSchemaListConfig(
                        $actionData["config"] ?? null,
                        sprintf(
                            "schema.actions[%s][%s].config",
                            $model,
                            $typeString,
                        ),
                    );
                }

                $actions[] = new SchemaAction(
                    type: $type,
                    model: $model,
                    access: $this->loadAccessConfig(
                        $actionData["access"] ?? null,
                        sprintf(
                            "schema.actions[%s][%s].access",
                            $model,
                            $typeString,
                        ),
                    ),
                    writable: $this->loadWritable(
                        $actionData["writable"] ?? null,
                        sprintf(
                            "schema.actions[%s][%s].writable",
                            $model,
                            $typeString,
                        ),
                    ),
                    include: $this->loadStringArray(
                        $actionData["include"] ?? null,
                        sprintf(
                            "schema.actions[%s][%s].include",
                            $model,
                            $typeString,
                        ),
                    ),
                    includable: $this->loadIncludable(
                        $actionData["includable"] ?? null,
                        sprintf(
                            "schema.actions[%s][%s].includable",
                            $model,
                            $typeString,
                        ),
                    ),
                    listConfig: $listConfig,
                    comment: $this->castToStringOrNull(
                        $actionData["comment"] ?? null,
                    ),
                );
            }
        }

        return $actions;
    }

    /**
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function loadSchemaListConfig(
        mixed $data,
        string $path,
    ): ?SchemaListConfig {
        if ($data === null) {
            return null;
        }

        if (!is_array($data)) {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s' must be an object or null", $path),
            );
        }

        foreach ($data as $key => $value) {
            if (in_array($key, ["pagination", "sort", "filters"], true)) {
                continue;
            }

            // Legacy format: an explicit null for class/repository in the schema section
            // is allowed (it carries no information); a non-empty value is an error,
            // these keys belong to the impl section
            //
            // Легаси-формат: явный null для class/repository в schema-секции
            // допустим (информации не несёт); непустое значение — ошибка,
            // эти ключи принадлежат impl-секции
            if (
                in_array($key, ["class", "repository"], true) &&
                $value === null
            ) {
                continue;
            }

            if (in_array($key, ["class", "repository"], true)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Key '%s' in '%s' must be set in the impl section",
                        $key,
                        $path,
                    ),
                );
            }

            throw new InvalidConfigStructureException(
                sprintf(
                    "Unknown key '%s' in '%s'. Allowed keys: pagination, sort, filters",
                    $key,
                    $path,
                ),
            );
        }

        return new SchemaListConfig(
            pagination: $this->loadPaginationRule(
                $data["pagination"] ?? null,
                sprintf("%s.pagination", $path),
            ),
            sort: $this->loadSortRules(
                $data["sort"] ?? [],
                sprintf("%s.sort", $path),
            ),
            filters: $this->loadFilterRules(
                $data["filters"] ?? [],
                sprintf("%s.filters", $path),
            ),
        );
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function loadImplConfig(array $data): ImplConfig
    {
        return new ImplConfig(
            models: $this->loadImplModels($data["models"] ?? []),
            actions: $this->loadImplActions($data["actions"] ?? []),
            locators: $this->loadImplDictionary($data, "locators"),
            savers: $this->loadImplDictionary($data, "savers"),
            removers: $this->loadImplDictionary($data, "removers"),
            mutators: $this->loadImplDictionary($data, "mutators"),
            relationMutators: $this->loadImplDictionary(
                $data,
                "relationMutators",
            ),
            userModel: $this->castToStringOrNull($data["userModel"] ?? null),
            defaultLocator: $this->loadLocatorAlias($data["defaultLocator"] ?? null, "impl.defaultLocator"),
        );
    }

    /**
     * Locator alias: absent (null) or a non-empty string. The existence of
     * the alias in impl.locators is checked by ConfigValidator::validateLocators.
     *
     * Алиас локатора: отсутствует (null) или непустая строка. Существование
     * алиаса в impl.locators проверяет ConfigValidator::validateLocators.
     *
     * @throws InvalidConfigStructureException
     */
    protected function loadLocatorAlias(mixed $value, string $path): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || trim($value) === "") {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s' must be a non-empty string", $path),
            );
        }

        return $value;
    }

    /**
     * The structure of the elements is checked by ConfigValidator::validateImplDictionaries.
     *
     * Структура элементов проверяет ConfigValidator::validateImplDictionaries.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     * @throws InvalidConfigStructureException
     */
    protected function loadImplDictionary(array $data, string $name): array
    {
        $dict = $data[$name] ?? [];
        if (!is_array($dict)) {
            throw new InvalidConfigStructureException(
                sprintf("impl.%s must be an object", $name),
            );
        }

        return $dict;
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidConfigStructureException
     */
    protected function loadMutators(array $data, string $path): Mutators
    {
        return new Mutators(
            afterInput: $this->loadMutatorPhase($data, "afterInput", $path),
            afterValidate: $this->loadMutatorPhase($data, "afterValidate", $path),
            beforeOutput: $this->loadMutatorPhase($data, "beforeOutput", $path),
        );
    }

    /**
     * @param array<mixed> $data
     * @return list<string>
     * @throws InvalidConfigStructureException
     */
    protected function loadMutatorPhase(
        array $data,
        string $phase,
        string $path,
    ): array {
        $aliases = $data[$phase] ?? [];
        $phasePath = sprintf("%s.%s", $path, $phase);

        if (!is_array($aliases) || !array_is_list($aliases)) {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s' must be a list of strings", $phasePath),
            );
        }

        foreach ($aliases as $index => $alias) {
            if (!is_string($alias) || trim($alias) === "") {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Item at index %d in '%s' must be a non-empty string",
                        $index,
                        $phasePath,
                    ),
                );
            }
        }

        return $aliases;
    }

    /**
     * @param array<mixed> $data
     * @return ImplModel[]
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function loadImplModels(array $data): array
    {
        if (array_is_list($data) && $data !== []) {
            throw new InvalidConfigStructureException(
                "impl.models must be an object, not an array",
            );
        }

        $models = [];

        foreach ($data as $type => $modelData) {
            if (!is_string($type) || trim($type) === "") {
                throw new InvalidConfigStructureException(
                    "Model key must be a non-empty string",
                );
            }

            if (!is_array($modelData)) {
                throw new InvalidConfigStructureException(
                    sprintf("impl.models[%s] must be an object", $type),
                );
            }

            $this->validateRequiredField(
                $modelData,
                "class",
                sprintf("impl.models[%s]", $type),
            );

            $models[] = new ImplModel(
                type: $type,
                class: $modelData["class"],
                attributes: $this->loadImplAttributes(
                    $modelData["attributes"] ?? [],
                    $type,
                ),
                relations: $this->loadImplRelations(
                    $modelData["relations"] ?? [],
                    $type,
                ),
                entityLocator: $this->loadLocatorAlias(
                    $modelData["entityLocator"] ?? null,
                    sprintf("impl.models[%s].entityLocator", $type),
                ),
            );
        }

        return $models;
    }

    /**
     * @param array<mixed> $data
     * @return ImplAttribute[]
     * @throws InvalidConfigStructureException
     */
    protected function loadImplAttributes(array $data, string $modelType): array
    {
        if ($data === []) {
            return [];
        }

        if (array_is_list($data)) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "impl.models[%s].attributes must be an object, not an array",
                    $modelType,
                ),
            );
        }

        $attributes = [];

        foreach ($data as $name => $attrData) {
            if (!is_array($attrData)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "impl.models[%s].attributes[%s] must be an object",
                        $modelType,
                        $name,
                    ),
                );
            }

            $getter = $this->castToStringOrNullOrFalse(
                $attrData["getter"] ?? null,
            );
            $setter = $this->castToStringOrNullOrFalse(
                $attrData["setter"] ?? null,
            );

            if (is_string($getter)) {
                $this->validateIdentifier(
                    $getter,
                    sprintf(
                        "impl.models[%s].attributes[%s].getter",
                        $modelType,
                        $name,
                    ),
                );
            }

            if (is_string($setter)) {
                $this->validateIdentifier(
                    $setter,
                    sprintf(
                        "impl.models[%s].attributes[%s].setter",
                        $modelType,
                        $name,
                    ),
                );
            }

            $mutatorSetter = $this->castToStringOrNullOrFalse(
                $attrData["mutatorSetter"] ?? null,
            );

            if (is_string($mutatorSetter)) {
                $this->validateIdentifier(
                    $mutatorSetter,
                    sprintf(
                        "impl.models[%s].attributes[%s].mutatorSetter",
                        $modelType,
                        $name,
                    ),
                );
            }

            $attributes[] = new ImplAttribute(
                name: $name,
                getter: $getter,
                setter: $setter,
                mutatorSetter: $mutatorSetter,
                constructorParam: $this->castToBool(
                    $attrData["constructorParam"] ?? false,
                ),
            );
        }

        return $attributes;
    }

    /**
     * @param array<mixed> $data
     * @return ImplRelation[]
     * @throws InvalidConfigStructureException
     */
    protected function loadImplRelations(array $data, string $modelType): array
    {
        if ($data === []) {
            return [];
        }

        if (array_is_list($data)) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "impl.models[%s].relations must be an object, not an array",
                    $modelType,
                ),
            );
        }

        $relations = [];

        foreach ($data as $name => $relData) {
            if (!is_array($relData)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "impl.models[%s].relations[%s] must be an object",
                        $modelType,
                        $name,
                    ),
                );
            }

            $getter = $this->castToStringOrNullOrFalse(
                $relData["getter"] ?? null,
            );
            $setter = $this->castToStringOrNullOrFalse(
                $relData["setter"] ?? null,
            );
            $add = $this->castToStringOrNullOrFalse($relData["add"] ?? null);

            if (is_string($getter)) {
                $this->validateIdentifier(
                    $getter,
                    sprintf(
                        "impl.models[%s].relations[%s].getter",
                        $modelType,
                        $name,
                    ),
                );
            }

            if (is_string($setter)) {
                $this->validateIdentifier(
                    $setter,
                    sprintf(
                        "impl.models[%s].relations[%s].setter",
                        $modelType,
                        $name,
                    ),
                );
            }

            if (is_string($add)) {
                $this->validateIdentifier(
                    $add,
                    sprintf(
                        "impl.models[%s].relations[%s].add",
                        $modelType,
                        $name,
                    ),
                );
            }

            $mutatorSetter = $this->castToStringOrNullOrFalse(
                $relData["mutatorSetter"] ?? null,
            );

            if (is_string($mutatorSetter)) {
                $this->validateIdentifier(
                    $mutatorSetter,
                    sprintf(
                        "impl.models[%s].relations[%s].mutatorSetter",
                        $modelType,
                        $name,
                    ),
                );
            }

            $relations[] = new ImplRelation(
                name: $name,
                getter: $getter,
                setter: $setter,
                add: $add,
                mutatorSetter: $mutatorSetter,
                constructorParam: $this->castToBool(
                    $relData["constructorParam"] ?? false,
                ),
            );
        }

        return $relations;
    }

    /**
     * @param array<mixed> $data
     * @return ImplAction[]
     * @throws InvalidConfigStructureException|MissingRequiredFieldException
     */
    protected function loadImplActions(array $data): array
    {
        if ($data === []) {
            return [];
        }

        if (array_is_list($data)) {
            throw new InvalidConfigStructureException(
                "impl.actions must be an object, not an array",
            );
        }

        $actions = [];

        foreach ($data as $model => $actionsData) {
            if (!is_string($model) || trim($model) === "") {
                throw new InvalidConfigStructureException(
                    "Action model key must be a non-empty string",
                );
            }

            if (!is_array($actionsData) || array_is_list($actionsData)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "impl.actions[%s] must be an object, not an array",
                        $model,
                    ),
                );
            }

            foreach ($actionsData as $typeString => $actionData) {
                if (!is_array($actionData)) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "impl.actions[%s][%s] must be an object",
                            $model,
                            $typeString,
                        ),
                    );
                }

                $type = $this->parseActionType(
                    $typeString,
                    sprintf("impl.actions[%s][%s]", $model, $typeString),
                );

                $actions[] = new ImplAction(
                    type: $type,
                    model: $model,
                    entityLocator: $this->castToStringOrNull(
                        $actionData["entityLocator"] ?? null,
                    ),
                    entitySaver: $this->castToStringOrNull(
                        $actionData["entitySaver"] ?? null,
                    ),
                    entityRemover: $this->castToStringOrNull(
                        $actionData["entityRemover"] ?? null,
                    ),
                    listConfig: $this->loadImplListConfig(
                        $actionData["config"] ?? null,
                        sprintf(
                            "impl.actions[%s][%s].config",
                            $model,
                            $typeString,
                        ),
                    ),
                );
            }
        }

        return $actions;
    }

    /**
     * @throws InvalidConfigStructureException
     */
    protected function loadImplListConfig(
        mixed $data,
        string $path,
    ): ?ImplListConfig {
        if ($data === null) {
            return null;
        }

        if (!is_array($data)) {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s' must be an object or null", $path),
            );
        }

        foreach (array_keys($data) as $key) {
            if (!in_array($key, ["class", "repository"], true)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Unknown key '%s' in '%s'. Allowed keys: class, repository",
                        $key,
                        $path,
                    ),
                );
            }
        }

        $repository = $this->castToStringOrNull($data["repository"] ?? null);

        if ($repository !== null) {
            $this->configValidator->validateRepository($repository, $path);
        }

        $class = $this->castToStringOrNull($data["class"] ?? null);

        // The existence of classes is checked separately: repository — in
        // validateRepository above, class — in ConfigValidator::validateListFilters
        //
        // Существование классов проверяется отдельно: repository — в
        // validateRepository выше, class — в ConfigValidator::validateListFilters
        /** @var class-string|null $class */
        /** @var class-string|null $repository */

        return new ImplListConfig(
            class: $class,
            repository: $repository,
        );
    }
}
