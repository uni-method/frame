<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common;

use UniMethod\Frame\Config\{Action, Attribute};
use UniMethod\Frame\Config\Common\Impl\{
    ImplAction,
    ImplAttribute,
    ImplConfig,
    ImplModel,
    ImplRelation,
};
use UniMethod\Frame\Config\Common\Schema\{
    SchemaAction,
    SchemaListConfig,
    SchemaRelation,
};
use UniMethod\Frame\Config\Common\Schema\{
    SchemaAttribute,
    SchemaConfig,
    SchemaModel,
    SchemaWriteMode,
};
use UniMethod\Frame\Config\{Config, Model, Relation};
use UniMethod\Frame\Config\ListAction\ListActionConfig;

final class Merger
{
    public function merge(SchemaConfig $schema, ImplConfig $impl): Config
    {
        $implModelsByType = $this->indexImplModels($impl->models);
        $implActionsByKey = $this->indexImplActions($impl->actions);

        $models = [];
        foreach ($schema->models as $schemaModel) {
            $implModel = $implModelsByType[$schemaModel->type] ?? null;
            $models[] = $this->mergeModel($schemaModel, $implModel);
        }

        $actions = [];
        foreach ($schema->actions as $schemaAction) {
            $key = $schemaAction->model . "." . $schemaAction->type->value;
            $implAction = $implActionsByKey[$key] ?? null;
            $actions[] = $this->mergeAction($schemaAction, $implAction);
        }

        return new Config(
            models: $models,
            actions: $actions,
            locators: $impl->locators,
            savers: $impl->savers,
            removers: $impl->removers,
            mutators: $impl->mutators,
            relationMutators: $impl->relationMutators,
            userModel: $impl->userModel,
            defaultLocator: $impl->defaultLocator,
        );
    }

    private function mergeModel(SchemaModel $schema, ?ImplModel $impl): Model
    {
        $implAttrsByName = $this->indexImplAttributes(
            $impl instanceof ImplModel ? $impl->attributes : [],
        );
        $implRelsByName = $this->indexImplRelations(
            $impl instanceof ImplModel ? $impl->relations : [],
        );

        $attributes = [];
        foreach ($schema->attributes as $schemaAttr) {
            $implAttr = $implAttrsByName[$schemaAttr->name] ?? null;
            $attributes[] = $this->mergeAttribute($schemaAttr, $implAttr);
        }

        $relations = [];
        foreach ($schema->relations as $schemaRel) {
            $implRel = $implRelsByName[$schemaRel->name] ?? null;
            $relations[] = $this->mergeRelation($schemaRel, $implRel);
        }

        return new Model(
            class: $impl instanceof ImplModel ? $impl->class : "",
            type: $schema->type,
            comment: $schema->comment,
            attributes: $attributes,
            relations: $relations,
            entityLocator: $impl instanceof ImplModel ? $impl->entityLocator : null,
        );
    }

    private function mergeAttribute(
        SchemaAttribute $schema,
        ?ImplAttribute $impl,
    ): Attribute {
        $getter = $impl instanceof ImplAttribute ? $impl->getter : null;
        $setter = $impl instanceof ImplAttribute ? $impl->setter : null;
        $constructorParam =
            $impl instanceof ImplAttribute && $impl->constructorParam;

        // SchemaWriteMode takes precedence over impl — it overrides setter/constructorParam
        //
        // SchemaWriteMode имеет приоритет над impl — перезаписывает setter/constructorParam
        if ($schema->writeMode === SchemaWriteMode::ReadOnly) {
            $setter = false;
            $constructorParam = false;
        } elseif ($schema->writeMode === SchemaWriteMode::CreateOnly) {
            $setter = false;
            $constructorParam = true;
        }

        return new Attribute(
            name: $schema->name,
            type: $schema->type,
            getter: $getter,
            setter: $setter,
            mutatorSetter: $impl instanceof ImplAttribute
                ? $impl->mutatorSetter
                : null,
            comment: $schema->comment,
            constructorParam: $constructorParam,
            mutators: $schema->mutators,
        );
    }

    private function mergeRelation(
        SchemaRelation $schema,
        ?ImplRelation $impl,
    ): Relation {
        return new Relation(
            name: $schema->name,
            type: $schema->type,
            targetModel: $schema->targetModel,
            getter: $impl instanceof ImplRelation ? $impl->getter : null,
            setter: $impl instanceof ImplRelation ? $impl->setter : null,
            add: $impl instanceof ImplRelation ? $impl->add : null,
            mutatorSetter: $impl instanceof ImplRelation
                ? $impl->mutatorSetter
                : null,
            // comment is set only in schema; the impl DTO carries no comment field.
            //
            // comment задаётся только в schema; impl-DTO поля комментария не несёт.
            comment: $schema->comment,
            constructorParam: $impl instanceof ImplRelation &&
                $impl->constructorParam,
            mutators: $schema->mutators,
        );
    }

    private function mergeAction(
        SchemaAction $schema,
        ?ImplAction $impl,
    ): Action {
        $listConfig = null;
        if ($schema->listConfig instanceof SchemaListConfig) {
            $listConfig = new ListActionConfig(
                class: $impl?->listConfig?->class,
                repository: $impl?->listConfig?->repository,
                pagination: $schema->listConfig->pagination,
                sort: $schema->listConfig->sort,
                filters: $schema->listConfig->filters,
            );
        }

        return new Action(
            type: $schema->type,
            model: $schema->model,
            writable: $schema->writable,
            include: $schema->include,
            includable: $schema->includable,
            config: $listConfig,
            comment: $schema->comment,
            access: $schema->access,
            entityLocator: $impl?->entityLocator,
            entitySaver: $impl?->entitySaver,
            entityRemover: $impl?->entityRemover,
        );
    }

    /**
     * @param ImplModel[] $models
     * @return array<string, ImplModel>
     */
    private function indexImplModels(array $models): array
    {
        $index = [];
        foreach ($models as $model) {
            $index[$model->type] = $model;
        }

        return $index;
    }

    /**
     * @param ImplAction[] $actions
     * @return array<string, ImplAction>
     */
    private function indexImplActions(array $actions): array
    {
        $index = [];
        foreach ($actions as $action) {
            $index[$action->model . "." . $action->type->value] = $action;
        }

        return $index;
    }

    /**
     * @param ImplAttribute[] $attributes
     * @return array<string, ImplAttribute>
     */
    private function indexImplAttributes(array $attributes): array
    {
        $index = [];
        foreach ($attributes as $attr) {
            $index[$attr->name] = $attr;
        }

        return $index;
    }

    /**
     * @param ImplRelation[] $relations
     * @return array<string, ImplRelation>
     */
    private function indexImplRelations(array $relations): array
    {
        $index = [];
        foreach ($relations as $rel) {
            $index[$rel->name] = $rel;
        }

        return $index;
    }
}
