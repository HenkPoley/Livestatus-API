<?php declare(strict_types=1);

// TODO: Namespace

class LiveStatusException extends Exception
{
}

/**
 * Class LiveStatusClient.
 *
 * TODO: Fix all the possible breaking states in getQuery() that should be impossible
 *
 * TODO: Write a new class where the impossible states are impossible
 */
class LiveStatusClient
{
    /**
     * @var bool
     */
    public $pretty_print;

    /**
     * @var string
     */
    private $socket_path;

    /**
     * @var null|resource
     * @psalm-var null|resource|closed-resource
     */
    private $socket;

    /**
     * @var null|LiveStatusQuery
     */
    private $query;

    /**
     * LiveStatusClient constructor.
     * @param string $socket_path
     */
    public function __construct(string $socket_path)
    {
        $this->socket_path = $socket_path;
        $this->socket = null;
        $this->query = null;
        $this->pretty_print = false;

    }

    private function _connect(): void
    {
        $this->socket = stream_socket_client($this->socket_path);
    }

    private function _jsonOpts(): ?int
    {
        $json_opts = null;
        $this->pretty_print && $json_opts = JSON_PRETTY_PRINT;

        return $json_opts;
    }

    /**
     * @param string $response
     * @return array
     * @throws LiveStatusException
     */
    private function _parseResponse(string $response): array
    {
        /** @var null|array $json_decoded_response Array or null due to assoc = true */
        $json_decoded_response = json_decode($response, true) ?? [];

        if (!is_array($json_decoded_response)) {
            throw new LiveStatusException('Whoops, expected array from json_decode() in $json_decoded_response', 405);
        }

        if (!($this->query instanceof LiveStatusQuery)) {
            throw new LiveStatusException('You did not set up any query', 405);
        }
        if ($this->query->stats) {
            return $json_decoded_response;
        }

        $cols = $this->query->columns;

        if (!$cols) {
            /**
             * @var array|mixed $cols
             * @psalm-var array<array-key, array-key>|mixed $cols
             */
            $cols = array_shift($json_decoded_response);

            if (!is_array($cols)) {
                throw new LiveStatusException('Whoops, _parseResponse expected cols array', 405);
            }
        }

        $results = [];

        foreach ($json_decoded_response as $row) {
            if (!is_array($row)) {
                throw new LiveStatusException('Whoops, _parseResponse expected row array', 405);
            }
            $results[] = array_combine($cols, $row);
        }

        return $results;
    }

    /**
     * @return array
     * @throws LiveStatusException
     */
    private function _fetchResponse(): array
    {
        $response = '';
        /** @var int|string $status */
        $status = 500;
        if (!is_resource($this->socket)) {
            throw new LiveStatusException('Cannot fetch a response from a closed socket', 405);
        }
        if ($status_line = fgets($this->socket)) {
            [$status, $length] = explode(' ', $status_line);

            while ($line = fgets($this->socket)) {
                $response .= $line;
            }
        }
        if (200 !== (int)$status) {
            throw new LiveStatusException($response, (int)$status);
        }

        return $this->_parseResponse($response);
    }

    /**
     * @param LiveStatusQuery $query
     * @return array
     * @throws LiveStatusException
     */
    public function runQuery(LiveStatusQuery $query): array
    {
        $this->_connect();
        $this->query = $query;

        $query_string = $query->getQueryString();
        if (!is_resource($this->socket)) {
            throw new LiveStatusException('Cannot run a LiveStatus query on a closed socket', 405);
        }
        fwrite($this->socket, $query_string);
        $response = $this->_fetchResponse();
        fclose($this->socket);

        return $response;
    }

    /**
     * @throws LiveStatusException
     */
    public function verify_post_request(): void
    {
        // Defined in index.php. TODO: No globals
        global $request_method;
        if ('POST' !== $request_method) {
            header('Allow: POST');

            throw new LiveStatusException("Invalid request method: {$request_method}. Use POST instead.", 405);
        }
    }

