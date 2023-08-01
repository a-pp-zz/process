<?php
/**
 * Run cli-process via pipes
 */
namespace AppZz\CLI;
use AppZz\Helpers\Arr;

/**
 * Class Process
 * @package AppZz\CLI
 * @author CoolSwitcher
 * @team AppZz
 * @license MIT
 * #version 2.x
 */
class Process {

	const READ_LENGTH = 1024;
	const STDIN       = 0;
	const STDOUT      = 1;
	const STDERR      = 2;

	private $_exitcode;
	private $_pipes;
	private $_process;
	private $_triggers;
	private $_cmd;

	private	$_descriptor = [
		["pipe", "r"],
		["pipe", "w"],
		["pipe", "w"]
	];

	/**
	 * @param $cmd
	 * @return Process
	 */
	public static function factory ($cmd)
	{
		return new Process ($cmd);
	}

	public function trigger ($action, $trigger)
	{
		$this->_triggers[$action] = $trigger;
		return $this;
	}

	public function output_file ($file = '', $pipe = Process::STDOUT, $append = true)
	{
		$this->_descriptor[$pipe] = ['file', $file, ($append ? 'a' : 'w')];
		return $this;
	}

	public function run ($wait_exit = false)
	{
		$this->_pipes = [];
		$os = $this->_detect_system ();

		$this->_process = proc_open (
			$this->_cmd,
			$this->_descriptor,
			$this->_pipes,
			NULL,
			NULL,
			['bypass_shell'=>($os == 'Win')]
		);

		if ($os == 'Win') {
			stream_set_blocking($this->_pipes[Process::STDOUT], 0);
			stream_set_blocking($this->_pipes[Process::STDERR], 0);
		}

		if ($wait_exit) {
			$this->_call_trigger('start');

			while (true)
			{
				$this->_get_status ();

				if (isset($this->_exitcode)) {
					break;
				}
			}
		}

		return $this;
	}

	public function get_exitcode ()
	{
		return $this->_exitcode;
	}

	private function __construct ($cmd)
	{
		$this->_cmd = $cmd;
	}

	private function _get_status ()
	{
		$process_info = proc_get_status($this->_process);

		$buffer1 = $this->_fillbuffer (Process::STDOUT);
		$buffer2 = $this->_fillbuffer (Process::STDERR);

		$data = [];

		$data[Process::STDOUT]['buffer']  = $buffer1;
		$last_line = is_array($buffer1) ? array_pop ($buffer1) : null;
		$data[Process::STDOUT]['message'] = $last_line;

		$data[Process::STDERR]['buffer']  = $buffer2;
		$last_line = is_array($buffer2) ? array_pop ($buffer2) : null;
		$data[Process::STDERR]['message'] = $last_line;

		if (Arr::get($process_info, 'running')) {
			$this->_call_trigger('running', $data);
		} else {
			$this->_exitcode = Arr::get($process_info, 'exitcode');
			$this->_call_trigger('finish', $data);
		}

		return $this;
	}

	private function _call_trigger ($action, $data = [])
	{
		$f = Arr::get($this->_triggers, $action);

		if ( ! $f) {
			$f = Arr::get($this->_triggers, 'all');
		}

		if ($f) {
			call_user_func ($f, $data);
		}

		return $this;
	}

	private function _fillbuffer ($pipe = Process::STDOUT)
	{
		$pipe_type = Arr::path ($this->_descriptor, $pipe.'.0');

		if ($pipe_type != 'pipe') {
			return false;
		}

		$buffer = [];
		$pipes = [Arr::get($this->_pipes, $pipe)];

		if (feof(Arr::get($pipes, 0))) {
			return false;
		}

		$ready = stream_select ($pipes, $write, $ex, 1, 0);

		if ($ready === false) {
			return false;
		} elseif ($ready === 0 ) {
			return $buffer;
		}

		$status = ['unread_bytes' => 1];
		$read = true;

		while ($status['unread_bytes'] > 0) {
			$read = fread(Arr::get($pipes, 0), Process::READ_LENGTH);

			if ($read !== false) {
				$buffer[] = trim($read);
			}

			$status = stream_get_meta_data(Arr::get($pipes, 0));
		}

		return $buffer;
	}

    private function _detect_system () {
        switch (true) {
            case stristr(PHP_OS, 'DAR'):
                $os = 'macOS';
            break;
            case stristr(PHP_OS, 'LINUX'):
                $os = 'Linux';
            break;
            default :
                $os = 'Win';
            break;
        }

        return $os;
    }
}
