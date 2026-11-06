<?php

declare(strict_types=1);

namespace UniMethod\Frame\Integration;

/**
 * The pipeline point at which constraints are checked.
 *
 * Точка пайплайна, в которой проверяются ограничения.
 */
enum ConstraintPhase: string
{
    /**
     * The object was obtained by the locator, input not yet applied: read, update, delete, reference resolution
     * in writable.
     *
     * Объект получен локатором, вход ещё не применён: read, update, delete, резолв reference в writable.
     */
    case AfterLocate = 'afterLocate';

    /**
     * The object is built from input: create, update (including nested creates in writable).
     *
     * Объект собран из входа: create, update (включая вложенные create в writable).
     */
    case AfterInput = 'afterInput';

    /**
     * DTO of the list request (filter / sort / pagination).
     *
     * DTO list-запроса (filter / sort / pagination).
     */
    case ListQuery = 'listQuery';
}
