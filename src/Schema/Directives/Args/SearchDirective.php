<?php

namespace Nuwave\Lighthouse\Schema\Directives\Args;

use Illuminate\Database\Eloquent\Builder;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Support\Contracts\ArgFilterDirective;

class SearchDirective extends BaseDirective implements ArgFilterDirective
{
    /**
     * Name of the directive.
     *
     * @return string
     */
    public function name(): string
    {
        return 'search';
    }

    /**
     * Get the filter.
     *
     * @return \Closure
     */
    public function filter(): \Closure
    {
        // Adds within method to specify custom index.
        $within = $this->directiveArgValue('within');

        return function (Builder $query, string $columnName, $value) use ($within) {
            $modelClass = \get_class($query->getModel());

            /** @var \Laravel\Scout\Builder $query */
            $query = $modelClass::search($value);

            if (null !== $within) {
                $query->within($within);
            }

            return $query;
        };
    }

    /**
     * Get the type of the ArgFilterDirective.
     *
     * @return string self::SINGLE_TYPE | self::MULTI_TYPE
     */
    public function type(): string
    {
        return static::SINGLE_TYPE;
    }
}
