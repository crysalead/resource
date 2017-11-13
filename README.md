# Resource - Resource Dispatching Strategy

[![Build Status](https://travis-ci.org/crysalead/resource.svg?branch=master)](https://travis-ci.org/crysalead/resource)
[![Code Coverage](https://scrutinizer-ci.com/g/crysalead/resource/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/crysalead/resource/)

Resource dispatching strategy for [router](https://github.com/crysalead/router).

## Installation

```bash
composer require crysalead/resource
```

## API

### Setting up the strategy

Example of routes definition:

```php
use Lead\Router\Router;
use Lead\Router\Resource\ResourceStrategy;

$router = new Router();

$router->strategy('resource', new ResourceStrategy());


$router->resource('MyResource');

// Matching any following URLs
// /my_resource
// /my_resource/:<action>
// /my_resource/<id>/:<action>
// /my_relation/<id>/my_resource
// /my_relation/<id>/my_resource/:<action>

```
