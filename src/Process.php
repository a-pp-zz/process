<?php
/**
 * Run CLI-process in background and get status, log via triggers
 */
namespace AppZz\CLI;
use AppZz\Helpers\Arr;

/**
 * Class Process
 * @package AppZz\CLI
 * @author CoolSwitcher
 * @team AppZz
 * @license MIT
 * @version 2.x
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

	private	$_descriptor = [];
	private $_log = [];
	private $_logfiles = [];

	/**
	 * @param $cmd
	 * @return Process
	 */
	public static function factory ($cmd)
	{
		return new Process ($cmd);
	}

	/**
	 * Set trigger
	 * @param  string $action  all|running|finished
	 * @param  Closure $trigger closure func
	 * @return $this
	 */
	public function trigger ($action, $trigger)
	{
		$this->_triggers[$action] = $trigger;
		return $this;
	}

	/**
	 * Set output to files
	 * @param  string  $file   path to file
	 * @param  int  $pipe   pipe
	 * @param  boolean $append append or no
	 * @return $this
	 */
	public function output_file ($file = '', $pipe = Process::STDOUT, $append = true)
	{
		$this->_descriptor[$pipe] = ['file', $file, ($append ? 'a' : 'w')];
		$this->_logfiles[$pipe] = $file;
		return $this;
	}

	/**
	 * Get output log from files or pipes
	 * @param  int  $pipe
	 * @param  boolean $as_text format to text
	 * @return mixed
	 */
	public function get_log ($pipe = Process::STDOUT, $as_text = false)
	{
		$logfile = Arr::get ($this->_logfiles, $pipe);

		if ( ! empty ($logfile AND file_exists ($logfile))) {
			return file_get_contents ($logfile);
		}

		$log = Arr::get ($this->_log, $pipe, []);

		if ($as_text) {

			if (empty ($log)) {
				return false;
			}
			
			$text = '';

			foreach ($log as $txt) {
				if ( ! empty ($txt)) {
					$text .= implode ("\n", $txt) . "\n";
				}
			}

			return trim ($text);
		}

		return $log;
	}

	/**
	 * Run process
	 * @param  boolean $wait_exit wait exitcode or no
	 * @return $this
	 */
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

			while (true) :
				$this->_get_status ();

				if (isset($this->_exitcode)) {
					break;
				}
			endwhile;
		}

		return $this;
	}

	/**
	 * Get exitcode
	 * @return mixed
	 */
	public function get_exitcode ()
	{
		return $this->_exitcode;
	}

	private function __construct ($cmd)
	{
		$this->_cmd = $cmd;

		$this->_descriptor = [
			["pipe", "r"],
			["pipe", "w"],
			["pipe", "w"]
		];

		$this->_log = [];
		$this->_logfiles = [];
	}

	/**
	 * Get process status, fill data & call triggers
	 * @return $this
	 */
	private function _get_status ()
	{
		$process_info = proc_get_status ($this->_process);
		$buff1 = $this->_fill_buffer (Process::STDOUT);
		$buff2 = $this->_fill_buffer (Process::STDERR);
		$data = [];

		if ( ! empty ($buff1)) {
			$data[Process::STDOUT] = $buff1;
		}

		if ( ! empty ($buff2)) {
			$data[Process::STDERR] = $buff2;
		}

		if (Arr::get($process_info, 'running')) {
			$this->_call_trigger('running', $data);
		} else {
			$this->_exitcode = Arr::get($process_info, 'exitcode');
			$this->_call_trigger('finished', $data);
		}

		return $this;
	}

	/**
	 * Fill buffers
	 * @param  int $pipe
	 * @return mixed
	 */
	private function _fill_buffer ($pipe = Process::STDOUT)
	{
		if ( ! $this->_is_pipe()) {
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

		if ( ! empty ($buffer)) {
			$buffer_vals = trim (implode ('', $buffer));
			//ignore dummy output
			if ( ! empty ($buffer_vals)) {
				$ret = [];
				$this->_log[$pipe][] = $ret['buffer'] = $buffer;
				$ret['message'] = array_pop ($buffer);
				return $ret;
			}
		}

		return false;
	}

	/**
	 * Detect pipe-type file or pipe
	 * @param  int $pipe
	 * @return boolean
	 */
	private function _is_pipe ($pipe = Process::STDOUT)
	{
		return (Arr::path ($this->_descriptor, $pipe.'.0') == 'pipe');
	}

	/**
	 * Try to call closure if present
	 * @param  string $action
	 * @param  array  $data
	 * @return $this
	 */
	private function _call_trigger ($action, $data = [])
	{
		if (empty ($data)) {
			$data = null;
		}

		$f = Arr::get($this->_triggers, $action);

		if ( ! $f) {
			$f = Arr::get($this->_triggers, 'all');
		}

		if ($f) {
			call_user_func ($f, $data);
		}

		return $this;
	}

	/**
	 * Detect OS type
	 * @return string
	 */
    private function _detect_system ()
    {
        switch (true) :
            case stristr(PHP_OS, 'DAR'):
                $os = 'macOS';
            break;
            case stristr(PHP_OS, 'LINUX'):
                $os = 'Linux';
            break;
            default :
                $os = 'Win';
            break;
        endswitch;

        return $os;
    }
}
