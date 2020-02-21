<?php

namespace tii\yii2daemon;

use Yii;
use yii\base\NotSupportedException;
use yii\console\Controller;
use yii\helpers\Console;

abstract class DaemonController extends Controller
{
    /**
     * @event Событие перед выполнением задания
     */
    const EVENT_BEFORE_JOB = 'beforeJob';
    /**
     * @event Событие после выполнения задания
     */
    const EVENT_AFTER_JOB = 'afterJob';
    /**
     * @event Событие перед полученим задания
     */
    const EVENT_BEFORE_ITERATION = 'beforeIteration';
    /**
     * @event Событие после обработки всех заданий
     */
    const EVENT_AFTER_ITERATION = 'afterIteration';
    /**
     * @event Событие после обработки всех заданий (только если были задания)
     */
    const EVENT_AFTER_FINISH_JOBS = 'afterFinishJobs';


    /**
     * @var int Задержка между проверками новых заданий (в случеа отсутствия заданий)
     * @default 10 sec
     */
    public $sleep = 10;
    /**
     * @var boolean Запустить контроллер как демон
     * @default false
     */
    public $demonize = false;
    /**
     * @var boolean Разрешить контроллеру создавать дополнительные экземпляры процессов
     * @see $maxChildProcesses
     * @default false
     */
    public $isMultiInstance = false;
    /**
     * @var boolean Если true - отдельный процесс выделяется для обработки одного задания от родительского процесса, если false - создаются полностью идентичные процессы получающие и обрабатывающие задания
     * @default false
     */
    public $multiInstanceForJob = false;
    /**
     * @var int Максимальное количество дополнительных процессов
     * @default 3
     */
    public $maxChildProcesses = 3;
    /**
     * @var array Список PID дочерних процессов
     */
    protected $childProcesses;
    /**
     * @var int Лимит памяти в байтах (должен быть меньше PHP memory_limit)
     * @default 33554432 bytes = 32M
     */
    protected $memoryLimit = 33554432;
    /**
     * @var bool Флаг остановки работы процесса
     */
    protected $stopFlag = false;
    /**
     * @var string Путь к каталогу для хранения файла-маркера с PID
     */
    protected $pidDir = '@runtime/daemons/pids';
    /**
     * @var string Путь к каталогу для хранения логов
     */
    protected $logDir = '@runtime/daemons/logs';
    protected $connections = [];
    /**
     * @var string Название процесса
     */
    protected $processName;
    protected $fileTargetClassName = '\yii\log\FileTarget';


    /**
     * Получает задания на обрадотку
     * @return array Список заданий
     */
    abstract protected function defineJobs();

    /**
     * Обработка задания
     * @param $job
     * @return boolean True в случае успешного выполнения
     */
    abstract protected function doJob($job);

    /**
     * Логирует сообщение
     * @param string $level Уровень сообщения - error, warning, info, trace, profile, profile begin, profile end
     * @param string $message Сообщение
     * @param string $category Категория
     */
    public function log($level, $message, $category = 'application')
    {

        $loger = Yii::getLogger();

        switch ($level) {
            case 'error':
                $logLevel = $loger::LEVEL_ERROR;
                break;
            case 'warning':
                $logLevel = $loger::LEVEL_WARNING;
                break;
            case 'info':
                $logLevel = $loger::LEVEL_INFO;
                break;
            case 'trace':
                $logLevel = $loger::LEVEL_TRACE;
                break;
            case 'profile':
                $logLevel = $loger::LEVEL_PROFILE;
                break;
            case 'profile begin':
                $logLevel = $loger::LEVEL_PROFILE_BEGIN;
                break;
            case 'profile end':
                $logLevel = $loger::LEVEL_PROFILE_END;
                break;
            default:
                $logLevel = 'unknown';
        }

        Yii::getLogger()->log($message, $logLevel, $category);
    }

    public function init()
    {
        parent::init();

        pcntl_signal(SIGTERM, [$this, 'signalHandler']);
        pcntl_signal(SIGHUP, [$this, 'signalHandler']);
        pcntl_signal(SIGUSR1, [$this, 'signalHandler']);
        pcntl_signal(SIGCHLD, [$this, 'signalHandler']);
    }

    public function options($actionID)
    {
        return [
            'demonize',
            'taskLimit',
            'isMultiInstance',
            'maxChildProcesses',
            'multiInstanceForJob',
        ];
    }

