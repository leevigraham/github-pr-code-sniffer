*This project is not stable, tested or production ready. It's a proof of concept and a work in progress.*

# Github Pull Request PHP Codesniffer

A fairly straight forward Silex app for processing pull request events, running PHP Codesniffer over the changed files and reporting any errors as comments.

After cloning run the following: `git update-index --assume-unchanged resources/config/prod.php` to make sure your app secret isn't exposed.