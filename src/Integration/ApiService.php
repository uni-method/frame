<?php

declare(strict_types=1);

namespace UniMethod\Frame\Integration;

use UniMethod\Frame\Config\AccessConfig;
use UniMethod\Frame\Config\{Action, ActionType, Config, Model, PhaseType};
use UniMethod\Frame\Config\ListAction\ListActionConfig;
use UniMethod\Frame\Exception\FrameDomainException;
use UniMethod\Frame\Integration\Exception\{
    ActionAliasNotConfiguredException,
    AmbiguousRepositoryException,
    SaverContractViolationException,
};
use UniMethod\Frame\Json\{ConvertToJson, ConvertToStdClass};
use UniMethod\Frame\Mapping\{
    MappingFromObject,
    MappingToListAction,
    MappingToObject,
};
use UniMethod\Frame\Mapping\Exception\{
    ModelNotFoundException,
    RelationTypeMismatchException,
};
use UniMethod\Frame\Mapping\ListQuery\Exception\{
    ForbiddenOperatorException,
    ListActionMisconfiguredException,
    UnsatisfiableListFilterException,
};
use UniMethod\Frame\Mutator\MutatorRegistry;
use UniMethod\Frame\Persistence\{
    EntityCollectionInterface,
    ListLocators,
    ListRemovers,
    ListSavers,
};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Validator\ActionValidator;
use UniMethod\Frame\Validator\Exception\{
    DeclaredRelationMissingException,
    InvalidDataStructureException,
    RelationNotIncludableException,
    UndeclaredRelationException,
    UnknownPropertyException,
    UnknownRelationException,
    UnwritableRelationException,
};

final class ApiService
{
    /** @var list<EntityCollectionInterface> */
    private array $repositories = [];

    /**
     * Cache of the mapping "class from config.repository → instance", see getRepository().
     *
     * Кэш сопоставления «класс из config.repository → экземпляр», см. getRepository().
     *
     * @var array<class-string, EntityCollectionInterface|null>
     */
    private array $repositoryByConfigClass = [];

    /**
     * @param iterable<EntityCollectionInterface|object> $repositories
     */
    public function __construct(
        private readonly ConstraintCheckerInterface $constraintChecker,
        private readonly Config $config,
        private readonly TreeBuilder $treeBuilder,
        private readonly ConvertToStdClass $convertToStdClass,
        private readonly ConvertToJson $convertToJson,
        private readonly MappingFromObject $mappingFromObject,
        private readonly MappingToObject $mappingToObject,
        private readonly MappingToListAction $mappingToListAction,
        private readonly ActionValidator $actionValidator,
        private readonly ListLocators $locators,
        private readonly ListSavers $savers,
        private readonly ListRemovers $removers,
        private readonly MutatorRegistry $manipulators,
        private readonly ?UserProviderInterface $userProvider = null,
        iterable $repositories = [],
        private readonly ?DomainExceptionObserverInterface $domainExceptionObserver = null,
    ) {
        foreach ($repositories as $repository) {
            if ($repository instanceof EntityCollectionInterface) {
                $this->repositories[] = $repository;
            }
        }

        // Fail-fast: for each list action the repository must be determined
        // unambiguously. Matching by instanceof rather than by get_class — DI may
        // return a lazy proxy or a subclass of the class from the config
        //
        // Fail-fast: для каждого list-действия репозиторий должен определяться
        // однозначно. Сопоставление по instanceof, а не по get_class, — DI может
        // отдать lazy-прокси или наследника класса из конфига
        foreach ($this->config->actions as $action) {
            $configuredClass = $action->config?->repository;
            if ($action->type !== ActionType::List || $configuredClass === null) {
                continue;
            }

            $this->getRepository($configuredClass, $action->model);
        }
    }

