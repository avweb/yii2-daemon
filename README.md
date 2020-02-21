Пакет реализующий демоны для Yii2
============================

Для работы необходим `pcntl` и `posix`, но они не указаны в require для возможности установки пакета из Windows

В `Yii::$app->params` необходимо добавить свойство `projectName`

WatcherDaemonController контролирует осталье демоны
defineJobs() должен возвращать массив вида:
[
    'route' => 'daemons/my-daemon',
    'enabled' => 1,
    'params' => [
        'param1' => 1,
        'param2' => 'param2',
        ...
    ],
    'streams' => 5,
]

    `route`     путь к контроллеру демона
    `enabled`   активность демона 0/1
    `params`    [не обязательный] содержит параметры и значения которые будут переданы в виде пареметров контроллеру
    `streams`   [не обязательный] количество потоков на которые будет размножен демон. каждому экземпляру контроллера будут переданы параметры: `streamsCount` - количество потоков, `streamNo` - номер текущего потока

Для управления демонами из базы можно использовать таблицу:

PostgeSQL
CREATE SEQUENCE daemons_id_seq
  INCREMENT 1
  MINVALUE 1
  MAXVALUE 9223372036854775807
  START 1
  CACHE 1;
CREATE TABLE "daemons" (
	"id" INTEGER NOT NULL DEFAULT nextval('daemons_id_seq'::regclass),
	"route" VARCHAR(50) NOT NULL,
	"enabled" SMALLINT NOT NULL,
	"streams" SMALLINT NOT NULL,
	"params" VARCHAR(250) NOT NULL
);

params - значения в формате "paкam1=value1;param2=value2"
