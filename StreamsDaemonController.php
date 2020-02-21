<?php

namespace tii\yii2daemon;

abstract class StreamsDaemonController extends DaemonController
{
    public $streamsCount = 1;
    public $streamNo = 1;
    public $streamCapacity = 100;


    public function options($actionID)
    {

        $options = ['streamsCount', 'streamNo', 'streamCapacity'];

        return array_merge($options, parent::options($actionID));
    }

    protected function getProcessName()
    {
        if (empty($this->processName)) {
            $this->processName = $this->getControllerName();
            if ($this->streamsCount > 1) {
                $this->processName .= '_' . $this->streamNo;
            }
        }
        return $this->processName;
    }
}
