<?php
/**
 * Run CLI-process in background and get status, log via triggers
 */

namespace AppZz\CLI;

use AppZz\Helpers\Arr;
use Closure;

/**
 * Class Process
 * @package AppZz\CLI
 * @author CoolSwitcher
 * @team AppZz
 * @license MIT
 * @version 2.x
 */
class Process
{
    const READ_LENGTH = 1024;
    const STDIN = 0;
    const STDOUT = 1;
    const STDERR = 2;

    private $_exitcode;
    private $_pipes;
    private $_process;
    private $_triggers;
    private $_cmd;

    private $_descriptor;
    private $_log;
    private $_logfiles;
    private $_needed_pipes;

    /**
     * @param string $cmd
     * @param int $pipe needed pipe
     * @return Process
     */
    public static function factory(string $cmd, int $pipe = 0): Process
    {
        return new Process ($cmd, $pipe);
    }

    /**
     * Set trigger
     * @param string $action all|running|finished
     * @param Closure $trigger closure function
     * @return $this
     */
    public function trigger(string $action, closure $trigger): Process
    {
        $this->_triggers[$action] = $trigger;
        return $this;
    }

    /**
     * Set output to files
     * @param string $file path to file
     * @param int $pipe pipe
     * @param boolean $append append or no
     * @return $this
     */
    public function output_file(string $file = '', int $pipe = Process::STDOUT, bool $append = true): Process
    {
        $this->_descriptor[$pipe] = ['file', $file, ($append ? 'a' : 'w')];
        $this->_logfiles[$pipe] = $file;
        return $this;
    }

    /**
     * Get output log from files or pipes
     * @param int $pipe
     * @param string $sep separator for text
     * @return mixed
     */
    public function get_log(int $pipe = Process::STDOUT, string $sep = ''): string
    {
        $logfile = Arr::get($this->_logfiles, $pipe);

        if (!empty ($logfile and file_exists($logfile))) {
            return file_get_contents($logfile);
        }

        $log = Arr::get($this->_log, $pipe, []);

        $text = '';

        if (empty ($log)) {
            return $text;
        }

        foreach ($log as $txt) {
            if (!empty ($txt)) {
                $text .= implode($sep, $txt) . $sep;
            }
        }

        return trim($text);
    }

    /**
     * Get output
     * @param string $sep
     * @return string
     */
    public function get_output(string $sep = ''): string
    {
        return $this->get_log(Process::STDOUT, $sep);
    }

    /**
     * Get error
     * @param string $sep
     * @return string
     */
    public function get_error(string $sep = ''): string
    {
        return $this->get_log(Process::STDERR, $sep);
    }

    /**
     * Run process
     * @param bool $wait_exit wait exitcode or no
     * @return int
     */
    public function run(bool $wait_exit = false): int
    {
        $this->_pipes = [];
        $os = $this->_detect_system();

        $this->_process = @proc_open(
            $this->_cmd,
            $this->_descriptor,
            $this->_pipes,
            NULL,
            NULL,
            ['bypass_shell' => ($os == 'Win')]
        );

        if (!is_resource($this->_process)) {
            return 1;
        }

        if ($os == 'Win') {
            stream_set_blocking($this->_pipes[Process::STDOUT], 0);
            stream_set_blocking($this->_pipes[Process::STDERR], 0);
        }

        if ($wait_exit) {
            $this->_call_trigger('start');

            while (true) :
                $this->_get_status();

                if (isset($this->_exitcode)) {
                    return $this->_exitcode;
                }
            endwhile;
        }

        return -1;
    }

    private function __construct($cmd, $pipe = null)
    {
        $this->_cmd = $cmd;

        $this->_descriptor = [
            Process::STDIN => ["pipe", "r"],
            Process::STDOUT => ["pipe", "w"],
            Process::STDERR => ["pipe", "w"]
        ];

        $this->_log = [];
        $this->_logfiles = [];

        if (empty($pipe)) {
            $pipe = [Process::STDOUT, Process::STDERR];
        } else {
            $pipe = (array)$pipe;
        }

        $this->_needed_pipes = $pipe;
    }

    /**
     * Get process status, fill data & call triggers
     * @return void
     */
    private function _get_status(): void
    {
        if (!is_resource($this->_process)) {
            $this->_exitcode = 1;
            return;
        }

        $process_info = proc_get_status($this->_process);
        $data = [];

        foreach ($this->_needed_pipes as $pipe) {
            $buffer = $this->_fill_buffer($pipe);
            if (!empty ($buffer)) {
                $data[$pipe] = $buffer;
            }
        }

        if (Arr::get($process_info, 'running')) {
            $this->_call_trigger('running', $data);
        } else {
            $this->_exitcode = Arr::get($process_info, 'exitcode');
            $this->_call_trigger('finished', $data);
        }
    }

    /**
     * Fill buffers
     * @param int $pipe
     * @return array|bool
     */
    private function _fill_buffer(int $pipe = Process::STDOUT): ?array
    {
        $buffer = [];

        if (!$this->_is_pipe($pipe)) {
            return $buffer;
        }

        $pipes = [Arr::get($this->_pipes, $pipe)];

        if (feof(Arr::get($pipes, 0))) {
            return $buffer;
        }

        $ready = stream_select($pipes, $write, $ex, 1, 0);

        if ($ready === false) {
            return null;
        } elseif ($ready === 0) {
            return $buffer;
        }

        $status = ['unread_bytes' => 1];

        while ($status['unread_bytes'] > 0) {
            $read = fread(Arr::get($pipes, 0), Process::READ_LENGTH);

            if ($read !== false) {
                $buffer[] = trim($read);
            }

            $status = stream_get_meta_data(Arr::get($pipes, 0));
        }

        if (!empty ($buffer)) {
            $buffer_vals = trim(implode('', $buffer));
            //ignore dummy output
            if (!empty ($buffer_vals)) {
                $ret = [];
                $this->_log[$pipe][] = $ret['buffer'] = $buffer;
                $ret['message'] = array_pop($buffer);
                return $ret;
            }
        }

        return null;
    }

    /**
     * Detect pipe-type file or pipe
     * @param int $pipe
     * @return bool
     */
    private function _is_pipe(int $pipe = Process::STDOUT): bool
    {
        return (Arr::path($this->_descriptor, $pipe . '.0') == 'pipe');
    }

    /**
     * Try to call closure if present
     * @param string $action
     * @param array $data
     * @return void
     */
    private function _call_trigger(string $action, array $data = []): void
    {
        if (empty ($data)) {
            $data = null;
        }

        $f = Arr::get($this->_triggers, $action);

        if (!$f) {
            $f = Arr::get($this->_triggers, 'all');
        }

        if ($f) {
            call_user_func($f, $data);
        }
    }

    /**
     * Detect OS type
     * @return string
     */
    private function _detect_system(): string
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