    /**
     * @param array<mixed> $queryParams
     * @return ApiResult<string>|ApiResult<list<object>>|ApiResult<Errors>
     */
    public function execute(
        string $modelType,
        ActionType $actionType,
        array $queryParams = [],
        ?string $rawContent = null,
        ?string $id = null,
    ): ApiResult {
        try {
            // Find the action
            //
            // Находим действие
            $action = null;
            foreach ($this->config->actions as $a) {
                if ($a->model === $modelType && $a->type === $actionType) {
                    $action = $a;
                    break;
                }
            }

            if (!$action) {
                return ApiResult::error(
                    new Error(
                        Errors::NOT_FOUND_ERROR_CODE,
                        "Action not found.",
                    ),
                );
            }

            // Access check
            //
            // Проверка доступа
            if ($action->access !== null && !$this->canAccessAction($action)) {
                return ApiResult::error(
                    new Error($this->denialStatus($action->access), "Access denied."),
                );
            }

            // Find the model
            //
            // Находим модель
            $model = null;
            foreach ($this->config->models as $m) {
                if ($m->type === $modelType) {
                    $model = $m;
                    break;
                }
            }

            if ($model === null) {
                throw new ModelNotFoundException($modelType);
            }

            // Build include
            //
            // Формируем include
            $included = $this->normalizeIncluded(
                $queryParams["included"] ?? null,
            );
            if ($included === null) {
                return ApiResult::error(
                    new Error(
                        Errors::DEFAULT_ERROR_CODE,
                        "Parameter 'included' must be a string or a list of strings.",
                    ),
                );
            }

            $requestedIncludes = $this->treeBuilder->simpleSplitAndUnique(
                $included,
            );

            if ($action->includable !== null) {
                $requestedIncludes = array_values(
                    array_filter($requestedIncludes, function (
                        string $path,
                    ) use ($action): bool {
                        if (!array_key_exists($path, $action->includable)) {
                            return false;
                        }

                        $accessConfig =
                            $action->includable[$path] ?? $action->access;
                        if ($accessConfig === null) {
                            return true;
                        }

                        return $this->isAccessGranted($accessConfig);
                    }),
                );
            }

            $finalIncluded = $this->treeBuilder->mergeArraysToUnique(
                $action->include ?? [],
                $requestedIncludes,
            );

            // CRUD handling
            //
            // Обработка CRUD
            return match ($actionType) {
                ActionType::Create => $this->handleWriteAction(
                    $action,
                    $model,
                    $included,
                    $finalIncluded,
                    $rawContent,
                    $id,
                ),
                ActionType::Update => $id
                    ? $this->handleWriteAction(
                        $action,
                        $model,
                        $included,
                        $finalIncluded,
                        $rawContent,
                        $id,
                    )
                    : ApiResult::error(
                        new Error(
                            Errors::DEFAULT_ERROR_CODE,
                            "Missing id for update.",
                        ),
                    ),
                ActionType::Read => $id
                    ? $this->handleReadAction(
                        $action,
                        $model,
                        $finalIncluded,
                        $id,
                    )
                    : ApiResult::error(
                        new Error(
                            Errors::DEFAULT_ERROR_CODE,
                            "Missing id for read.",
                        ),
                    ),
                ActionType::Delete => $id
                    ? $this->handleDeleteAction(
                        $action,
                        $model,
                        $id,
                        $finalIncluded,
                    )
                    : ApiResult::error(
                        new Error(
                            Errors::DEFAULT_ERROR_CODE,
                            "Missing id for delete.",
                        ),
                    ),
                ActionType::List => $this->handleListAction(
                    $action,
                    $queryParams,
                    $finalIncluded,
                ),
            };
        } catch (FrameDomainException $frameDomainException) {
            // Domain exception: determined by client input, safe
            // to show. Optionally notify the observer (logging/analytics),
            // then build the response using the code carried by the exception itself.
            //
            // Доменное исключение: детерминировано вводом клиента, безопасно
            // показать. Опционально уведомляем наблюдателя (логирование/аналитика),
            // затем оформляем ответ по коду, который несёт само исключение.
            $this->domainExceptionObserver?->onDomainException($frameDomainException);

            return ApiResult::error(
                new Error($frameDomainException->getHttpErrorCode(), $frameDomainException->getMessage()),
            );
        }

        // Non-domain exceptions (hydration, DB, mapping bug, misconfiguration) are NOT
        // caught: they fly to the application boundary, which logs the trace and
        // produces a 500. That is also the place for a swoole revert of a failed migration.
        //
        // Не-доменные исключения (гидрация, БД, баг маппинга, мисконфиг) НЕ
        // ловятся: они улетают на границу приложения, которая логирует трейс и
        // оформляет 500. Там же — место для swoole-реверта упавшей миграции.
    }

