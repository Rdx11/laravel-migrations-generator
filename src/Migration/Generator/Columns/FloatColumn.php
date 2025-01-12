<?php

namespace KitLoong\MigrationsGenerator\Migration\Generator\Columns;

use KitLoong\MigrationsGenerator\Enum\Migrations\Method\ColumnModifier;
use KitLoong\MigrationsGenerator\Migration\Blueprint\Method;
use KitLoong\MigrationsGenerator\Schema\Models\Column;
use KitLoong\MigrationsGenerator\Schema\Models\Table;

class FloatColumn implements ColumnTypeGenerator
{
    // Framework set (8, 2) as default precision.
    private const DEFAULT_PRECISION = 8;
    private const DEFAULT_SCALE     = 2;

    private const EMPTY_PRECISION = 0;
    private const EMPTY_SCALE     = 0;

    /**
     * @inheritDoc
     */
    public function generate(Table $table, Column $column): Method
    {
        $precisions = $this->getPrecisions($column);

        $method = new Method($column->getType(), $column->getName(), ...$precisions);

        if ($column->isUnsigned()) {
            $method->chain(ColumnModifier::UNSIGNED());
        }

        return $method;
    }

    /**
     * Get precision and scale.
     * Return empty if both precision and scale are 0.
     * Also, return empty if precision = 8 and scale = 2.
     *
     * @param  \KitLoong\MigrationsGenerator\Schema\Models\Column  $column
     * @return int[] "[]|[precision]|[precision, scale]"
     */
    private function getPrecisions(Column $column): array
    {
        if (
            $column->getPrecision() === self::EMPTY_PRECISION
            && $column->getScale() === self::EMPTY_SCALE
        ) {
            return [];
        }

        if (
            $column->getPrecision() === self::DEFAULT_PRECISION
            && $column->getScale() === self::DEFAULT_SCALE
        ) {
            return [];
        }

        if ($column->getScale() === self::DEFAULT_SCALE) {
            return [$column->getPrecision()];
        }

        return [$column->getPrecision(), $column->getScale()];
    }
}
