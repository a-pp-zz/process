# Process v2.0
Run cli-process in background and get output from pipes or files
Works on *nix & windows (with limitations)

## Install

```php
composer require appzz/process
```

## Basic usage

```php
use \AppZz\CLI\Process;

chdir(realpath(__DIR__));
require_once 'vendor/autoload.php';

$cmd = 'ping google.com';

//On windows need specify pipe!!! On *nix systems you can get output from all pipes
$pr = Process::factory ($cmd, Process::STDOUT);

//Trigger for all events
$pr->trigger('all', function ($data) {
	var_dump ($data);
	echo PHP_EOL;
});

//Or you can specify separated triggers

$pr->trigger('running', function ($data) {
	//get current output
  var_dump ($data[Process::STDOUT]);
  var_dump ($data[Process::STDERR]);
	echo PHP_EOL;
});

$pr->trigger('start', function () {
	echo 'Start!!!';
	echo PHP_EOL;
});

$pr->trigger('finished', function () {
	echo 'Finished!!!';
	echo PHP_EOL;
});

//Get exitcode of process, 0 on success
$exitcode = $pr->run(true);

//Or run and go away
$pr->run(false);

//If you don't want use triggers, you can use text files
$pr->output_file('./out.txt', Process::STDOUT, false);
$pr->output_file('./err.txt', Process::STDERR, false);

//Get full output
$std_out = $pr->get_log(Process::STDOUT);
$err_log = $st->get_log(Process::STDERR);

//Or
$std_out = $pr->get_output()
$err_log = $st->get_error();
```