    /**
     * @param LiveStatusCommand $command
     * @throws LiveStatusException
     */
    public function runCommand(LiveStatusCommand $command): void
    {
        $this->verify_post_request();
        $command_string = $command->getCommandString();
        $this->_connect();
        if (!is_resource($this->socket)) {
            throw new LiveStatusException('Cannot run a LiveStatus command on a closed socket', 405);
        }
        fwrite($this->socket, $command_string);
        fclose($this->socket);
    }

    /**
     * TODO: Make impossible states impossible.
     *
     * @param string $action
     * @psalm-param 'hosts'|'services'|'log'|'hostgroups'|'servicegroups'|'contactgroups'|'servicesbygroup'|'servicesbyhostgroup'|'hostsbygroup'|'contact'|'commands'|'timeperiods'|'downtimes'|'comments'|'status'|'columns'|'statehist' $action
     * @param string[]|string[][] $args
     * @return array
     * @throws LiveStatusException
     */
    public function getQuery(string $action, array $args = []): array
    {
        $query = new LiveStatusQuery($action);

        foreach ($args as $key => $val) {
            switch ($key) {
                case 'Columns':
                    if (is_string($val)) {
                        $columns = explode(',', $val);
                        $query->setColumns($columns);

                        break;
                    }

                    throw new LiveStatusException('Whoops, we expected Columns val to be a string', 405);
                case 'Filter': // Backwards compatibility
                case 'Filters':
                    if (is_array($val)) {
                        foreach ($val as $subvalue) {
                            $query->addFilter($subvalue);
                        }
                    } else {
                        $query->addFilter($val);
                    }

                    break;
                case 'Stats':
                    if (is_array($val)) {
                        foreach ($val as $subvalue) {
                            $query->addStat($subvalue);
                        }
                    } else {
                        $query->addStat($val);
                    }

                    break;
                case 'Option':
                default:
                    if (is_string($key)) {
                        if (is_string($val)) {
                            $query->setOption($key, $val);

                            break;
                        }

                        throw new LiveStatusException('Whoops, by default we expected the Option val to be a string', 405);
                    }

                    throw new LiveStatusException('Whoops, by default we expected the Option key to be a string', 405);
            }
        }

        return $this->runQuery($query);
    }

    /**
     * @param array $args
     * @psalm-param array{author?: null|string, comment?: null|string, downtime_id?: null|string, duration?: int, end_time?: int, host?: null|string, notify?: null|int, persistent?: null|int, scope?: null|string, service?: null|string, start_time?: int, sticky?: null|int} $args
     * @throws LiveStatusException
     */
    public function acknowledgeProblem(array $args): void
    {
        $cmd = new AcknowledgeCommand($args);
        $this->runCommand($cmd);
    }

    /**
     * @param array $args
     * @psalm-param array{author?: null|string, comment?: null|string, downtime_id?: null|string, duration?: int, end_time?: int, host?: null|string, notify?: null|int, persistent?: null|int, scope?: null|string, service?: null|string, start_time?: int, sticky?: null|int} $args
     * @throws LiveStatusException
     */
    public function cancelDowntime(array $args): void
    {
        $cmd = new CancelDowntimeCommand($args);
        $this->runCommand($cmd);
    }

    /**
     * @param array $args
     * @psalm-param array{author?: null|string, comment?: null|string, downtime_id?: null|string, duration?: int, end_time?: int, host?: null|string, notify?: null|int, persistent?: null|int, scope?: null|string, service?: null|string, start_time?: int, sticky?: null|int} $args
     * @throws LiveStatusException
     */
    public function scheduleDowntime(array $args): void
    {
        $cmd = new ScheduleDowntimeCommand($args);
        $this->runCommand($cmd);
    }

