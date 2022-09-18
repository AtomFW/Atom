
# Atom

Atom is mini mvc framework to simple work.

Atom to mini framework mvc do prostej pracy.


## Installation

Install Atom with gh repo

```gh repo
  gh repo clone AtomFW/Atom

```
    
## Demo

A Download this reposytory to your computer and runing in PHP server(interpreter)!

## Usage/Examples

Params
```php 
    Atom\core\Application::INSTALL_MOD_CONTROLLERS
    Atom\core\Application::INSTALL_MOD_MIGRATIONS
    Atom\core\Application::INSTALL_MOD_MODELS
    Atom\core\Application::INSTALL_MOD_PUBLIC
    Atom\core\Application::INSTALL_MOD_RUNTIME
    Atom\core\Application::INSTALL_MOD_VIEWS
```

Add params to $params array

```php
<?php

    $config = [
        'db' => [
            'dsn' => "mysql:host=localhost;port=3306;dbname=atom",
            'user' => "root",
            'password' => "",
        ]
    ];

    $params = [];

    $App = new Atom(dirname(__DIR__), $config);
    $App->newAplication($params)->install();
?>
```


## Documentation

In Progress


## Used By

This project is used by the following project:

- BlackMinCMS



## License

[MIT](https://choosealicense.com/licenses/mit/) 


# Hi, I'm Timonix! 👋
Am Creating this framework to BlackMinCMS

## Authors

- [@Timonix](https://www.github.com/di-Timonix)

