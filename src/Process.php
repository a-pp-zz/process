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
 */
class Process {

	const READ_LENGTH       = 1024;
	const STDIN             = 0;
	const STDOUT            = 1;
	const STDERR            = 2;

	private $_exitcode;
	private $_pipes;
	private $_pipe;
	private $_process;
	private $_triggers;

	/**
	 * @param $cmd
	 * @param int $pipe
	 * @return Process
	 */
	public static function factory ($cmd, $pipe = Process::STDOUT)
	{
		return new Process ($cmd, $pipe);
	}

	public function trigger ($action, $trigger)
	{
		$this->_triggers[$action] = $trigger;
		return $this;
	}

	public function run ()
	{
		$this->_call_trigger('start');

		while (TRUE)
		{
			$this->_get_status ();

			if (isset($this->_exitcode))
				break;
		}

		return $this;
	}

	public function get_exitcode ()
	{
		return $this->_exitcode;
	}

	private function __construct ($cmd, $pipe)
	{
		$this->_pipes = [];
		$this->_pipe = $pipe;

		$descriptor = [
			["pipe", "r"], // in
			["pipe", "w"], // out
			["pipe", "w"]  // err
		];

		$this->_process = proc_open(
			$cmd,
			$descriptor,
			$this->_pipes,
			NULL,
			NULL,
			['bypass_shell'=>TRUE]
		);

		//Set STDOUT and STDERR to non-blocking
		stream_set_blocking($this->_pipes[Process::STDOUT], 0);
		stream_set_blocking($this->_pipes[Process::STDERR], 0);
	}

	private function _get_status ()
	{
		$process_info = proc_get_status($this->_process);
		$data = [];
		$buffer          = $this->_fillbuffer ();
		$data['buffer']  = $buffer;
		$last_line       = is_array($buffer) ? array_pop ($buffer) : NULL;
		$data['message'] = $last_line;

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

	private function _fillbuffer ()
	{
		$buffer = [];
		$pipes = [Arr::get($this->_pipes, $this->_pipe)];

		if (feof(Arr::get($pipes, 0)))
			return FALSE;

		$ready = stream_select ($pipes, $write, $ex, 1, 0);

		if ($ready === FALSE) {
			return FALSE;
		}
		elseif ($ready === 0 ) {
			return $buffer; // will be empty
		}

		$status = ['unread_bytes' => 1];
		$read = TRUE;

		while ( $status['unread_bytes'] > 0 ) {
			$read = fread(Arr::get($pipes, 0), Process::READ_LENGTH);

			if ($read !== FALSE) {
				$buffer[] = trim($read);
			}

			$status = stream_get_meta_data(Arr::get($pipes, 0));
		}

		return $buffer;
	}
}