    /**
     * @param array $args
     * @psalm-param array{author?: null|string, comment?: null|string, downtime_id?: null|string, duration?: int, end_time?: int, host?: null|string, notify?: null|int, persistent?: null|int, scope?: null|string, service?: null|string, start_time?: int, sticky?: null|int} $args
     * @throws LiveStatusException
     */
    public function disableNotifications(array $args): void
    {
        $cmd = new DisableNotificationsCommand($args);
        $this->runCommand($cmd);
    }

    /**
     * @param array $args
     * @psalm-param array{author?: null|string, comment?: null|string, downtime_id?: null|string, duration?: int, end_time?: int, host?: null|string, notify?: null|int, persistent?: null|int, scope?: null|string, service?: null|string, start_time?: int, sticky?: null|int} $args
     * @throws LiveStatusException
     */
    public function enableNotifications(array $args): void
    {
        $cmd = new EnableNotificationsCommand($args);
        $this->runCommand($cmd);
    }
}

class LiveStatusQuery
{
    /**
     * @var string[]
     */
    public $columns = [];

    /**
     * @var string[]
     */
    public $stats = [];

    /**
     * @var string[]
     */
    private $options = [];

    /**
     * @var string[]
     */
    private $filters = [];

    /**
     * @var string
     */
    private $topic;

    /**
     * LiveStatusQuery constructor.
     * @param string $topic
     * @param string[] $options
     * @param string[] $columns
     * @psalm-param array<string, string> $options
     * @psalm-param array<string, string> $columns
     */
    public function __construct(string $topic, array $options = [], array $columns = [])
    {
        $this->topic = $topic;
        $this->columns = $columns;
        $this->filters = [];
        $this->stats = [];
        $this->options = $options;
        $this->options['OutputFormat'] = 'json';
        $this->options['ResponseHeader'] = 'fixed16';
    }

    public function setOption(string $name, string $value): void
    {
        $this->options[$name] = $value;
    }

    /**
     * @param string[] $column_list
     */
    public function setColumns(array $column_list): void
    {
        $this->columns = $column_list;
    }

    public function addFilter(string $filter): void
    {
        $this->filters[] = $filter;
    }

    public function addStat(string $stat): void
    {
        $this->stats[] = $stat;
    }

    /**
     * @return string
     */
    public function getQueryString(): string
    {
        $query = [];

        $query[] = "GET {$this->topic}";
        $this->columns && $query[] = 'Columns: ' . implode(' ', $this->columns);

        foreach ($this->filters as $filter) {
            $query[] = "Filter: {$filter}";
        }

        foreach ($this->stats as $stat) {
            $query[] = "Stats: {$stat}";
        }

        foreach ($this->options as $key => $value) {
            $query[] = "{$key}: {$value}";
        }

        $query[] = "\n";

        return implode("\n", $query);
    }
}

/** TODO: Fix unprovable mess in _processArgs() */
abstract class LiveStatusCommand
{
    /**
     * @var string
     */
    protected $action;

    /**
     * @var array
     * @psalm-var array{author?: null|string, comment?: null|string, downtime_id?: null|string, duration?: int, end_time?: int, host?: null|string, notify?: int|null, persistent?: int|null, scope?: null|string, service?: null|string, start_time?: int, sticky?: int|null}
     */
    protected $args = [];

    /**
     * @var array
     * @psalm-var array{author?: null|string, comment?: null|string, downtime_id?: null|string, duration?: int, end_time?: int, host?: null|string, notify?: int|null, persistent?: int|null, scope?: null|string, service?: null|string, start_time?: int, sticky?: int|null}
     */
    protected $required = [];

    /**
     * @var array
     * @psalm-var array{author?: null|string, comment?: null|string, downtime_id?: null|string, duration?: int, end_time?: int, host?: null|string, notify?: int|null, persistent?: int|null, scope?: null|string, service?: null|string, start_time?: int, sticky?: int|null}
     */
    protected $fields = [];

    /**
     * LiveStatusCommand constructor.
     * @param array $args
     * @psalm-param array{author?: null|string, comment?: null|string, downtime_id?: null|string, duration?: int, end_time?: int, host?: null|string, notify?: null|int, persistent?: null|int, scope?: null|string, service?: null|string, start_time?: int, sticky?: null|int} $args
     */
    public function __construct(array $args = [])
    {
        $this->action = '';
        $this->args = $args;
        $this->required = [];
        $this->fields = [];
    }

