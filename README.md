# SilverStripe GraphQL Server

[![Build Status](https://travis-ci.org/silverstripe/silverstripe-graphql.svg?branch=master)](https://travis-ci.org/silverstripe/silverstripe-graphql)
[![codecov](https://codecov.io/gh/silverstripe/silverstripe-graphql/branch/master/graph/badge.svg)](https://codecov.io/gh/silverstripe/silverstripe-graphql)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/silverstripe/silverstripe-graphql/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/silverstripe/silverstripe-graphql/?branch=master)

This modules serves SilverStripe data as
[GraphQL](http://facebook.github.io/react/blog/2015/05/01/graphql-introduction.html)
representations, with helpers to generate schemas based on SilverStripe model
introspection. It layers a pluggable schema registration system on top of the
[graphql-php](https://github.com/webonyx/graphql-php) library. The APIs are
very similar, for example:

## Installation

Require the [composer](http://getcomposer.org) package in your `composer.json`

```
composer require silverstripe/graphql
```

## Usage

GraphQL is used through a single route which defaults to `/graphql`. You need
to define *Types* and *Queries* to expose your data via this endpoint.

Currently, the default endpoint (`/graphql`) is protected against access unless the
current user has CMS Access.

## Examples

Code examples can be found in the `examples/` folder (built out from the
configuration docs below).

## Configuration

### Define Types

Types describe your data. While your data could be any arbitrary structure, in
a SilverStripe project a GraphQL type usually relates to a `DataObject`.
GraphQL uses this information to validate queries and allow GraphQL clients to
introspect your API capabilities. The GraphQL type system is hierarchical, so
the `fields()` definition declares object properties as scalar types within
your complex type. Refer to the
[graphql-php type definitions](https://github.com/webonyx/graphql-php#type-system)
for available types.

```php
<?php

namespace MyProject\GraphQL;

use GraphQL\Type\Definition\Type;
use SilverStripe\GraphQL\TypeCreator;
use SilverStripe\GraphQL\Pagination\Connection;

class MemberTypeCreator extends TypeCreator
{
    public function attributes()
    {
        return [
            'name' => 'member'
        ];
    }

    public function fields()
    {
        return [
            'ID' => ['type' => Type::nonNull(Type::id())],
            'Email' => ['type' => Type::string()],
            'FirstName' => ['type' => Type::string()],
            'Surname' => ['type' => Type::string()],
        ];
    }
}

```

Each type class needs to be registered with a unique name against the schema
through YAML configuration:

```yml
SilverStripe\GraphQL:
  schema:
    types:
      member: 'MyProject\GraphQL\MemberTypeCreator'
```


## Define Queries

Types can be exposed via "queries". These queries are in charge of retrieving
data through the SilverStripe ORM. The response itself is handled by the
underlying GraphQL PHP library, which loops through the resulting `DataList`
and accesses fields based on the referred "type" definition.

**Note:** This will return ALL records. See below for a paginated example.

```php
<?php

namespace MyProject\GraphQL;

use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use SilverStripe\Security\Member;
use SilverStripe\GraphQL\OperationResolver;
use SilverStripe\GraphQL\QueryCreator;

class ReadMembersQueryCreator extends QueryCreator implements OperationResolver
{
    public function attributes()
    {
        return [
            'name' => 'readMembers'
        ];
    }

    public function args()
    {
        return [
            'Email' => ['type' => Type::string()]
        ];
    }

    public function type()
    {
        // Return a "thunk" to lazy load types
        return function () {
            return Type::listOf($this->manager->getType('member'));
        };
    }

    public function resolve($object, array $args, $context, ResolveInfo $info)
    {
        $member = Member::singleton();
        if (!$member->canView($context['currentUser'])) {
            throw new \InvalidArgumentException(sprintf(
                '%s view access not permitted',
                Member::class
            ));
        }
        $list = Member::get();

        // Optional filtering by properties
        if (isset($args['Email'])) {
            $list = $list->filter('Email', $args['Email']);
        }

        return $list;
    }
}
```

We'll register the query with a unique name through YAML configuration:

```yml
SilverStripe\GraphQL:
  schema:
    queries:
      readMembers: 'MyProject\GraphQL\ReadMembersQueryCreator'
```

You can query data with the following URL:

```
/graphql/?query={readMembers{ID+FirstName+Email}}
```

The query contained in the `query` parameter can be reformatted as follows:

```graphql
{
  readMembers {
    ID
    FirstName
    Email
  }
}
```

You can apply the `Email` filter in the above example like so:

```graphql
query ($Email: String) {
  readMembers(Email: $Email) {
    ID
    FirstName
    Email
  }
}
```

And add a query variable:

```json
{
  "Email": "john@example.com"
}
```

## Pagination

The GraphQL module also provides a wrapper to return paginated and sorted
records using offset based pagination.

> This module currently does not support Relay (cursor based) pagination.
> [This blog post](https://dev-blog.apollodata.com/understanding-pagination-rest-graphql-and-relay-b10f835549e7#.kg5qkwvuz)
> describes the differences.

To have a `Query` return a page-able list of records queries should extend the
`PaginatedQueryCreator` class and return a `Connection` instance.

```php
<?php

namespace MyProject\GraphQL;

use GraphQL\Type\Definition\Type;
use SilverStripe\Security\Member;
use SilverStripe\GraphQL\Pagination\Connection;
use SilverStripe\GraphQL\Pagination\PaginatedQueryCreator;

class PaginatedReadMembersQueryCreator extends PaginatedQueryCreator
{
    public function connection()
    {
        return Connection::create('paginatedReadMembers')
            ->setConnectionType(function () {
                return $this->manager->getType('member');
            })
            ->setArgs([
                'Email' => [
                    'type' => Type::string()
                ]
            ])
            ->setSortableFields(['ID', 'FirstName', 'Email'])
            ->setConnectionResolver(function ($obj, $args, $context) {
                $member = Member::singleton();
                if (!$member->canView($context['currentUser'])) {
                    throw new \InvalidArgumentException(sprintf(
                        '%s view access not permitted',
                        Member::class
                    ));
                }
                $list = Member::get();

                // Optional filtering by properties
                if (isset($args['Email'])) {
                    $list = $list->filter('Email', $args['Email']);
                }

                return $list;
            });
    }
}

```

You will need to add a new unique query alias to your configuration:

```yml
SilverStripe\GraphQL:
  schema:
    queries:
      paginatedReadMembers: 'MyProject\GraphQL\PaginatedReadMembersQueryCreator'
```

Using a `Connection` the GraphQL server will return the results wrapped under
the `edges` result type. `Connection` supports the following arguments:

* `limit`
* `offset`
* `sortBy`

Additional arguments can be added by providing the `setArgs` function (such as
`Email` in the previous example). Each argument must be given a specific type.

Pagination information is provided under the `pageInfo` type. This object type
supports the following fields:

* `totalCount` returns the total number of items in the list,
* `hasNextPage` returns whether more records are available.
* `hasPreviousPage` returns whether more records are available by decreasing
the offset.

You can query paginated data with the following URL:

```
/graphql/?query=query+Members{paginatedReadMembers(limit:1,offset:0){edges{node{ID+FirstName+Email}}pageInfo{hasNextPage+hasPreviousPage+totalCount}}}
```

The query contained in the `query` parameter can be reformatted as follows:

```graphql
query Members {
  paginatedReadMembers(limit: 1, offset: 0) {
    edges {
      node {
        ID
        FirstName
        Email
      }
    }
    pageInfo {
      hasNextPage
      hasPreviousPage
      totalCount
    }
  }
}

```

#### Setting Pagination and Sorting options

To limit the ability for users to perform searching and ordering as they wish,
`Collection` instances can define their own limits and defaults.

* `setSortableFields` an array of allowed sort columns.
* `setDefaultLimit` integer for the default page length (default 100)
* `setMaximumLimit` integer for the maximum `limit` records per page to prevent
excessive load trying to load millions of records (default 100)

```php
return Connection::create('paginatedReadMembers')
    // ...
    ->setDefaultLimit(10)
    ->setMaximumLimit(100); // previous users requesting more than 100 records
```

#### Nested Connections

`Connection` can be used to return related objects such as `has_many` and
`many_many` models.

```php
<?php

namespace MyProject\GraphQL;

use GraphQL\Type\Definition\Type;
use SilverStripe\GraphQL\TypeCreator;
use SilverStripe\GraphQL\Pagination\Connection;

class MemberTypeCreator extends TypeCreator
{
    public function attributes()
    {
        return [
            'name' => 'member'
        ];
    }

    public function fields()
    {
        $groupsConnection = Connection::create('Groups')
            ->setConnectionType(function() {
                return $this->manager->getType('group');
            })
            ->setDescription('A list of the users groups')
            ->setSortableFields(['ID', 'Title']);

        return [
            'ID' => ['type' => Type::nonNull(Type::id())],
            'Email' => ['type' => Type::string()],
            'FirstName' => ['type' => Type::string()],
            'Surname' => ['type' => Type::string()],
            'Groups' => [
                'type' => $groupsConnection->toType(),
                'args' => $groupsConnection->args(),
                'resolve' => function($obj, $args, $context) use ($groupsConnection) {
                    return $groupsConnection->resolveList(
                        $obj->Groups(),
                        $args,
                        $context
                    );
                }
            ]
        ];
    }
}
```

```graphql
query Members {
  paginatedReadMembers(limit: 10) {
    edges {
      node {
        ID
        FirstName
        Email
        Groups(sortBy: [{field: Title, direction: DESC}]) {
          edges {
            node {
              ID
              Title
              Description
            }
          }
          pageInfo {
            hasNextPage
            hasPreviousPage
            totalCount
          }
        }
      }
    }
    pageInfo {
      hasNextPage
      hasPreviousPage
      totalCount
    }
  }
}
```

### Define Mutations

A "mutation" is a specialised GraphQL query which has side effects on your data,
such as create, update or delete. Each of these operations would be expressed
as its own mutation class. Returning an object from the `resolve()` method
will automatically include it in the response.

```php
<?php
namespace MyProject\GraphQL;

use GraphQL\Type\Definition\Type;
use SilverStripe\GraphQL\MutationCreator;
use SilverStripe\GraphQL\OperationResolver;
use SilverStripe\Security\Member;

class CreateMemberMutationCreator extends MutationCreator implements OperationResolver
{
    public function attributes()
    {
        return [
            'name' => 'createMember',
            'description' => 'Creates a member without permissions or group assignments'
        ];
    }

    public function type()
    {
        return function() {
            return $this->manager->getType('member');
        };
    }

    public function args()
    {
        return [
            'Email' => ['type' => Type::nonNull(Type::string())],
            'FirstName' => ['type' => Type::string()],
            'LastName' => ['type' => Type::string()],
        ];
    }

    public function resolve($object, array $args, $context, $info)
    {
        if (!singleton(Member::class)->canCreate($context['currentUser'])) {
            throw new \InvalidArgumentException('Member creation not allowed');
        }

        return (new Member($args))->write();
    }
}

```

We'll register this mutation through YAML configuration:

```yml
SilverStripe\GraphQL:
  schema:
    mutations:
      createMember: 'MyProject\GraphQL\CreateMemberMutationCreator'
```

You can run a mutation with the following query:

```graphql
mutation ($Email: String!) {
  createMember(Email: $Email) {
    ID
  }
}
```

This will create a new member with an email address, which you can pass in as
query variables: `{"Email": "test@test.com"}`. It'll return the new `ID`
property of the created member.

### Define Interfaces

TODO

### Define Input Types

TODO

## Testing/Debugging Queries and Mutations

An in-browser IDE for the GraphQL server is available via the [silverstripe-graphql-devtools](https://github.com/silverstripe/silverstripe-graphql-devtools) module.

As an alternative, a [desktop version](https://github.com/skevy/graphiql-app) of this application is also available. (OSX only)

## Authentication

Some SilverStripe resources have permission requirements to perform CRUD operations
on, for example the `Member` object in the previous examples.

If you are logged into the CMS and performing a request from the same session then
the same Member session is used to authenticate GraphQL requests, however if you
are performing requests from an anonymous/external application you may need to
authenticate before you can complete a request.

Please note that when implementing GraphQL resources it is the developer's
responsibility to ensure that permission checks are implemented wherever
resources are accessed.

### Basic Authentication

Silverstripe has built in support for [HTTP basic authentication](https://en.wikipedia.org/wiki/Basic_access_authentication).
It can be configured for GraphQL implementation with YAML configuration (see below).
This is kept separate from the SilverStripe CMS authenticator because GraphQL needs
to use the successfully authenticated member for CMS permission filtering, whereas
the global `BasicAuth` does not log the member in or use it for model security.

#### YAML configuration

You will need to define the class under `SilverStripe\GraphQL.authenticators`.
You can optionally provide a `priority` number if you want to control which
Authenticator is used when multiple are defined (higher priority returns first).

Here's an example for implementing HTTP basic authentication:

```yaml
SilverStripe\GraphQL:
  authenticators:
    - class: SilverStripe\GraphQL\Auth\BasicAuthAuthenticator
      priority: 10
```

#### In GraphiQL

If you want to add basic authentication support to your GraphQL requests you can
do so by adding a custom `Authorization` HTTP header to your GraphiQL requests.

If you are using the [GraphiQL macOS app](https://github.com/skevy/graphiql-app)
this can be done from "Edit HTTP Headers". The `/dev/graphiql` implementation
does not support custom HTTP headers at this point.

Your custom header should follow the following format:

```
# Key: Value
Authorization: Basic aGVsbG86d29ybGQ=
```

`Basic` is followed by a [base64 encoded](https://en.wikipedia.org/wiki/Base64)
combination of your username, colon and password. The above example is `hello:world`.

**Note:** Authentication credentials are transferred in plain text when using HTTP
basic authenticaiton. We strongly recommend using TLS for non-development use.

Example:

```shell
php -r 'echo base64_encode("hello:world");'
# aGVsbG86d29ybGQ=
```

## TODO

 * Permission checks
 * Input/constraint validation on mutations (with third-party validator)
 * CSRF protection (or token-based auth)
 * Generate CRUD operations based on DataObject reflection
 * Generate DataObject relationship CRUD operations
 * Create Enum GraphQL types from DBEnum
 * Date casting
 * Schema serialisation/caching (performance)
