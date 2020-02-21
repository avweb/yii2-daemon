<?php

namespace tii\yii2daemon;

use Yii;
use yii\base\InvalidRouteException;

abstract class WatcherDaemonController extends DaemonController
{
    /**
     * @var array Список PID запущеных демонов
     */
    protected $running = [];


    protected function prepareJobs(array $jobs)
    {
        $result = [];
        foreach ($jobs as $key => $job) {

            // присваиваем имя
            $controllerName = $this->getControllerNameFromRoute($job['route']);
            if (!$controllerName) {
                throw new InvalidRouteException("Can't resolve path '" . $job['route'] . "'");
            }
            $job['name'] = $controllerName;

            // параметры
            if (empty($job['params'])) {
                $job['params'] = [];
            }
            elseif (is_string($job['params'])) {
                $paramsString = $job['params'];
                $job['params'] = [];
                $a = explode(';', $paramsString);
                foreach ($a as $params) {
                    $b = explode('=', $params);
                    $job['params'][$b[0]] = $b[1];
                }
            }

            if (isset($job['streams']) && $job['streams'] > 1) {
                // плодим многопоточные задания
                for ($i = 1; $i <= $job['streams']; $i++) {
                    $newJob = $job;
                    $newJob['name'] = $job['name'] . '_' . $i;
                    $newJob['params']['streamsCount'] = $job['streams'];
                    $newJob['params']['streamNo'] = $i;
                    $result[] = $newJob;
                }
            }
            else {
                $result[] = $job;
            }
        }
        return $result;
    }

    public function init()
    {
        parent::init();

        // проверка уникальности вотчера
        if (file_exists($this->getPidPath())) {
            $pid = file_get_contents($this->getPidPath());
            exec("ps -p $pid", $output);
            if (count($output) > 1) {
                $this->halt(static::EXIT_CODE_ERROR, 'Another Watcher is already running.');
            }
        }

        $this->on(static::EVENT_AFTER_FINISH_JOBS, [$this, 'afterFinishJobs']);
    }

    protected function doJob($job)
    {
        $this->log('trace', 'Check daemon ' . $job['name']);

        if (file_exists($this->getPidPath($job['name']))) {
            $pid = file_get_contents($this->getPidPath($job['name']));
            if (posix_getpgid($pid)) {
                if ($job['enabled']) {
                    $this->log('trace', 'Daemon ' . $job['name'] . ' running pid= '.$pid.' enabled='.$job['enabled'] );

                    return true;
                }
                else {
                    $this->log('trace',
                        'Daemon ' . $job['name'] . ' running, but disabled in config. Send SIGTERM signal. pid= '.$pid.' enabled='.$job['enabled']);
                    if (isset($job['hardKill']) && $job['hardKill']) {
                        posix_kill($pid, SIGKILL);
                    }
                    else {
                        posix_kill($pid, SIGTERM);
                    }

                    return true;
                }
            }
        }

        $this->log('trace', 'Daemon ' . $job['name'] . ' pid not found.');

        if ($job['enabled']) {
            $this->log('trace', 'Try to run daemon ' . $job['name'] . '.');
            Yii::getLogger()->flush(true);
            $this->renewConnections();
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->halt(static::EXIT_CODE_ERROR, 'pcntl_fork() returned error');
            }
            elseif ($pid === 0) {
                $command = $job['route'] . DIRECTORY_SEPARATOR . 'index';
                $defaultParams = ['demonize' => 1];
                $params = (isset($job['params'])) ? array_merge($defaultParams, $job['params']) : $defaultParams;
                Yii::$app->requestedRoute = $command;
                Yii::$app->runAction("$command", $params);
                $this->halt(static::EXIT_CODE_NORMAL);
            }
            else {
                $this->log('trace', 'Daemon ' . $job['name'] . ' is running with pid ' . $pid);
                $this->running[$job['name']][] = $pid;
                sleep(1); // чтобы успел создаться файл-маркер запущеного демона
            }
        }

        $this->log('trace', 'Daemon ' . $job['name'] . ' is checked.');

        return true;
    }

    protected function afterFinishJobs()
    {
        // таймаут между проверками демонов
        sleep($this->sleep);
    }

    /**
     * Получает название контроллера из пути
     * @return string Название клнтроллера
     */
    protected function getControllerNameFromRoute($route)
    {

        $pos = strrpos($route, '/');
        if ($pos === false) {
            $className = $route;
        }
        else {
            $className = substr($route, $pos + 1);
        }

        if (!preg_match('%^[a-z][a-z0-9\\-_]*$%', $className)) {
            return null;
        }

        $className = str_replace(' ', '', ucwords(str_replace('-', ' ', $className)));

        return $className;
    }
}
