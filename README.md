** This project is not stable, tested or production ready. It's a proof of concept and a work in progress. **

# Github Pull Request PHP Codesniffer

A fairly straight forward Silex app for processing pull request events, running PHP Codesniffer over the changed files and reporting any errors as comments.

Installation
------------

### Without git clone

Run the following commands:

    curl -s http://getcomposer.org/installer | php
    php composer.phar create-project lyrixx/Silex-Kitchen-Edition PATH/TO/YOUR/APP
    cd PATH/TO/YOUR/APP

### With git clone

Run the following commands:

    git clone https://github.com/lyrixx/Silex-Kitchen-Edition.git PATH/TO/YOUR/APP
    cd PATH/TO/YOUR/APP
    curl -s http://getcomposer.org/installer | php
    php composer.phar install

### Then

You can edit `resources/config/prod.php` and start hacking in `src/controllers.php`

Help
----

* http://silex.sensiolabs.org/documentation
