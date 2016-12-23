<?php

namespace SilverStripe\GraphQL\Scaffolding\Interfaces;

use SilverStripe\GraphQL\Scaffolding\Scaffolders\GraphQLScaffolder;

/**
 * Use on classes that update the GraphQL scaffolder
 */
interface ScaffoldingProvider
{
    /**
     * @return mixed
     */
    public function provideGraphQLScaffolding(GraphQLScaffolder $scaffolder);
}