    public function beforeAction($action)
    {
        $this->initLogger(); // не переносить в init()
        // запрещаем всё кроме index
        if (parent::beforeAction($action)) {
            if ($action->id !== 'index') {
                throw new NotSupportedException("Only index action allowed in daemons. So, don't create and call another");
            }
        }

        // отвязываем от консоли
        if ($this->demonize) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->halt(static::EXIT_CODE_ERROR, 'pcntl_fork() rise error');
            }
            elseif ($pid) {
                $this->halt(static::EXIT_CODE_NORMAL);
            }
            else {
                posix_setsid();
                if (is_resource(STDIN)) {
                    fclose(STDIN);
                    $stdIn = fopen('/dev/null', 'rb');
                }
                if (is_resource(STDOUT)) {
                    fclose(STDOUT);
                    $stdOut = fopen('/dev/null', 'ab');
                }
                if (is_resource(STDERR)) {
                    fclose(STDERR);
                    $stdErr = fopen('/dev/null', 'ab');
                }
            }
        }

        // переименовываем процесс
        if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
            cli_set_process_title($this->getProcessNameFull());
        }
        else {
            if (!function_exists('setproctitle')) {
                throw new NotSupportedException("Can't find cli_set_process_title or setproctitle function");
            }
            setproctitle($this->getProcessNameFull());
        }

        return true;
    }

    /**
     * Предварительная обработка полученных заданий
     * @param $jobs array Список заданий
     * @return array Обработанный список заданий
     */
    protected function prepareJobs(array $jobs)
    {
        return $jobs;
    }

    /**
     * @return int 0|1 Код выхода
     */
    public function actionIndex()
    {

        if (!file_put_contents($this->getPidPath(), getmypid())) {
            $this->halt(static::EXIT_CODE_ERROR, 'Can\'t create pid file ' . $this->getPidPath());
        }

        $this->log('trace', 'Pid ' . getmypid() . ' started.');

        while (!$this->stopFlag) {

            pcntl_signal_dispatch();

            $this->trigger(static::EVENT_BEFORE_ITERATION);

            $jobs = $this->prepareJobs($this->defineJobs());

            if (empty($jobs)) {
                $this->ifNoJobs();
            }
            else {

                while (($job = array_shift($jobs)) !== null) {

                    pcntl_signal_dispatch();
                    if ($this->stopFlag) {
                        break;
                    }

                    // форкаем задание
                    if ($this->isMultiInstance && $this->multiInstanceForJob) {

                        if (count($this->childProcesses) < $this->maxChildProcesses) {

                            $this->log('trace',
                                'Free workers found: ' . ($this->maxChildProcesses - count($this->childProcesses)) . ' worker(s). Delegate tasks.');

                            $this->renewConnections();
                            $pid = pcntl_fork();
                            if ($pid === -1) {
                                $this->halt(static::EXIT_CODE_ERROR, 'pcntl_fork() returned error');
                            }
                            elseif ($pid) {
                                $this->childProcesses[$pid] = true;
                            }
                            else {
                                $this->trigger(static::EVENT_BEFORE_JOB);
                                if ($this->doJob($job)) {
                                    $this->trigger(static::EVENT_AFTER_JOB);
                                    $this->halt(static::EXIT_CODE_NORMAL);
                                }
                                else {
                                    $this->trigger(static::EVENT_AFTER_JOB);
                                    $this->halt(static::EXIT_CODE_ERROR,
                                        'Child process #' . $pid . ' return error.');
                                }
                            }
                        }
                        else {
                            $this->log('trace', 'Reached maximum number of child processes. Waiting...');
                            sleep(1);
                        }

                        // проверяем, умер ли один из дочерних процессов
                        while ($signaled_pid = pcntl_waitpid(-1, $status, WNOHANG)) {
                            if ($signaled_pid === -1) {
                                // дочерних процессов
                                $this->childProcesses = [];
                                break;
                            }
                            else {
                                unset($this->childProcesses[$signaled_pid]);
                            }
                        }
                    }
                    else {
                        $this->trigger(static::EVENT_BEFORE_JOB);
                        $jobResult = $this->doJob($job);
                        $this->trigger(static::EVENT_AFTER_JOB);
                    }
                }

                $this->trigger(static::EVENT_AFTER_FINISH_JOBS);
            }

            $this->trigger(static::EVENT_AFTER_ITERATION);

            if (memory_get_usage() > $this->memoryLimit) {
                $this->log('trace',
                    'Pid ' . getmypid() . ' exceeded memory limit. Used  ' . memory_get_usage() . ' bytes on ' . $this->memoryLimit . ' bytes allowed.');
                $this->stop();
            }
        }

        if (memory_get_usage() < $this->memoryLimit) {
            $this->log('trace',
                'Pid ' . getmypid() . ' used ' . memory_get_usage() . ' bytes on ' . $this->memoryLimit . ' bytes allowed by memory limit.');
        }

        $this->log('trace', 'Pid ' . getmypid() . ' is stopped.');

        if (file_exists($this->getPidPath())) {
            @unlink($this->getPidPath());
        }
        else {
            $this->log('error', 'Can\'t unlink pid file ' . $this->getPidPath());
        }

        return static::EXIT_CODE_NORMAL;
    }

    protected function ifNoJobs()
    {
        $this->sleep($this->sleep);
    }

    /**
     * Обработчик сигналов PCNTL
     * @param int $signo
     * @param null $pid
     * @param null $status
     */
    protected function signalHandler($signo, $pid = null, $status = null)
    {

        $this->log('trace', 'Signal ' . $signo . ' received.');

        switch ($signo) {
            case SIGTERM:
                //shutdown
                $this->stop();
                break;
            case SIGHUP:
                //restart, not implemented
                break;
            case SIGUSR1:
                //user signal, not implemented
                break;
            case SIGCHLD:
                while( pcntl_waitpid(-1, $status, WNOHANG) > 0 ){
                }
                break;
        }
    }

    /**
     * Добавляет логирование для процесса
     */
    protected function initLogger()
    {

        $targets = Yii::$app->getLog()->targets;
        /*
          foreach ($targets as &$target) {
          $target->enabled = false;
          }
         */

        $config = [
            'enabled' => true,
            'exportInterval' => 1, // по умолчанию 1000
            'logVars' => [], // по умолчанию ['_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_SERVER']
            'levels' => ['error', 'warning'],
            'logFile' => Yii::getAlias($this->logDir) . DIRECTORY_SEPARATOR . $this->getProcessName() . '.log',
            'maxLogFiles' => 1, // количество фалов лога. По-умолчанию 5
        ];
        $targets['daemon'] = new $this->fileTargetClassName($config);

        // для trace и info исключаем yii\db\*
        $config = [
            'enabled' => true,
            'exportInterval' => 1, // по умолчанию 1000
            'logVars' => [], // по умолчанию ['_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_SERVER']
            'levels' => ['trace', 'info'],
            'logFile' => Yii::getAlias($this->logDir) . DIRECTORY_SEPARATOR . $this->getProcessName() . '.log',
            'except' => ['yii\db\*'], // Don't include messages from db
            'maxLogFiles' => 1, // количество фалов лога. По-умолчанию 5
        ];
        $targets['daemon_trace'] = new $this->fileTargetClassName($config);

        Yii::$app->getLog()->targets = $targets;
        Yii::$app->getLog()->init();
    }

    /**
     * Возвращает название текущего процесса
     * @return string Название текущего процесса
     */
    protected function getProcessName()
    {
        if (empty($this->processName)) {
            $this->processName = $this->getControllerName();
        }
        return $this->processName;
    }

    /**
     * Возвращает название текущего процесса с принадлежностью проекту
     * @return string Название текущего процесса
     */
    protected function getProcessNameFull()
    {
        return Yii::$app->params['projectName'] . '/' . $this->getProcessName();
    }

    protected function getControllerName()
    {
        $classname = static::className();
        if (preg_match('@\\\\([\w]+)$@', $classname, $matches)) {
            $classname = $matches[1];
        }
        $classname = substr($classname, 0, -10); // обрезаем Controller
        return $classname;
    }

    /**
     * Возвращает путь к файлу-маркеру с PID процесса
     * @param string $processName [optional] Название процесса. По-умолчанию текущий процес
     * @return string Путь к файлу-маркеру с PID процесса
     */
    protected function getPidPath($processName = null)
    {
        if (empty($processName)) {
            $processName = $this->getProcessName();
        }
        $dir = Yii::getAlias($this->pidDir);
        if (!file_exists($dir)) {
            mkdir($dir, 0744, true);
        }
        return $dir . DIRECTORY_SEPARATOR . $processName;
    }

    /**
     * Сбрасывает текущие соединения с БД
     */
    protected function renewConnections()
    {
        $this->processName = null;
        foreach ($this->connections as $connection) {
            $connection->close();
        }
    }

    /**
     * Выводит сообщение в консоль
     * @param string $message Сообщение
     */
    protected function writeConsole($message)
    {
        $out = Console::ansiFormat('[' . date('d.m.Y H:i:s') . '] ', [Console::BOLD]);
        $this->stdout($out . $message . "\n");
    }

    /**
     * Помечает процесс на завершение
     */
    protected function stop()
    {
        $this->stopFlag = true;
    }

    /**
     * Завершает процесс и записывает или выводит сообщение
     * @param int $code Код завершения
     * @param string $message Сообщение
     */
    protected function halt($code, $message = null)
    {
        if ($message !== null) {
            if ($code == static::EXIT_CODE_ERROR) {
                $this->log('error', $message);
                if (!$this->demonize) {
                    $message = Console::ansiFormat($message, [Console::FG_RED]);
                }
            }
            else {
                $this->log('trace', $message);
            }
            if (!$this->demonize) {
                $this->writeConsole($message);
            }
        }
        exit($code);
    }

    /**
     * Ставит демон в режим ожидания
     * @param $seconds int Время в секундах
     */
    protected function sleep($seconds)
    {
        $sleepEnd = microtime(true) + $seconds;
        while (microtime(true) < $sleepEnd && !$this->stopFlag) {
            sleep(1);
            pcntl_signal_dispatch();
        }
    }
}
