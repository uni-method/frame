<?php

declare(strict_types=1);

namespace UniMethod\Frame\Type;

use DateTimeImmutable;
use DateTimeInterface;

class Caster
{
    /**
     * An allowed datetime is only an absolute ISO 8601 (RFC 3339 profile):
     * `YYYY-MM-DD`, `YYYY-MM-DDThh:mm:ss`, a fractional seconds part `.f` (1–6
     * digits, the `Date.prototype.toISOString()` format), an offset `Z` or `±hh:mm`.
     * Relative ones (`now`, `+1 day`, …), zone abbreviations and identifiers
     * (`EST`, `Europe/Moscow`) and other formats of the PHP parser are forbidden: the
     * `P` format in createFromFormat accepts them by itself, so the shape of the string
     * is checked with a regular expression before parsing.
     *
     * A value without an offset (`YYYY-MM-DD`, `YYYY-MM-DDThh:mm:ss`) is interpreted
     * in the default PHP timezone (date_default_timezone_get()), a date without
     * time — as midnight in that timezone.
     *
     * Допустимый datetime — только абсолютный ISO 8601 (профиль RFC 3339):
     * `YYYY-MM-DD`, `YYYY-MM-DDThh:mm:ss`, дробная часть секунд `.f` (1–6
     * цифр, формат `Date.prototype.toISOString()`), смещение `Z` или `±hh:mm`.
     * Относительные (`now`, `+1 day`, …), аббревиатуры и идентификаторы зон
     * (`EST`, `Europe/Moscow`) и прочие форматы PHP-парсера запрещены: формат
     * `P` в createFromFormat сам по себе их принимает, поэтому форма строки
     * проверяется регулярным выражением до разбора.
     *
     * Значение без смещения (`YYYY-MM-DD`, `YYYY-MM-DDThh:mm:ss`) трактуется
     * в таймзоне PHP по умолчанию (date_default_timezone_get()), дата без
     * времени — как полночь в этой таймзоне.
     */
    private const string DATE_TIME_PATTERN =
        '/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})?)?$/D';

    public function cast(mixed $value, TypeSpec $type): mixed
    {
        if ($value === null) {
            if ($type->nullable) {
                return null;
            }

            throw new InvalidCastInputException(
                "Null given, but type is not nullable",
            );
        }

        if ($type->isList) {
            if (!is_iterable($value)) {
                throw new InvalidCastInputException("Expected list/iterable");
            }

            $out = [];
            foreach ($value as $v) {
                $out[] = $this->castSingle($v, $type->kind);
            }

            return $out;
        }

        return $this->castSingle($value, $type->kind);
    }

    public function normalize(mixed $value, TypeSpec $type): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($type->isList) {
            return array_map(
                fn ($v): mixed => $this->normalizeSingle($v, $type->kind),
                $value,
            );
        }

        return $this->normalizeSingle($value, $type->kind);
    }

    private function normalizeSingle(mixed $value, ValueKind $kind): mixed
    {
        return match ($kind) {
            ValueKind::DateTime => $value instanceof DateTimeInterface
                ? $value->format("c")
                : $value,

            default => $value,
        };
    }

    private function castSingle(mixed $value, ValueKind $kind): mixed
    {
        return match ($kind) {
            ValueKind::String => is_string($value)
                ? $value
                : (is_scalar($value)
                    ? (string) $value
                    : $this->failCast("string", $value)),
            ValueKind::Int => $this->castToInt($value),
            ValueKind::Float => is_numeric($value)
                ? (float) $value
                : $this->failCast("float", $value),
            ValueKind::Bool => is_bool($value)
                ? $value
                : $this->castToBool($value),
            ValueKind::DateTime => $value instanceof DateTimeInterface
                ? $value
                : $this->castToDateTime($value),
            ValueKind::Uuid => is_string($value) &&
            preg_match('~^[0-9a-fA-F-]{36}$~', $value)
                ? $value
                : $this->failCast("uuid", $value),
        };
    }

    private function failCast(string $expect, mixed $value): never
    {
        throw new TypeCastException($expect, $value);
    }

    private function castToBool(mixed $value): bool
    {
        // An explicit check instead of `?? failCast`: does not depend on never semantics
        // and does not break if failCast stops throwing.
        //
        // Явная проверка вместо `?? failCast`: не зависит от never-семантики
        // и не ломается, если failCast перестанет бросать.
        $result = filter_var(
            $value,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );

        if ($result === null) {
            $this->failCast("bool", $value);
        }

        return $result;
    }

    private function castToDateTime(mixed $value): DateTimeImmutable
    {
        if (
            !is_string($value) ||
            preg_match(self::DATE_TIME_PATTERN, $value, $matches) !== 1
        ) {
            $this->failCast("datetime", $value);
        }

        $format = "!Y-m-d";
        if (strlen($value) > 10) {
            $format .= "\\TH:i:s";
            $format .= ($matches[1] ?? "") !== "" ? ".u" : "";
            $format .= ($matches[2] ?? "") !== "" ? "P" : "";
        }

        $result = DateTimeImmutable::createFromFormat($format, $value);
        // getLastErrors(): overflows like 2026-02-31 are warnings
        //
        // getLastErrors(): переполнения вида 2026-02-31 — предупреждения
        if (
            !$result instanceof DateTimeImmutable ||
            DateTimeImmutable::getLastErrors() !== false
        ) {
            $this->failCast("datetime", $value);
        }

        return $result;
    }

    private function castToInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (
            is_string($value) &&
            filter_var($value, FILTER_VALIDATE_INT) !== false
        ) {
            return (int) $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        $this->failCast("int", $value);
    }
}