    /**
     * @param list<string> $finalIncluded
     * @return ApiResult<string>|ApiResult<Errors>
     * @throws RelationTypeMismatchException|InvalidDataStructureException
     * @throws RelationNotIncludableException|UnknownPropertyException|UnknownRelationException
     * @throws UnwritableRelationException|UndeclaredRelationException|DeclaredRelationMissingException
     */
    private function handleWriteAction(
        Action $action,
        Model $model,
        string $included,
        array $finalIncluded,
        ?string $rawContent,
        ?string $id,
    ): ApiResult {
        $rawObject = $this->convertToStdClass->convert($rawContent ?: "{}");

        foreach ($action->writable ?? [] as $path => $writableConfig) {
            if ($this->hasNestedKey($rawObject, $path)) {
                $effectiveAccess = $writableConfig->access ?? $action->access;
                if (
                    $effectiveAccess !== null &&
                    !$this->isAccessGranted($effectiveAccess)
                ) {
                    return ApiResult::error(
                        new Error(
                            $this->denialStatus($effectiveAccess),
                            sprintf("Access denied for relation '%s'.", $path),
                        ),
                    );
                }
            }
        }

        // Input contract: body structure and declaration ⊂ writable, then
        // the body matching the declaration (undeclared / declared-but-missing → 422)
        //
        // Контракт входа: структура тела и декларация ⊂ writable, затем
        // соответствие тела декларации (undeclared / declared-but-missing → 422)
        $this->actionValidator->validateStd(
            $action->type,
            $action->model,
            $rawObject,
            $included,
        );
        $this->actionValidator->validateDeclaration(
            $action->type,
            $action->model,
            $rawObject,
            $included,
        );

        $original = null;
        $located = null;

        if ($action->type === ActionType::Update && $id !== null) {
            $located = $this->locators
                ->getEntityLocatorByName($this->requireAlias($action->entityLocator, "entityLocator", $action))
                ->find($model->class, $id, $finalIncluded);

            if ($located === null) {
                return ApiResult::error(
                    new Error(Errors::NOT_FOUND_ERROR_CODE, "Not found."),
                );
            }

            $errors = $this->constraintChecker->check(
                $located,
                ConstraintContext::afterLocate(
                    $action->model,
                    $action->type,
                    $id,
                    $this->currentUser(),
                ),
            );

            if ($errors !== []) {
                return ApiResult::errors(...$errors);
            }

            // The "before" snapshot is a shallow clone of the entity. Scalars and
            // value objects (DateTime) are reliable; related objects are the same instances
            // as in the current one (compare by id). An entity, not a DTO: rules
            // (UnchangedOnUpdate, etc.) read it with the same getters as the
            // current object
            //
            // Снимок «до» — поверхностный clone сущности. Достоверны скаляры и
            // value-объекты (DateTime); связанные объекты — те же экземпляры,
            // что и в текущем (сравнивать по id). Сущность, а не DTO: правила
            // (UnchangedOnUpdate и т.п.) читают её теми же геттерами, что и
            // текущий объект
            $original = clone $located;
        }

        $entity = $this->mappingToObject->map(
            $action->model,
            $rawObject,
            $action->type,
            $included,
            $id,
            $finalIncluded,
            $located,
        );

        $treeAfterInput = $this->treeBuilder->buildSimpleTree(
            $this->treeBuilder->simpleSplitAndUnique($included),
        );
        $this->manipulators->applyPhase(
            PhaseType::AfterInput,
            $entity,
            $model,
            $action->type,
            $treeAfterInput,
        );

        $errors = $this->constraintChecker->check(
            $entity,
            ConstraintContext::afterInput(
                $action->model,
                $action->type,
                $this->currentUser(),
                $id,
                $original,
            ),
        );

        if ($errors !== []) {
            return ApiResult::errors(...$errors);
        }

        $treeFinal = $this->treeBuilder->buildSimpleTree($finalIncluded);
        $this->manipulators->applyPhase(
            PhaseType::AfterValidate,
            $entity,
            $model,
            $action->type,
            $treeFinal,
        );

        $saverAlias = $this->requireAlias($action->entitySaver, "entitySaver", $action);
        $saved = $this->savers
            ->getEntitySaverByName($saverAlias)
            ->save($entity);

        if ($saved !== $entity) {
            throw new SaverContractViolationException($action, $saverAlias);
        }

        // BeforeOutput applies MappingFromObject::map (by $actionType) —
        // exactly once for each output object
        //
        // BeforeOutput применяет MappingFromObject::map (по $actionType) —
        // ровно один раз на каждый выводимый объект

        $content = $this->convertToJson->convert(
            $this->mappingFromObject->map(
                $action->model,
                $saved,
                $finalIncluded,
                $action->type,
            ),
        );

        return new ApiResult(
            $action->type === ActionType::Create ? 201 : 200,
            $content,
            [],
            true,
        );
    }

