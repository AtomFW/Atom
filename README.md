
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

```php
<?php

    $config = [
        'userClass' => \Atom\models\User::class,
        'db' => [
            'dsn' => "mysql:host=localhost;port=3306;dbname=atom",
            'user' => "root",
            'password' => "",
        ]
    ];

    $App = new Atom(dirname(__DIR__), $config);
    $App->newAplication()->run();
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