    /**
     * @throws LiveStatusException
     */
    private function _validateArgs(): void
    {
        $required = $this->required;

        foreach ($required as $field) {
            if (is_string($field) || is_int($field)) {
                if (!array_key_exists($field, $this->args)) {
                    error_log("missing {$field}");

                    throw new LiveStatusException(
                        "Required field '{$field}' is missing",
                        400
                    );
                }
            } else {
                throw new LiveStatusException('At least one field is not a valid PHP key (not a string, nor int)', 400);
            }
        }
    }

    /**
     * TODO: Isn't this some kind of array_merge() variant ??
     *
     * TODO: Fix psalm moping about InvalidPropertyAssignmentValue and PropertyTypeCoercion
     */
    protected function _processArgs(): void
    {
        foreach (array_keys($this->fields) as $field) {
            if (array_key_exists($field, $this->args)) {
                $this->fields[$field] = $this->args[$field];
            }
        }
        $this->args = $this->fields;
    }

    /**
     * @return string
     * @throws LiveStatusException
     */
    public function getCommandString(): string
    {
        $this->_validateArgs();
        $this->_processArgs();
        $command = 'COMMAND ';
        $command .= sprintf('[%d] ', time());
        $command .= "{$this->action};";
        $command .= implode(';', $this->args);
        $command .= "\n\n";

        return $command;

        // $time = time();
        // $arguments = implode(';', $this->args);
        //
        // return "COMMAND [$time]{$this->action};$arguments\n\n";
    }
}

class AcknowledgeCommand extends LiveStatusCommand
{
    /**
     * AcknowledgeCommand constructor.
     * @param array $args
     * @psalm-param array{author?: null|string, comment?: null|string, downtime_id?: null|string, duration?: int, end_time?: int, host?: null|string, notify?: null|int, persistent?: null|int, scope?: null|string, service?: null|string, start_time?: int, sticky?: null|int} $args
     */
    public function __construct(array $args = [])
    {
        parent::__construct($args);
        $this->action = 'ACKNOWLEDGE_SVC_PROBLEM';
        $this->required = [
            'host',
            'author',
            'comment',
        ];

        $this->fields = [
            'host' => '',
            'service' => '',
            'sticky' => 1,
            'notify' => 1,
            'persistent' => 1,
            'author' => '',
            'comment' => '',
        ];
    }

    protected function _processArgs(): void
    {
        parent::_processArgs();

        if (isset($this->args['service']) && !$this->args['service']) {
            unset($this->args['service']);
            $this->action = 'ACKNOWLEDGE_HOST_PROBLEM';
        }
    }
}

class CancelDowntimeCommand extends LiveStatusCommand
{
    /**
     * CancelDowntimeCommand constructor.
     * @param array $args
     * @psalm-param array{author?: null|string, comment?: null|string, downtime_id?: null|string, duration?: int, end_time?: int, host?: null|string, notify?: null|int, persistent?: null|int, scope?: null|string, service?: null|string, start_time?: int, sticky?: null|int} $args
     */
    public function __construct(array $args = [])
    {
        parent::__construct($args);
        $this->action = 'DEL_HOST_DOWNTIME';
        $this->required = [
            'downtime_id',
        ];

        $this->fields = [
            'downtime_id' => '',
            'service' => null,
        ];
    }

    protected function _processArgs(): void
    {
        parent::_processArgs();

        if (isset($this->args['service'])) {
            $this->action = 'DEL_SVC_DOWNTIME';
        }
    }
}