    // -------------------------------------------
    // Helper handlers
    // -------------------------------------------
    //
    // -------------------------------------------
    // Вспомогательные обработчики
    // -------------------------------------------

    /**
     * @param list<string> $included
     * @return ApiResult<string>|ApiResult<Errors>
     */
    private function handleReadAction(
        Action $action,
        Model $model,
        array $included,
        string $id,
    ): ApiResult {
        $entity = $this->locators
            ->getEntityLocatorByName($this->requireAlias($action->entityLocator, "entityLocator", $action))
            ->find($model->class, $id, $included);

        if ($entity === null) {
            return ApiResult::error(
                new Error(Errors::NOT_FOUND_ERROR_CODE, "Not found."),
            );
        }

        $errors = $this->constraintChecker->check(
            $entity,
            ConstraintContext::afterLocate(
                $action->model,
                ActionType::Read,
                $id,
                $this->currentUser(),
            ),
        );

        if ($errors !== []) {
            return ApiResult::errors(...$errors);
        }

        $content = $this->convertToJson->convert(
            $this->mappingFromObject->map(
                $action->model,
                $entity,
                $included,
                ActionType::Read,
            ),
        );

        return new ApiResult(200, $content, [], true);
    }

    /**
     * @param list<string> $included
     * @return ApiResult<string>|ApiResult<Errors>
     */
    private function handleDeleteAction(
        Action $action,
        Model $model,
        string $id,
        array $included,
    ): ApiResult {
        $result = $this->locators
            ->getEntityLocatorByName($this->requireAlias($action->entityLocator, "entityLocator", $action))
            ->find($model->class, $id, $included);

        if ($result === null) {
            return ApiResult::error(
                new Error(Errors::NOT_FOUND_ERROR_CODE, "Not found."),
            );
        }

        $errors = $this->constraintChecker->check(
            $result,
            ConstraintContext::afterLocate(
                $action->model,
                ActionType::Delete,
                $id,
                $this->currentUser(),
            ),
        );

        if ($errors !== []) {
            return ApiResult::errors(...$errors);
        }

        $this->removers
            ->getEntityRemoverByName($this->requireAlias($action->entityRemover, "entityRemover", $action))
            ->remove($result);

        $content = $this->convertToJson->convert(
            $this->mappingFromObject->map(
                $action->model,
                $result,
                $included,
                ActionType::Delete,
            ),
        );

        return new ApiResult(200, $content, [], true);
    }

    /**
     * @param array<mixed> $params
     * @param list<string> $included
     * @return ApiResult<list<object>>|ApiResult<Errors>
     */
    private function handleListAction(
        Action $action,
        array $params,
        array $included,
    ): ApiResult {
        $this->checkFilterOperatorAccess($action, $params);

        // A filter by relation with non-existent ids is not a client error but a knowingly
        // empty result: we do not touch the repository, we respond with 200 and an empty list
        //
        // Фильтр по связи с несуществующими id — не ошибка клиента, а заведомо
        // пустая выборка: репозиторий не трогаем, отвечаем 200 и пустым списком
        $unsatisfiable = false;
        try {
            $result = $this->mappingToListAction->map($params, $action->model);
        } catch (UnsatisfiableListFilterException) {
            $result = null;
            $unsatisfiable = true;
        }

        $filter = null;
        $sort = null;
        $pagination = null;

        $repositoryName = $action->config?->repository;

        if (!$repositoryName) {
            throw new ListActionMisconfiguredException(
                sprintf(
                    "Repository is not configured for list action of model '%s'",
                    $action->model,
                ),
            );
        }

        $repository = $this->getRepository($repositoryName, $action->model);

        if (!$repository instanceof EntityCollectionInterface) {
            throw new ListActionMisconfiguredException(
                sprintf(
                    "Repository '%s' for list action of model '%s' is not passed to ApiService",
                    $repositoryName,
                    $action->model,
                ),
            );
        }

        if ($unsatisfiable) {
            return new ApiResult(200, [], ["X-Total-Count" => "0"], false);
        }

        if ($result !== null) {
            $errors = $this->constraintChecker->check(
                $result,
                ConstraintContext::listQuery(
                    $action->model,
                    $this->currentUser(),
                ),
            );

            if ($errors !== []) {
                return ApiResult::errors(...$errors);
            }

            $filter = $result->filter ?? null;
            $sort = $result->sort ?? null;
            $pagination = $result->pagination ?? null;
        }

        $items = $repository->list($filter, $sort, $pagination, $included);

        $totalCount = $repository->countForList($filter);

        $mappedItems = array_map(
            fn (object $item): object => $this->mappingFromObject->map(
                $action->model,
                $item,
                $included,
                ActionType::List,
            ),
            $items,
        );

        return new ApiResult(
            200,
            $mappedItems,
            ["X-Total-Count" => (string) $totalCount],
            false,
        );
    }

    private function currentUser(): ?UserInterface
    {
        return $this->userProvider?->getCurrentUser();
    }

    /**
     * Non-emptiness of the alias is guaranteed by config validation; here it is type narrowing.
     *
     * Непустота алиаса гарантируется валидацией конфига; здесь — сужение типа.
     */
    private function requireAlias(?string $alias, string $field, Action $action): string
    {
        if ($alias === null || $alias === "") {
            throw new ActionAliasNotConfiguredException($action, $field);
        }

        return $alias;
    }

    /**
     * `?included=a,b` or `?included[]=a&included[]=b`; anything else — null (400).
     *
     * `?included=a,b` или `?included[]=a&included[]=b`; иное — null (400).
     */
    private function normalizeIncluded(mixed $included): ?string
    {
        if ($included === null) {
            return "";
        }

        if (is_string($included)) {
            return $included;
        }

        if (is_array($included)) {
            foreach ($included as $path) {
                if (!is_string($path)) {
                    return null;
                }
            }

            return implode(",", $included);
        }

        return null;
    }

    /**
     * Repository for the class from `config.repository`: the single passed
     * instance that is an instanceof this class (the class itself, a subclass,
     * a lazy proxy). Several matching — AmbiguousRepositoryException;
     * none — null (the calling code responds with ListActionMisconfiguredException).
     *
     * Репозиторий для класса из `config.repository`: единственный переданный
     * экземпляр, являющийся instanceof этого класса (сам класс, наследник,
     * lazy-прокси). Несколько подходящих — AmbiguousRepositoryException;
     * ни одного — null (вызывающий код отвечает ListActionMisconfiguredException).
     *
     * @param class-string $configuredClass
     * @throws AmbiguousRepositoryException
     */
    private function getRepository(
        string $configuredClass,
        string $modelType,
    ): ?EntityCollectionInterface {
        if (array_key_exists($configuredClass, $this->repositoryByConfigClass)) {
            return $this->repositoryByConfigClass[$configuredClass];
        }

        $matches = array_values(array_filter(
            $this->repositories,
            static fn (EntityCollectionInterface $repository): bool => $repository instanceof $configuredClass,
        ));

        if (count($matches) > 1) {
            throw new AmbiguousRepositoryException(
                $configuredClass,
                $modelType,
                array_map(static fn (object $repository): string => $repository::class, $matches),
            );
        }

        return $this->repositoryByConfigClass[$configuredClass] = $matches[0] ?? null;
    }