class ScheduleDowntimeCommand extends LiveStatusCommand
{
    /**
     * ScheduleDowntimeCommand constructor.
     * @param array $args
     * @psalm-param array{author?: null|string, comment?: null|string, downtime_id?: null|string, duration?: int, end_time?: int, host?: null|string, notify?: null|int, persistent?: null|int, scope?: null|string, service?: null|string, start_time?: int, sticky?: null|int} $args
     */
    public function __construct(array $args = [])
    {
        parent::__construct($args);
        $this->action = 'SCHEDULE_SVC_DOWNTIME';
        $this->required = [
            'host',
            'author',
            'comment',
        ];

        $this->fields = [
            'host' => '',
            'service' => '',
            'start_time' => 0,
            'end_time' => 0,
            'fixed' => 1,
            'trigger_id' => 0,
            'duration' => 0,
            'author' => '',
            'comment' => '',
        ];
    }

    protected function _processArgs(): void
    {
        parent::_processArgs();

        if (isset($this->args['service']) && !$this->args['service']) {
            unset($this->args['service']);
            $this->action = 'SCHEDULE_HOST_DOWNTIME';
        }

        $this->args['start_time'] = time();
        $this->args['end_time'] = time() + ($this->args['duration'] ?? 0);
    }
}

class DisableNotificationsCommand extends LiveStatusCommand
{
    /**
     * DisableNotificationsCommand constructor.
     * @param array $args
     * @psalm-param array{author?: null|string, comment?: null|string, downtime_id?: null|string, duration?: int, end_time?: int, host?: null|string, notify?: null|int, persistent?: null|int, scope?: null|string, service?: null|string, start_time?: int, sticky?: null|int} $args
     */
    public function __construct(array $args = [])
    {
        parent::__construct($args);
        $this->action = 'DISABLE_SVC_NOTIFICATIONS';
        $this->required = [
            'host',
        ];

        // The 'scope' field helps define if we want to disable notifications
        // for all the host's services. Its only valid value is 'all' and it's
        // not required/used by any external Nagios commands.
        $this->fields = [
            'host' => '',
            'service' => '',
            'scope' => '',
        ];
    }

    protected function _processArgs(): void
    {
        parent::_processArgs();

        // Do we want to disable all services under the given host?
        if (isset($this->args['scope']) && $this->args['scope'] && 'all' === $this->args['scope']) {
            // Unset the 'service' arg if present; it's redundant in this context.
            unset($this->args['service']);
            $this->action = 'DISABLE_HOST_SVC_NOTIFICATIONS';
        } elseif (isset($this->args['service']) && !$this->args['service']) {
            unset($this->args['service']);
            $this->action = 'DISABLE_HOST_NOTIFICATIONS';
        }
    }
}

class EnableNotificationsCommand extends LiveStatusCommand
{
    /**
     * EnableNotificationsCommand constructor.
     * @param array $args
     * @psalm-param array{author?: null|string, comment?: null|string, downtime_id?: null|string, duration?: int, end_time?: int, host?: null|string, notify?: null|int, persistent?: null|int, scope?: null|string, service?: null|string, start_time?: int, sticky?: null|int} $args
     */
    public function __construct(array $args = [])
    {
        parent::__construct($args);
        $this->action = 'ENABLE_SVC_NOTIFICATIONS';
        $this->required = [
            'host',
        ];

        // The 'scope' field helps define if we want to enable notifications
        // for all the host's services. Its only valid value is 'all' and it's
        // not required/used by any external Nagios commands.
        $this->fields = [
            'host' => '',
            'service' => '',
            'scope' => '',
        ];
    }

    protected function _processArgs(): void
    {
        parent::_processArgs();

        // Do we want to enable all services under the given host?
        if (isset($this->args['scope']) && $this->args['scope'] && 'all' === $this->args['scope']) {
            // Unset the 'service' arg if present; it's redundant in this context.
            unset($this->args['service']);
            $this->action = 'ENABLE_HOST_SVC_NOTIFICATIONS';
        } elseif (isset($this->args['service']) && !$this->args['service']) {
            unset($this->args['service']);
            $this->action = 'ENABLE_HOST_NOTIFICATIONS';
        }
    }
}