    private function hasNestedKey(object $obj, string $dotPath): bool
    {
        $parts = explode(".", $dotPath);
        $current = $obj;
        foreach ($parts as $part) {
            if (!is_object($current) || !property_exists($current, $part)) {
                return false;
            }

            $current = $current->$part;
        }

        return true;
    }

    /**
     * Access to filter operators in the extended form `{ operator, access }`.
     * Query parsing happens only in MappingToListAction::parseFilters(): here is the same
     * result that will go into mapping, without a second copy of the logic. An unknown
     * operator/field → 400 from parseFilters, forbidden access → 401/403
     * (see denialStatus).
     *
     * Доступ к операторам фильтра в расширенной форме `{ operator, access }`.
     * Разбор query — только в MappingToListAction::parseFilters(): здесь тот же
     * результат, что пойдёт в маппинг, без второй копии логики. Неизвестный
     * оператор/поле → 400 из parseFilters, запрещённый access → 401/403
     * (см. denialStatus).
     *
     * @param array<mixed> $params
     * @throws ForbiddenOperatorException
     */
    private function checkFilterOperatorAccess(
        Action $action,
        array $params,
    ): void {
        if (!$action->config instanceof ListActionConfig) {
            return;
        }

        foreach ($this->mappingToListAction->parseFilters($params, $action->model) as $parsedFilter) {
            $access = $parsedFilter->operatorAccess();
            if (!$access instanceof AccessConfig) {
                continue;
            }

            if (!$this->isAccessGranted($access)) {
                throw new ForbiddenOperatorException(
                    $parsedFilter->operator->value,
                    $parsedFilter->rule->path,
                    $this->denialStatus($access),
                );
            }
        }
    }

    /**
     * Fail-closed: without a user provider only trivial access passes.
     *
     * Fail-closed: без провайдера пользователя проходит только тривиальный access.
     */
    private function canAccessAction(Action $action): bool
    {
        if ($this->userProvider instanceof UserProviderInterface) {
            return $this->userProvider->canAccessAction($action);
        }

        return !$action->access instanceof AccessConfig || $action->access->isTrivial();
    }

    /**
     * Fail-closed: without a user provider only trivial access passes.
     *
     * Fail-closed: без провайдера пользователя проходит только тривиальный access.
     */
    private function isAccessGranted(AccessConfig $access): bool
    {
        if ($this->userProvider instanceof UserProviderInterface) {
            return $this->userProvider->canAccess($access);
        }

        return $access->isTrivial();
    }

    /**
     * HTTP code of an access denial that has already happened. 401 — `authRequired` and
     * there is no current user, or `UserProvider` was not passed at all (a denial
     * without a provider is possible only with non-trivial access); otherwise — 403.
     *
     * HTTP-код уже состоявшегося отказа в доступе. 401 — `authRequired`, а
     * текущего пользователя нет, либо `UserProvider` не передан вовсе (отказ
     * без провайдера возможен только при нетривиальном access); иначе — 403.
     */
    private function denialStatus(AccessConfig $access): int
    {
        if (!$this->userProvider instanceof UserProviderInterface) {
            return Errors::UNAUTHORIZED_ERROR_CODE;
        }

        if ($access->authRequired && !$this->userProvider->getCurrentUser() instanceof UserInterface) {
            return Errors::UNAUTHORIZED_ERROR_CODE;
        }

        return Errors::FORBIDDEN_ERROR_CODE;
    }
}
